<?php

namespace App\Services\IngredientEnrichment;

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
        private readonly IngredientTranslationService $translationService,
        private readonly IngredientMarketLabelService $marketLabelService,
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

        if (! $promotion
            && ($plan['changed'] ?? false) !== true
            && $sourceFingerprint === $storedSourceFingerprint
            && $currentFingerprint === $storedResultFingerprint) {
            return ['status' => 'unchanged', 'ingredient' => $ingredient];
        }

        if (! $promotion && $sourceFingerprint !== $currentFingerprint) {
            throw ValidationException::withMessages([
                'source_fingerprint' => __('ingredient_enrichment.validation.stale_apply'),
            ]);
        }

        if (($plan['changed'] ?? false) !== true) {
            return ['status' => 'unchanged', 'ingredient' => $ingredient];
        }

        $reviewedAt ??= CarbonImmutable::now();

        $effective = is_array($plan['effective'] ?? null) ? $plan['effective'] : [];
        $canonical = is_array($effective['canonical'] ?? null) ? $effective['canonical'] : [];
        $this->syncCanonicalAndIdentity(
            $ingredient,
            $canonical,
            $effective['identifiers'] ?? [],
            $effective['aliases'] ?? [],
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

        $proposal = is_array($result['proposal'] ?? null) ? $result['proposal'] : [];
        $cosingRows = $this->cosingRows($proposal['cosing_functions'] ?? []);
        if (in_array(IngredientEnrichmentReplaceField::CosingFunctions->value, $replaceFields, true)) {
            $this->functionAssignments->replaceCosIng($ingredient, $cosingRows);
        } else {
            $this->functionAssignments->mergeCosIng($ingredient, $cosingRows);
        }

        $translationRows = is_array($effective['translations'] ?? null) ? $effective['translations'] : [];
        $this->translationService->sync(
            $ingredient,
            $translationRows,
            IngredientTranslationOrigin::AiGenerated,
            (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
        );

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
            'warnings' => $result['warnings'] ?? [],
            'unresolved_questions' => $result['unresolved_questions'] ?? [],
            'source_fingerprint' => $sourceFingerprint,
            'result_fingerprint' => $resultFingerprint,
            'applied_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
        $guidanceEvidence = is_array($result['guidance_evidence'] ?? null)
            ? $result['guidance_evidence']
            : [];
        data_set($sourceData, 'enrichment.guidance', [
            'evidence' => $guidanceEvidence,
            'research_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_research.prompt_version'),
            'guidance_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_prompt_version'),
            'localization_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
            'approved_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
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

    /**
     * @param  array<string, mixed>  $canonical
     */
    private function syncCanonicalAndIdentity(
        Ingredient $ingredient,
        array $canonical,
        mixed $identifierRows,
        mixed $aliasRows,
    ): void {
        $formState = $this->dataEntryService->formData($ingredient);
        $identifiers = is_array($identifierRows) ? $identifierRows : [];
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
            'identifier_evidence' => collect($identifiers)
                ->filter(fn (array $row): bool => is_string($row['source_url'] ?? null)
                    && is_string($row['source_name'] ?? null))
                ->map(fn (array $row): array => [
                    'scheme' => (string) ($row['scheme'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                    'evidence' => [[
                        'source_name' => $row['source_name'] ?? null,
                        'source_url' => $row['source_url'] ?? null,
                        'source_tier' => $row['source_tier'] ?? null,
                        'confidence' => $row['confidence'] ?? null,
                        'source_version' => $row['source_version'] ?? null,
                        'source_updated_at' => $row['source_updated_at'] ?? null,
                        'retrieved_at' => $row['retrieved_at'] ?? null,
                    ]],
                ])->all(),
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
