<?php

namespace App\Actions\IngredientEnrichment;

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshResultValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditIngredientGuidanceProposal
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
        private readonly IngredientGuidanceRefreshResultValidator $validator,
        private readonly IngredientEnrichmentBatchService $batches,
    ) {}

    /** @param array<string,mixed> $proposal */
    public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $proposal): IngredientEnrichmentBatchItem
    {
        Gate::forUser($actor)->authorize('approve', $item->batch);
        $this->assertAllowed($proposal);

        $outcome = DB::transaction(function () use ($actor, $item, $proposal): array {
            $locked = IngredientEnrichmentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, [IngredientEnrichmentItemStatus::Ready, IngredientEnrichmentItemStatus::Warning], true)) {
                throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.not_editable')]);
            }
            $batch = $locked->batch()->firstOrFail();
            $mode = $batch->mode;
            $ingredient = Ingredient::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->ingredient_id);
            if (! $mode instanceof IngredientEnrichmentBatchMode || ! $mode->isGuidance()) {
                throw ValidationException::withMessages(['batch' => 'This is not a guidance refresh batch.']);
            }
            if ($this->snapshots->fingerprint($ingredient) !== $locked->source_fingerprint) {
                $locked->update(['status' => IngredientEnrichmentItemStatus::Stale]);
                $this->batches->refresh($locked->ingredient_enrichment_batch_id);

                return ['item' => $locked->refresh(), 'stale' => true];
            }

            $current = is_array($locked->result) ? $locked->result : [];
            if ($mode->isLocalizationOnly()
                && array_key_exists('info_markdown', $proposal)
                && trim((string) $proposal['info_markdown']) !== trim((string) ($current['info_markdown'] ?? ''))) {
                throw ValidationException::withMessages(['proposal.info_markdown' => 'English guidance cannot be edited in a localization-only batch.']);
            }
            $candidate = $current;
            if (array_key_exists('info_markdown', $proposal)) {
                $candidate['info_markdown'] = $proposal['info_markdown'];
            }
            if (array_key_exists('translations', $proposal)) {
                $byLocale = collect($current['translations'] ?? [])->keyBy('locale');
                foreach ($proposal['translations'] as $translation) {
                    $byLocale->put((string) $translation['locale'], $translation);
                }
                $candidate['translations'] = $byLocale->values()->all();
            }
            $expectedLocales = collect($candidate['translations'] ?? [])->pluck('locale')->filter()->values()->all();
            $report = $this->validator->validateOrFail($candidate, $ingredient, $mode, $expectedLocales);
            $normalized = $report['normalized'];
            $warnings = collect($report['warnings'])
                ->merge($normalized['warnings'])
                ->merge($normalized['unresolved_questions'])
                ->filter()->unique()->values()->all();
            $editedFields = collect($locked->edited_fields ?? [])
                ->merge($this->changedPaths($current, $normalized))
                ->filter(fn (mixed $path): bool => is_string($path))
                ->unique()->sort()->values()->all();
            $locked->update([
                'status' => $warnings === [] ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Warning,
                'original_result' => $locked->original_result ?? $current,
                'result' => $normalized,
                'validation_report' => $report,
                'plan' => $this->plan($ingredient, $normalized),
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
            throw ValidationException::withMessages(['item' => __('ingredient_enrichment_admin.validation.stale')]);
        }

        return $outcome['item'];
    }

    /** @param array<string,mixed> $proposal */
    private function assertAllowed(array $proposal): void
    {
        if (array_diff(array_keys($proposal), ['info_markdown', 'translations']) !== []) {
            throw ValidationException::withMessages(['proposal' => 'Guidance proposals may contain only guidance fields.']);
        }
        if (isset($proposal['info_markdown']) && ! is_string($proposal['info_markdown'])) {
            throw ValidationException::withMessages(['proposal.info_markdown' => 'English guidance must be text.']);
        }
        if (isset($proposal['translations'])) {
            if (! is_array($proposal['translations'])) {
                throw ValidationException::withMessages(['proposal.translations' => 'Translations must be an array.']);
            }
            foreach ($proposal['translations'] as $index => $translation) {
                if (! is_array($translation) || array_diff(array_keys($translation), ['locale', 'info_markdown']) !== []) {
                    throw ValidationException::withMessages(["proposal.translations.{$index}" => 'Guidance translation rows may contain only locale and guidance.']);
                }
            }
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return list<string> */
    private function changedPaths(array $before, array $after): array
    {
        $paths = [];
        if (($before['info_markdown'] ?? null) !== ($after['info_markdown'] ?? null)) {
            $paths[] = 'proposal.info_markdown';
        }
        $beforeTranslations = collect($before['translations'] ?? [])->keyBy('locale');
        foreach ($after['translations'] ?? [] as $translation) {
            if (! is_array($translation)) {
                continue;
            }
            $locale = (string) ($translation['locale'] ?? '');
            if (($beforeTranslations->get($locale)['info_markdown'] ?? null) !== ($translation['info_markdown'] ?? null)) {
                $paths[] = "proposal.translations.{$locale}.info_markdown";
            }
        }

        return $paths;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function plan(Ingredient $ingredient, array $result): array
    {
        $decisions = [];
        if ((string) ($ingredient->info_markdown ?? '') !== (string) ($result['info_markdown'] ?? '')) {
            $decisions[] = [
                'field' => 'proposal.info_markdown',
                'decision' => 'replace',
                'current' => (string) ($ingredient->info_markdown ?? ''),
                'proposed' => (string) ($result['info_markdown'] ?? ''),
            ];
        }
        $currentTranslations = $ingredient->translations()->get(['locale', 'info_markdown'])->keyBy('locale');
        foreach ($result['translations'] ?? [] as $translation) {
            if (! is_array($translation)) {
                continue;
            }
            $locale = (string) ($translation['locale'] ?? '');
            $current = (string) ($currentTranslations->get($locale)?->info_markdown ?? '');
            $proposed = (string) ($translation['info_markdown'] ?? '');
            if ($current !== $proposed) {
                $decisions[] = [
                    'field' => "proposal.translations.{$locale}.info_markdown",
                    'decision' => 'replace',
                    'current' => $current,
                    'proposed' => $proposed,
                ];
            }
        }

        return [
            'changed' => $decisions !== [],
            'decisions' => $decisions,
            'effective' => [
                'info_markdown' => $result['info_markdown'] ?? '',
                'translations' => $result['translations'] ?? [],
            ],
        ];
    }
}
