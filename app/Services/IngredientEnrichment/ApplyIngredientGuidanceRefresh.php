<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientTranslationWriteIntent;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientTranslation;
use App\Models\User;
use App\Services\IngredientTranslationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyIngredientGuidanceRefresh
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientTranslationService $translations,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    /** @return array{applied:int,unchanged:int,stale:int,failed:int} */
    public function handle(User $actor, IngredientEnrichmentBatch $batch): array
    {
        $totals = ['applied' => 0, 'unchanged' => 0, 'stale' => 0, 'failed' => 0];
        $approvedItemIds = $batch->items()
            ->where('status', IngredientEnrichmentItemStatus::Approved->value)
            ->pluck('id');

        foreach ($approvedItemIds as $itemId) {
            try {
                $status = DB::transaction(function () use ($actor, $itemId): string {
                    $item = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($itemId);
                    $item->update(['status' => IngredientEnrichmentItemStatus::Applying]);
                    $batch = $item->batch()->firstOrFail();
                    $mode = $batch->mode;
                    if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                        throw ValidationException::withMessages([
                            'batch' => __('ingredient_enrichment_admin.validation.guidance_batch_mode'),
                        ]);
                    }

                    $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($item->ingredient_id);
                    $result = is_array($item->result) ? $item->result : [];
                    if ($this->snapshots->fingerprint($ingredient) !== (string) ($result['source_fingerprint'] ?? '')) {
                        $item->update(['status' => IngredientEnrichmentItemStatus::Stale]);

                        return 'stale';
                    }

                    $report = $this->validator->validateOrFail(
                        $result,
                        $ingredient,
                        $mode,
                        collect($result['translations'] ?? [])->pluck('locale')->filter()->values()->all(),
                    );
                    $normalized = $report['normalized'];
                    $beforeTranslations = $this->translationState($ingredient);
                    $beforeGuidance = is_array(data_get($ingredient->source_data, 'enrichment.guidance'))
                        ? data_get($ingredient->source_data, 'enrichment.guidance')
                        : [];
                    $beforeEvidence = $this->evidenceRows($beforeGuidance['evidence'] ?? []);
                    $editedFields = collect(is_array($item->edited_fields) ? $item->edited_fields : []);
                    $englishEdited = $editedFields->contains('proposal.info_markdown');
                    $reviewerLocales = $this->editedLocales($editedFields);
                    $revalidatedLocales = $this->revalidatedLocales($item);
                    $changed = false;
                    if (! $mode->isLocalizationOnly()
                        && (string) ($ingredient->info_markdown ?? '') !== (string) ($normalized['info_markdown'] ?? '')) {
                        $ingredient->info_markdown = $normalized['info_markdown'];
                        $ingredient->save();
                        $changed = true;
                    }

                    $translationRows = collect($beforeTranslations)
                        ->map(fn (array $translation): array => [
                            'locale' => $translation['locale'],
                            'display_name' => $translation['display_name'],
                            'saponification_name' => $translation['saponification_name'],
                            'info_markdown' => $translation['info_markdown'],
                        ])
                        ->keyBy('locale');
                    $proposalRows = collect($normalized['translations'] ?? [])
                        ->filter(fn (mixed $translation): bool => is_array($translation))
                        ->mapWithKeys(function (array $translation) use ($translationRows): array {
                            $locale = (string) ($translation['locale'] ?? '');
                            $current = $translationRows->get($locale, [
                                'locale' => $locale,
                                'display_name' => null,
                                'saponification_name' => null,
                                'info_markdown' => null,
                            ]);

                            return [$locale => [
                                ...$current,
                                'info_markdown' => $translation['info_markdown'] ?? null,
                            ]];
                        });
                    $selectedProposalRows = $proposalRows
                        ->filter(fn (array $translation, string $locale): bool => ! $englishEdited || $reviewerLocales->contains($locale));
                    $selectedProposalRows
                        ->each(fn (array $translation, string $locale) => $translationRows->put($locale, $translation));
                    $localizationPromptVersion = (string) ($normalized['prompt_versions']['localization']
                        ?? config('ingredient-enrichment.openai.guidance_localization_prompt_version'));
                    $writeIntents = $selectedProposalRows
                        ->mapWithKeys(function (array $translation, string $locale) use ($reviewerLocales, $revalidatedLocales, $localizationPromptVersion): array {
                            $reviewerEdited = $reviewerLocales->contains($locale);

                            return [$locale => new IngredientTranslationWriteIntent(
                                $reviewerEdited ? IngredientTranslationOrigin::ReviewerEdited : IngredientTranslationOrigin::AiGenerated,
                                $reviewerEdited ? null : $localizationPromptVersion,
                                $reviewerEdited || $revalidatedLocales->contains($locale),
                            )];
                        })
                        ->all();
                    $this->translations->sync(
                        $ingredient,
                        $translationRows->values()->all(),
                        IngredientTranslationOrigin::AiGenerated,
                        $localizationPromptVersion,
                        $writeIntents,
                    );
                    $afterTranslations = $this->translationState($ingredient);
                    $changed = $changed || $beforeTranslations !== $afterTranslations;

                    $sourceData = is_array($ingredient->source_data) ? $ingredient->source_data : [];
                    $guidance = $beforeGuidance;
                    $guidanceEvidence = $this->evidenceRows($normalized['guidance_evidence'] ?? []);
                    if ($guidanceEvidence !== []) {
                        $guidance['evidence'] = $guidanceEvidence;
                    }
                    if (! $mode->isLocalizationOnly()) {
                        $guidance['guidance_prompt_version'] = (string) ($normalized['prompt_versions']['guidance']
                            ?? config('ingredient-enrichment.openai.guidance_prompt_version'));
                    }
                    $guidance['localization_prompt_version'] = $localizationPromptVersion;
                    $guidance['approved_at'] = $item->approved_at?->toIso8601String() ?? CarbonImmutable::now()->toIso8601String();
                    $afterEvidence = $this->evidenceRows($guidance['evidence'] ?? []);
                    $changed = $changed
                        || $beforeEvidence !== $afterEvidence
                        || ($beforeGuidance['guidance_prompt_version'] ?? null) !== ($guidance['guidance_prompt_version'] ?? null)
                        || ($beforeGuidance['localization_prompt_version'] ?? null) !== ($guidance['localization_prompt_version'] ?? null);
                    data_set($sourceData, 'enrichment.guidance', $guidance);
                    $ingredient->source_data = $sourceData;
                    $ingredient->save();

                    $item->update([
                        'status' => $changed ? IngredientEnrichmentItemStatus::Applied : IngredientEnrichmentItemStatus::Unchanged,
                        'result' => $normalized,
                        'validation_report' => $report,
                        'applied_by_user_id' => $actor->id,
                        'applied_at' => now(),
                    ]);

                    return $changed ? 'applied' : 'unchanged';
                }, attempts: 5);
                $totals[$status]++;
            } catch (ValidationException) {
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $totals['stale']++;
            } catch (Throwable $exception) {
                report($exception);
                IngredientEnrichmentBatchItem::query()->whereKey($itemId)->update([
                    'status' => IngredientEnrichmentItemStatus::Failed,
                    'failure_message' => __('ingredient_enrichment_admin.validation.apply_failed'),
                ]);
                $totals['failed']++;
            }
        }

        $this->batches->refresh($batch->id);
        $this->batches->markAppliedWhenComplete($batch->id);

        return $totals;
    }

    /**
     * @return array<string, array{locale: string, display_name: string|null, saponification_name: string|null, info_markdown: string|null, source_fingerprint: string|null, origin: string|null, prompt_version: string|null}>
     */
    private function translationState(Ingredient $ingredient): array
    {
        return $ingredient->translations()
            ->get([
                'locale',
                'display_name',
                'saponification_name',
                'info_markdown',
                'source_fingerprint',
                'origin',
                'prompt_version',
            ])
            ->mapWithKeys(function (IngredientTranslation $translation): array {
                $origin = $translation->origin instanceof IngredientTranslationOrigin
                    ? $translation->origin->value
                    : ($translation->origin === null ? null : (string) $translation->origin);

                return [$translation->locale => [
                    'locale' => (string) $translation->locale,
                    'display_name' => $translation->display_name,
                    'saponification_name' => $translation->saponification_name,
                    'info_markdown' => $translation->info_markdown,
                    'source_fingerprint' => $translation->source_fingerprint,
                    'origin' => $origin,
                    'prompt_version' => $translation->prompt_version,
                ]];
            })
            ->sortKeys()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $editedFields
     * @return Collection<int, string>
     */
    private function editedLocales(Collection $editedFields): Collection
    {
        return $editedFields
            ->filter(fn (mixed $path): bool => is_string($path)
                && Str::startsWith($path, 'proposal.translations.')
                && Str::endsWith($path, '.info_markdown'))
            ->map(fn (string $path): string => Str::between($path, 'proposal.translations.', '.info_markdown'))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    private function revalidatedLocales(IngredientEnrichmentBatchItem $item): Collection
    {
        $decisions = is_array($item->plan) ? $item->plan['decisions'] ?? [] : [];

        return collect(is_array($decisions) ? $decisions : [])
            ->filter(fn (mixed $decision): bool => is_array($decision)
                && ($decision['decision'] ?? null) === 'revalidate'
                && is_string($decision['field'] ?? null)
                && Str::startsWith($decision['field'], 'proposal.translations.')
                && Str::endsWith($decision['field'], '.info_markdown'))
            ->map(fn (array $decision): string => Str::between($decision['field'], 'proposal.translations.', '.info_markdown'))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return list<array<string, mixed>> */
    private function evidenceRows(mixed $evidence): array
    {
        return collect(is_array($evidence) ? $evidence : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }
}
