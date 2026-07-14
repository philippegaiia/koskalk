<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\Support\NumberLocale;
use Illuminate\Database\Eloquent\Builder;

class RecipeWorkbenchViewDataBuilder
{
    public function __construct(
        private readonly RecipeWorkbenchService $recipeWorkbenchService,
        private readonly RecipeWorkbenchIngredientCatalogBuilder $recipeWorkbenchIngredientCatalogBuilder,
        private readonly RecipeWorkbenchIfraOptionsBuilder $recipeWorkbenchIfraOptionsBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ProductFamily $productFamily, ?Recipe $recipe, ?User $user, ?ProductType $productType = null): array
    {
        $savedDraft = $this->recipeWorkbenchService->currentVersionPayload($recipe);
        $defaultCurrency = $user?->defaultCurrency() ?? 'EUR';
        $productType ??= $recipe?->productType;

        return [
            'productFamily' => [
                'id' => $productFamily->id,
                'name' => $productFamily->name,
                'slug' => $productFamily->slug,
                'calculation_basis' => $productFamily->calculation_basis,
            ],
            'productType' => $this->productTypeData($productType),
            'productTypes' => $this->productTypes($productFamily, $productType),
            'recipe' => $this->recipeData($recipe),
            'savedDraft' => $savedDraft,
            'phases' => $this->recipeWorkbenchService->phaseBlueprints($productFamily),
            'ingredients' => $this->recipeWorkbenchIngredientCatalogBuilder->build($user, $productFamily),
            'ifraProductCategories' => $this->recipeWorkbenchIfraOptionsBuilder->categories($productFamily),
            'regulatoryRegimes' => $this->regulatoryRegimes(),
            'defaultIfraProductCategoryId' => $productFamily->slug === 'cosmetic'
                ? null
                : ($productType?->default_ifra_product_category_id
                    ?? $this->recipeWorkbenchIfraOptionsBuilder->defaultCategoryId($productFamily)),
            'costing' => null,
            'costingLoaded' => false,
            'packagingCatalog' => $this->recipeWorkbenchService->packagingCatalogPayload($user),
            'defaultCurrency' => $defaultCurrency,
            'currencies' => $this->currencyOptions(),
            'numberLocale' => $user instanceof User ? NumberLocale::resolve($user->number_locale) : null,
            'numberLocaleOptions' => NumberLocale::options(),
            'canPersist' => $user instanceof User,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function currencyOptions(): array
    {
        $currencies = config('currencies', []);

        return collect($currencies)
            ->mapWithKeys(fn (array $data, string $code): array => [
                $code => __('currencies.'.$code),
            ])
            ->sort()
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string, version_label: string|null, status: string, allergen_rule_count: int, substance_rule_count: int}>
     */
    private function regulatoryRegimes(): array
    {
        $today = now()->toDateString();
        $regimes = RegulatoryRegime::query()
            ->whereIn('status', ['active', 'preview'])
            ->withCount([
                'allergenRules as allergen_rule_count' => fn (Builder $query): Builder => $this->activeRuleCountQuery($query, $today),
                'substanceRules as substance_rule_count' => fn (Builder $query): Builder => $this->activeRuleCountQuery($query, $today),
            ])
            ->orderByDesc('is_default')
            ->orderBy('market_code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'version_label', 'status'])
            ->map(fn (RegulatoryRegime $regime): array => [
                'code' => $regime->code,
                'name' => $regime->name,
                'version_label' => $regime->version_label,
                'status' => $regime->status,
                'allergen_rule_count' => (int) $regime->allergen_rule_count,
                'substance_rule_count' => (int) $regime->substance_rule_count,
            ])
            ->values()
            ->all();

        return $regimes !== []
            ? $regimes
            : [[
                'code' => 'eu',
                'name' => 'EU regime',
                'version_label' => null,
                'status' => 'active',
                'allergen_rule_count' => 0,
                'substance_rule_count' => 0,
            ]];
    }

    private function activeRuleCountQuery(Builder $query, string $today): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $today);
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recipeData(?Recipe $recipe): ?array
    {
        if (! $recipe instanceof Recipe) {
            return null;
        }

        $hasSavedFormula = $recipe->relationLoaded('latestPublishedVersion')
            ? $recipe->latestPublishedVersion !== null
            : $recipe->latestPublishedVersion()->exists();

        return [
            'id' => $recipe->id,
            'public_id' => $recipe->public_id,
            'name' => $recipe->name,
            'description' => $recipe->description,
            'manufacturing_instructions' => $recipe->manufacturing_instructions,
            'featured_image_url' => $recipe->featuredImageUrl(),
            'is_locked' => $recipe->isLocked(),
            'locked_at' => $recipe->locked_at?->toISOString(),
            'locked_by' => $recipe->locked_by,
            'has_saved_formula' => $hasSavedFormula,
            'saved_formula_url' => $hasSavedFormula
                ? route('recipes.saved', $recipe)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productTypeData(?ProductType $productType): ?array
    {
        if (! $productType instanceof ProductType) {
            return null;
        }

        return [
            'id' => $productType->id,
            'name' => $productType->name,
            'slug' => $productType->slug,
            'default_ifra_product_category_id' => $productType->default_ifra_product_category_id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productTypes(ProductFamily $productFamily, ?ProductType $selectedProductType): array
    {
        $productTypes = ProductType::query()
            ->whereBelongsTo($productFamily)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'default_ifra_product_category_id']);

        if (
            $selectedProductType instanceof ProductType
            && ! $productTypes->contains('id', $selectedProductType->id)
        ) {
            $productTypes->push($selectedProductType);
        }

        return $productTypes
            ->map(fn (ProductType $productType): array => $this->productTypeData($productType))
            ->filter()
            ->values()
            ->all();
    }
}
