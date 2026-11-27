<?php

namespace App\Services;

use App\Enums\IngredientCatalogReviewStep;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tracks the administrator's progress through the platform ingredient catalogue:
 * applied enrichment, English guidance, current guidance translations, and sign-off.
 */
class IngredientCatalogReviewService
{
    /** @var list<string> */
    public const TRANSLATION_COLUMNS = ['id', 'ingredient_id', 'locale', 'info_markdown', 'source_fingerprint'];

    public function __construct(
        private readonly IngredientTranslationSourceFingerprint $sourceFingerprint,
    ) {}

    /**
     * Eager-load constraint that keeps translation rows to the columns progress needs.
     *
     * @return array<string, callable(HasMany<IngredientTranslation, Ingredient>): mixed>
     */
    public function translationEagerLoad(): array
    {
        return ['translations' => fn (HasMany $query): HasMany => $query->select(self::TRANSLATION_COLUMNS)];
    }

    /**
     * @return array{enriched: bool, has_guidance: bool, translated: int, stale: int, expected: int, step: IngredientCatalogReviewStep}
     */
    public function progress(Ingredient $ingredient): array
    {
        $expectedLocales = $this->expectedLocales();
        $canonicalFingerprint = $this->sourceFingerprint->forIngredient($ingredient);
        $guidanceTranslations = $ingredient->translations
            ->filter(fn (IngredientTranslation $translation): bool => in_array($translation->locale, $expectedLocales, true)
                && filled($translation->info_markdown));
        $stale = $guidanceTranslations
            ->filter(fn (IngredientTranslation $translation): bool => $translation->source_fingerprint !== $canonicalFingerprint)
            ->count();
        $hasGuidance = filled($ingredient->info_markdown);
        $translated = $guidanceTranslations->count();
        $expected = count($expectedLocales);

        return [
            'enriched' => filled(data_get($ingredient->source_data, 'enrichment.core')),
            'has_guidance' => $hasGuidance,
            'translated' => $translated,
            'stale' => $stale,
            'expected' => $expected,
            'step' => match (true) {
                ! $ingredient->requires_admin_review => IngredientCatalogReviewStep::Reviewed,
                ! $hasGuidance => IngredientCatalogReviewStep::MissingGuidance,
                $translated < $expected || $stale > 0 => IngredientCatalogReviewStep::TranslationsIncomplete,
                default => IngredientCatalogReviewStep::AwaitingReview,
            },
        ];
    }

    /**
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function constrainToStep(Builder $query, IngredientCatalogReviewStep $step): Builder
    {
        return match ($step) {
            IngredientCatalogReviewStep::Reviewed => $query->where('requires_admin_review', false),
            IngredientCatalogReviewStep::MissingGuidance => $query
                ->where('requires_admin_review', true)
                ->whereRaw("trim(coalesce(info_markdown, '')) = ''"),
            IngredientCatalogReviewStep::TranslationsIncomplete,
            IngredientCatalogReviewStep::AwaitingReview => $query->whereKey($this->idsWithGuidanceAtStep($step)),
        };
    }

    /**
     * Sign off the given platform ingredients. Ingredients already signed off keep their original date.
     *
     * @param  Collection<int, Ingredient>  $ingredients
     */
    public function markReviewed(User $reviewer, Collection $ingredients): int
    {
        return DB::transaction(function () use ($reviewer, $ingredients): int {
            $marked = 0;
            $lockedIngredients = Ingredient::query()
                ->whereKey($ingredients->map(fn (Ingredient $ingredient): int => (int) $ingredient->getKey())->all())
                ->lockForUpdate()
                ->get();

            foreach ($lockedIngredients as $ingredient) {
                if (! $ingredient->isPlatformCatalogRecord() || ! $ingredient->requires_admin_review) {
                    continue;
                }

                $ingredient->forceFill([
                    'requires_admin_review' => false,
                    'catalog_reviewed_at' => now(),
                    'catalog_reviewed_by_user_id' => $reviewer->id,
                ])->save();
                $marked++;
            }

            return $marked;
        }, attempts: 5);
    }

    /**
     * Translation freshness is a PHP fingerprint of the English guidance, so the two
     * guidance-dependent steps are resolved here rather than in SQL.
     *
     * @return list<int>
     */
    private function idsWithGuidanceAtStep(IngredientCatalogReviewStep $step): array
    {
        return Ingredient::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereNull('workspace_id')
            ->where('requires_admin_review', true)
            ->whereRaw("trim(coalesce(info_markdown, '')) <> ''")
            ->select(['id', 'owner_type', 'owner_id', 'workspace_id', 'info_markdown', 'requires_admin_review'])
            ->with($this->translationEagerLoad())
            ->lazyById(200)
            ->filter(fn (Ingredient $ingredient): bool => $this->progress($ingredient)['step'] === $step)
            ->map(fn (Ingredient $ingredient): int => (int) $ingredient->id)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function expectedLocales(): array
    {
        return array_values(config('interface-translations.catalogue_locales', []));
    }
}
