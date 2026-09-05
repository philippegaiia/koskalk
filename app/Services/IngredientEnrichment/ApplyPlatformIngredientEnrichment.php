<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientTranslationWriteIntent;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentReplaceField;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientFunctionAssignmentService;
use App\Services\IngredientMarketLabelService;
use App\Services\IngredientTranslationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyPlatformIngredientEnrichment
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
        private readonly IngredientDataEntryService $dataEntryService,
        private readonly IngredientFunctionAssignmentService $functionAssignments,
        private readonly IngredientMarketLabelService $marketLabelService,
        private readonly IngredientTranslationService $translationService,
        private readonly IngredientEnrichmentEvidenceReconciler $evidenceReconciler,
    ) {}

    /**
     * Apply one validated plan in its own transaction.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     * @param  list<string>  $replaceFields
     * @return array{status:string, ingredient:Ingredient}
     */
    public function apply(array $plan, array $result, array $replaceFields = []): array
    {
        return DB::transaction(
            fn (): array => $this->applyWithinTransaction($plan, $result, $replaceFields),
            attempts: 5,
        );
    }

    /**
     * Apply a plan while the caller owns the surrounding per-item transaction.
     * Intake promotion uses this boundary so ingredient creation, relationships,
     * and intake audit links commit or roll back together.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     * @param  list<string>  $replaceFields
     * @return array{status:string, ingredient:Ingredient}
     */
    public function applyWithinTransaction(
        array $plan,
        array $result,
        array $replaceFields = [],
        bool $promotion = false,
        ?int $reviewerId = null,
        ?CarbonImmutable $reviewedAt = null,
    ): array {
        $ingredientId = $plan['ingredient_id'] ?? null;
        if (! is_numeric($ingredientId) || (int) $ingredientId < 1) {
            throw ValidationException::withMessages([
                'ingredient_id' => __('ingredient_enrichment.validation.catalogue_target_required'),
            ]);
        }

        $ingredient = Ingredient::withoutGlobalScopes()
            ->lockForUpdate()
            ->findOrFail((int) $ingredientId);

        return $this->applyLocked($ingredient, $plan, $result, $replaceFields, $promotion, $reviewerId, $reviewedAt);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     * @param  list<string>  $replaceFields
     * @return array{status:string, ingredient:Ingredient}
     */
    private function applyLocked(
        Ingredient $ingredient,
        array $plan,
        array $result,
        array $replaceFields,
        bool $promotion,
        ?int $reviewerId,
        ?CarbonImmutable $reviewedAt,
    ): array {
        if ($ingredient->owner_type !== null || $ingredient->owner_id !== null) {
            throw ValidationException::withMessages([
                'catalog_key' => __('ingredient_enrichment.validation.platform_only_apply'),
            ]);
        }

        $currentFingerprint = $this->snapshotBuilder->fingerprint($ingredient);
        $sourceFingerprint = (string) ($result['source_fingerprint'] ?? '');
        $storedSourceFingerprint = data_get($ingredient->source_data, 'enrichment.core.source_fingerprint');
        $storedResultFingerprint = data_get($ingredient->source_data, 'enrichment.core.result_fingerprint');
        $hasApplicableChanges = $this->hasApplicableChanges($plan);

        if (! $promotion
            && ! $hasApplicableChanges
            && $sourceFingerprint === $storedSourceFingerprint
            && $currentFingerprint === $storedResultFingerprint) {
            return ['status' => 'unchanged', 'ingredient' => $ingredient];
        }

        if (! $promotion && $sourceFingerprint !== $currentFingerprint) {
            throw ValidationException::withMessages([
                'source_fingerprint' => __('ingredient_enrichment.validation.stale_apply'),
            ]);
        }

        if (! $hasApplicableChanges) {
            return ['status' => 'unchanged', 'ingredient' => $ingredient];
        }

        $reviewedAt ??= CarbonImmutable::now();

        $effective = is_array($plan['effective'] ?? null) ? $plan['effective'] : [];
        $canonical = is_array($effective['canonical'] ?? null) ? $effective['canonical'] : [];
        $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
        $this->syncCanonicalAndIdentity(
            $ingredient,
            $canonical,
            $effective['identifiers'] ?? [],
            $effective['aliases'] ?? [],
            $result['evidence'] ?? [],
            $proposal['identifiers'] ?? [],
        );

        $taxonomyChanged = ($ingredient->category?->value ?? null) !== ($canonical['category'] ?? null)
            || ($ingredient->subcategory?->value ?? null) !== ($canonical['subcategory'] ?? null);
        $ingredient->category = IngredientCategory::tryFrom((string) ($canonical['category'] ?? ''));
        $ingredient->subcategory = ($canonical['subcategory'] ?? null) === null
            ? null
            : IngredientSubcategory::tryFrom((string) $canonical['subcategory']);
        $ingredient->info_markdown = $canonical['info_markdown'] ?? null;
        $ingredient->requires_admin_review = true;
        if ($taxonomyChanged) {
            $ingredient->taxonomy_source = $promotion ? 'admin_reviewed_enrichment' : 'external_research_pending_review';
            $ingredient->taxonomy_reviewed_at = $promotion ? $reviewedAt : null;
            $ingredient->taxonomy_reviewed_by_user_id = $promotion ? $reviewerId : null;
        }
        $ingredient->save();
        $this->applyIdentityNameTranslations(
            $ingredient,
            $effective['translations'] ?? [],
            $proposal['translations'] ?? [],
        );

        $cosingRows = $this->cosingRows($proposal['cosing_functions'] ?? []);
        if (in_array(IngredientEnrichmentReplaceField::CosingFunctions->value, $replaceFields, true)) {
            $this->functionAssignments->replaceCosIng($ingredient, $cosingRows);
        } else {
            $this->functionAssignments->mergeCosIng($ingredient, $cosingRows);
        }

        $marketRows = is_array($proposal['market_labels'] ?? null) ? $proposal['market_labels'] : [];
        if ($marketRows !== []) {
            if (in_array(IngredientEnrichmentReplaceField::MarketLabels->value, $replaceFields, true)) {
                $this->marketLabelService->replaceImported($ingredient, $marketRows);
            } else {
                $this->marketLabelService->mergeImported($ingredient, $marketRows);
            }
        }

        $ingredient = Ingredient::withoutGlobalScopes()->findOrFail($ingredient->id);
        $resultFingerprint = $this->snapshotBuilder->fingerprint($ingredient);
        $sourceData = is_array($ingredient->source_data) ? $ingredient->source_data : [];
        data_set($sourceData, 'enrichment.core', [
            'schema_version' => (int) ($result['schema_version'] ?? config('ingredient-enrichment.schema_version')),
            'confidence' => $result['confidence'] ?? null,
            'field_confidence' => is_array($result['field_confidence'] ?? null)
                ? $result['field_confidence']
                : [],
            'value_provenance' => is_array($result['value_provenance'] ?? null)
                ? $result['value_provenance']
                : [],
            'warnings' => $result['warnings'] ?? [],
            'unresolved_questions' => $result['unresolved_questions'] ?? [],
            'source_fingerprint' => $sourceFingerprint,
            'result_fingerprint' => $resultFingerprint,
            'applied_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
        if (filled(data_get($result, 'proposal.info_markdown'))) {
            $guidanceEvidence = is_array($result['guidance_evidence'] ?? null)
                ? $result['guidance_evidence']
                : [];
            $guidanceMetadata = [
                'evidence' => $guidanceEvidence,
                'research_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_research.prompt_version'),
                'guidance_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_prompt_version'),
                'approved_at' => CarbonImmutable::now()->toIso8601String(),
            ];
            $localizationPromptVersion = data_get($sourceData, 'enrichment.guidance.localization_prompt_version');
            if (is_string($localizationPromptVersion) && trim($localizationPromptVersion) !== '') {
                $guidanceMetadata['localization_prompt_version'] = $localizationPromptVersion;
            }
            data_set($sourceData, 'enrichment.guidance', $guidanceMetadata);
        }
        $ingredient->source_data = $sourceData;
        $ingredient->requires_admin_review = $promotion ? false : true;
        if ($promotion) {
            $ingredient->taxonomy_source = 'admin_reviewed_enrichment';
            $ingredient->taxonomy_reviewed_at = $reviewedAt;
            $ingredient->taxonomy_reviewed_by_user_id = $reviewerId;
            $ingredient->is_active = true;
        }
        $ingredient->save();

        return ['status' => 'applied', 'ingredient' => $ingredient->fresh()];
    }

    /** @param array<string, mixed> $plan */
    private function hasApplicableChanges(array $plan): bool
    {
        $decisions = collect(is_array($plan['decisions'] ?? null) ? $plan['decisions'] : []);
        if ($decisions->isEmpty()) {
            return ($plan['changed'] ?? false) === true;
        }

        if ($decisions->contains(
            fn (mixed $decision): bool => is_array($decision)
                && is_string($decision['field'] ?? null)
                && ! str_starts_with($decision['field'], 'proposal.translations')
                && in_array($decision['decision'] ?? null, ['new', 'replace'], true),
        )) {
            return true;
        }

        return $decisions->contains(
            fn (mixed $decision): bool => is_array($decision)
                && preg_match('/^proposal\.translations\.[^.]+\.(display_name|saponification_name)$/', (string) ($decision['field'] ?? '')) === 1
                && in_array($decision['decision'] ?? null, ['new', 'replace'], true),
        );
    }

    private function applyIdentityNameTranslations(Ingredient $ingredient, mixed $effectiveRows, mixed $proposalRows): void
    {
        if (! is_array($effectiveRows) || ! is_array($proposalRows) || $proposalRows === []) {
            return;
        }

        $proposedLocales = collect($proposalRows)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['locale'] ?? null))
            ->map(fn (array $row): string => trim($row['locale']))
            ->filter()
            ->all();

        $rows = $ingredient->translations()
            ->get(['locale', 'display_name', 'saponification_name', 'info_markdown', 'source_fingerprint', 'origin', 'prompt_version'])
            ->mapWithKeys(fn ($translation): array => [$translation->locale => [
                'locale' => $translation->locale,
                'display_name' => $translation->display_name,
                'saponification_name' => $translation->saponification_name,
                'info_markdown' => $translation->info_markdown,
                'source_fingerprint' => $translation->source_fingerprint,
                'origin' => $translation->origin,
                'prompt_version' => $translation->prompt_version,
            ]]);
        $promptVersion = (string) config('ingredient-enrichment.openai.identity_name_localization_prompt_version');
        $writeIntents = [];
        $preservedGuidanceMetadata = [];

        collect($effectiveRows)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['locale'] ?? null))
            ->filter(fn (array $row): bool => in_array(trim($row['locale']), $proposedLocales, true))
            ->each(function (array $row) use ($rows, &$preservedGuidanceMetadata, &$writeIntents, $promptVersion): void {
                $locale = trim($row['locale']);
                $current = $rows->get($locale);
                if (($current['origin'] ?? null) === IngredientTranslationOrigin::ReviewerEdited) {
                    return;
                }

                $rows->put($locale, [
                    'locale' => $locale,
                    'display_name' => $row['display_name'] ?? null,
                    'saponification_name' => $row['saponification_name'] ?? null,
                    'info_markdown' => $current['info_markdown'] ?? null,
                    'origin' => IngredientTranslationOrigin::AiGenerated,
                    'source_fingerprint' => $current['source_fingerprint'] ?? null,
                    'prompt_version' => $current['prompt_version'] ?? null,
                ]);
                if (filled($current['info_markdown'] ?? null)) {
                    $preservedGuidanceMetadata[$locale] = [
                        'source_fingerprint' => $current['source_fingerprint'] ?? null,
                        'prompt_version' => $current['prompt_version'] ?? null,
                    ];
                }
                $writeIntents[$locale] = new IngredientTranslationWriteIntent(
                    IngredientTranslationOrigin::AiGenerated,
                    filled($current['info_markdown'] ?? null)
                        ? ($current['prompt_version'] ?? null)
                        : $promptVersion,
                    true,
                );
            });

        $this->translationService->sync(
            $ingredient,
            $rows->map(fn (array $row): array => collect($row)->only([
                'locale', 'display_name', 'saponification_name', 'info_markdown',
            ])->all())->values()->all(),
            IngredientTranslationOrigin::AiGenerated,
            $promptVersion,
            $writeIntents,
        );

        foreach ($preservedGuidanceMetadata as $locale => $metadata) {
            $ingredient->translations()->where('locale', $locale)->update($metadata);
        }
    }

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function syncCanonicalAndIdentity(
        Ingredient $ingredient,
        array $canonical,
        mixed $identifierRows,
        mixed $aliasRows,
        mixed $evidenceRows,
        mixed $proposalIdentifierRows,
    ): void {
        $formState = $this->dataEntryService->formData($ingredient);
        $identifiers = is_array($identifierRows) ? $identifierRows : [];
        $acceptedEvidence = is_array($evidenceRows) ? $evidenceRows : [];
        $proposalIdentifiers = collect(is_array($proposalIdentifierRows) ? $proposalIdentifierRows : [])
            ->values();
        $cas = collect($identifiers)->first(fn (array $row): bool => ($row['scheme'] ?? null) === 'cas' && ($row['is_primary'] ?? false));
        $ec = collect($identifiers)->first(fn (array $row): bool => ($row['scheme'] ?? null) === 'ec' && ($row['is_primary'] ?? false));

        $formState['current_version'] = [
            ...$formState['current_version'],
            'display_name' => $canonical['display_name'] ?? null,
            'inci_name' => $canonical['inci_name'] ?? null,
            'saponification_name' => $canonical['saponification_name'] ?? null,
            'soap_inci_naoh_name' => $canonical['soap_inci_naoh_name'] ?? null,
            'soap_inci_koh_name' => $canonical['soap_inci_koh_name'] ?? null,
        ];
        $formState['cas_number'] = $cas['value'] ?? null;
        $formState['ec_number'] = $ec['value'] ?? null;
        $formState['additional_identifiers'] = collect($identifiers)
            ->reject(fn (array $row): bool => in_array($row['scheme'] ?? null, ['cas', 'ec'], true)
                && ($row['is_primary'] ?? false) === true)
            ->map(fn (array $row): array => [
                'scheme' => $row['scheme'] ?? null,
                'value' => $row['value'] ?? null,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ])
            ->values()
            ->all();

        $this->dataEntryService->syncCurrentData($ingredient, [
            'current_version' => $formState['current_version'],
            'cas_number' => $formState['cas_number'],
            'ec_number' => $formState['ec_number'],
            'additional_identifiers' => $formState['additional_identifiers'],
            'identifier_evidence' => $this->evidenceReconciler->projectIdentifierEvidence(
                $identifiers,
                $proposalIdentifiers->all(),
                $acceptedEvidence,
            ),
            'aliases' => collect(is_array($aliasRows) ? $aliasRows : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(fn (array $row): array => [
                    'locale' => $row['locale'] ?? null,
                    'name' => $row['name'] ?? null,
                    'kind' => $row['kind'] ?? null,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return list<array{key:string, source_reference:string, source_checked_at:CarbonImmutable}>
     */
    private function cosingRows(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'source_reference' => (string) ($row['source_url'] ?? ''),
                'source_checked_at' => CarbonImmutable::parse((string) ($row['retrieved_at'] ?? now()->toIso8601String())),
                'source_tier' => $row['source_tier'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'source_version' => $row['source_version'] ?? null,
                'source_updated_at' => $row['source_updated_at'] ?? null,
            ])
            ->values()
            ->all();
    }
}
