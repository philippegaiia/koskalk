<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IngredientGuidanceProposalReviewService
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientGuidanceChangePlanner $planner,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    /**
     * @param  array<string, mixed>  $proposal
     */
    public function edit(
        User $actor,
        IngredientEnrichmentBatchItem $item,
        array $proposal,
    ): IngredientEnrichmentBatchItem {
        $outcome = DB::transaction(function () use ($actor, $item, $proposal): array {
            $locked = IngredientEnrichmentBatchItem::query()
                ->lockForUpdate()
                ->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.not_editable'),
                ]);
            }

            $batch = $locked->batch()->firstOrFail();
            $mode = $batch->mode;
            $ingredient = Ingredient::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($locked->ingredient_id);
            if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_enrichment_admin.validation.guidance_batch_mode'),
                ]);
            }

            $this->assertAllowed($proposal, $mode);

            if ($this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }

            $current = is_array($locked->result) ? $locked->result : [];
            if ($mode->isLocalizationOnly()
                && array_key_exists('info_markdown', $proposal)
                && trim((string) $proposal['info_markdown']) !== trim((string) ($current['info_markdown'] ?? ''))) {
                throw ValidationException::withMessages([
                    'proposal.info_markdown' => __('ingredient_enrichment_admin.validation.guidance_english_edit_forbidden'),
                ]);
            }

            $candidate = $current;
            if (array_key_exists('info_markdown', $proposal)) {
                $candidate['info_markdown'] = $proposal['info_markdown'];
            }
            if (array_key_exists('translations', $proposal)) {
                $currentTranslations = collect($current['translations'] ?? [])->keyBy('locale');
                $proposalTranslations = collect($proposal['translations'])->keyBy('locale');
                $candidate['translations'] = $currentTranslations
                    ->map(function (array $translation, string $locale) use ($proposalTranslations): array {
                        $proposalTranslation = $proposalTranslations->get($locale);

                        return [
                            ...$translation,
                            ...(is_array($proposalTranslation) ? $proposalTranslation : []),
                        ];
                    })
                    ->union($proposalTranslations)
                    ->values()
                    ->all();
            }

            $expectedLocales = $this->expectedLocales($candidate['translations'] ?? null);
            $report = $this->validator->validateOrFail($candidate, $ingredient, $mode, $expectedLocales);
            $normalized = $report['normalized'];
            $warnings = collect($report['warnings'])
                ->merge($normalized['warnings'])
                ->merge($normalized['unresolved_questions'])
                ->filter()
                ->unique()
                ->values()
                ->all();
            $editedFields = collect($locked->edited_fields ?? [])
                ->merge($this->changedPaths($current, $normalized))
                ->filter(fn (mixed $path): bool => is_string($path))
                ->unique()
                ->sort()
                ->values()
                ->all();
            $plan = $this->planner->plan($ingredient, $normalized, $mode, $editedFields);

            $locked->update([
                'status' => $warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning,
                'original_result' => $locked->original_result ?? $current,
                'result' => $normalized,
                'validation_report' => $report,
                'plan' => $plan,
                'warnings' => $warnings,
                'unresolved_questions' => $normalized['unresolved_questions'],
                'edited_fields' => $editedFields,
                'edited_by_user_id' => $actor->id,
                'edited_at' => now(),
                'approved_by_user_id' => null,
                'approved_at' => null,
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return ['item' => $locked->refresh(), 'stale' => false];
        }, attempts: 5);

        if ($outcome['stale']) {
            throw ValidationException::withMessages([
                'item' => __('ingredient_enrichment_admin.validation.stale'),
            ]);
        }

        return $outcome['item'];
    }

    public function approve(User $actor, IngredientEnrichmentBatchItem $item): IngredientEnrichmentBatchItem
    {
        $outcome = DB::transaction(function () use ($actor, $item): array {
            $locked = IngredientEnrichmentBatchItem::query()
                ->lockForUpdate()
                ->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages([
                    'item' => __('ingredient_enrichment_admin.validation.not_approvable'),
                ]);
            }

            $batch = $locked->batch()->firstOrFail();
            $mode = $batch->mode;
            $ingredient = Ingredient::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($locked->ingredient_id);
            if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                throw ValidationException::withMessages([
                    'batch' => __('ingredient_enrichment_admin.validation.guidance_batch_mode'),
                ]);
            }

            if ($this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }

            $result = is_array($locked->result) ? $locked->result : [];
            $expectedLocales = $this->expectedLocales($result['translations'] ?? null);
            $report = $this->validator->validateOrFail($result, $ingredient, $mode, $expectedLocales);
            $locked->update([
                'status' => IngredientEnrichmentItemStatus::Approved,
                'result' => $report['normalized'],
                'validation_report' => $report,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);
            $this->batches->refresh($locked->ingredient_enrichment_batch_id);

            return ['item' => $locked->refresh(), 'stale' => false];
        }, attempts: 5);

        if ($outcome['stale']) {
            throw ValidationException::withMessages([
                'item' => __('ingredient_enrichment_admin.validation.stale'),
            ]);
        }

        return $outcome['item'];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function assertAllowed(array $proposal, IngredientEnrichmentBatchMode $mode): void
    {
        if (array_diff(array_keys($proposal), ['info_markdown', 'translations']) !== []) {
            throw ValidationException::withMessages([
                'proposal' => __('ingredient_enrichment_admin.validation.guidance_proposal_fields'),
            ]);
        }

        if (array_key_exists('info_markdown', $proposal) && ! is_string($proposal['info_markdown'])) {
            throw ValidationException::withMessages([
                'proposal.info_markdown' => __('ingredient_enrichment_admin.validation.guidance_english_text'),
            ]);
        }

        if (! array_key_exists('translations', $proposal)) {
            return;
        }

        if (! is_array($proposal['translations'])) {
            throw ValidationException::withMessages([
                'proposal.translations' => __('ingredient_enrichment_admin.validation.guidance_translations_array'),
            ]);
        }

        $supportedLocales = array_values(config('interface-translations.catalogue_locales', []));
        foreach ($proposal['translations'] as $index => $translation) {
            $path = "proposal.translations.{$index}";
            if (! is_array($translation)) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.guidance_translation_row'),
                ]);
            }
            $allowedTranslationFields = $mode->isLocalizationOnly()
                ? ['locale', 'info_markdown']
                : ['locale', 'display_name', 'saponification_name', 'info_markdown'];
            if (array_diff(array_keys($translation), $allowedTranslationFields) !== []) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.guidance_translation_fields'),
                ]);
            }
            if (! is_string($translation['locale'] ?? null)
                || trim($translation['locale']) === ''
                || ! in_array(trim($translation['locale']), $supportedLocales, true)) {
                throw ValidationException::withMessages([
                    "{$path}.locale" => __('ingredient_enrichment_admin.validation.guidance_translation_locale'),
                ]);
            }
            foreach (['display_name', 'info_markdown'] as $field) {
                if (array_key_exists($field, $translation) && ! is_string($translation[$field])) {
                    throw ValidationException::withMessages([
                        "{$path}.{$field}" => __('ingredient_enrichment_admin.validation.guidance_translation_text'),
                    ]);
                }
            }
            if (array_key_exists('saponification_name', $translation)
                && $translation['saponification_name'] !== null
                && ! is_string($translation['saponification_name'])) {
                throw ValidationException::withMessages([
                    "{$path}.saponification_name" => __('ingredient_enrichment_admin.validation.guidance_translation_text'),
                ]);
            }
        }
    }

    /** @return list<string> */
    private function expectedLocales(mixed $translations): array
    {
        return collect(is_array($translations) ? $translations : [])
            ->filter(fn (mixed $translation): bool => is_array($translation) && is_string($translation['locale'] ?? null))
            ->map(fn (array $translation): string => trim($translation['locale']))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedPaths(array $before, array $after): array
    {
        $englishChanges = ($before['info_markdown'] ?? null) !== ($after['info_markdown'] ?? null)
            ? ['proposal.info_markdown']
            : [];
        $beforeTranslations = collect($before['translations'] ?? [])->keyBy('locale');
        $translationChanges = collect($after['translations'] ?? [])
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->flatMap(function (array $translation) use ($beforeTranslations): array {
                $locale = (string) ($translation['locale'] ?? '');
                $before = $beforeTranslations->get($locale, []);

                return collect(['display_name', 'saponification_name', 'info_markdown'])
                    ->filter(fn (string $field): bool => array_key_exists($field, $translation)
                        && ($before[$field] ?? null) !== $translation[$field])
                    ->map(fn (string $field): string => "proposal.translations.{$locale}.{$field}")
                    ->values()
                    ->all();
            })
            ->values()
            ->all();

        return [...$englishChanges, ...$translationChanges];
    }
}
