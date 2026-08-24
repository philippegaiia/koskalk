<?php

namespace App\Services;

use App\Enums\MassDisplaySystem;
use App\Enums\OwnerType;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RegulatoryRegime;
use App\Models\User;
use App\Models\Workspace;
use App\Support\NumberLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Translation\Translator;

class RecipeWorkbenchViewDataBuilder
{
    public function __construct(
        private readonly RecipeWorkbenchService $recipeWorkbenchService,
        private readonly RecipeWorkbenchIngredientCatalogBuilder $recipeWorkbenchIngredientCatalogBuilder,
        private readonly ProductTypeIfraOptionsBuilder $productTypeIfraOptionsBuilder,
        private readonly RecipeFormulaItemLimitService $recipeFormulaItemLimitService,
        private readonly CurrencyCatalog $currencyCatalog,
        private readonly Translator $translator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ProductFamily $productFamily, ?Recipe $recipe, ?User $user, ?ProductType $productType = null): array
    {
        $ingredients = $this->recipeWorkbenchIngredientCatalogBuilder->build($user, $productFamily);
        $savedDraft = $this->recipeWorkbenchService->currentVersionPayloadUsingCatalog($recipe, $ingredients);
        $defaultCurrency = $user?->defaultCurrency() ?? 'EUR';
        $productType ??= $recipe?->productType;
        $selectedIfraCategoryId = $savedDraft['selectedIfraProductCategoryId'] ?? null;
        $selectedIfraCategory = is_numeric($selectedIfraCategoryId)
            ? IfraProductCategory::query()->find((int) $selectedIfraCategoryId)
            : null;
        $ifraOptions = $this->productTypeIfraOptionsBuilder->build($productType, $selectedIfraCategory);
        $defaultMassGrams = $productFamily->calculation_basis === 'total_formula' ? '100' : '1000';
        $massDisplaySystem = $user?->company()?->mass_display_system ?? MassDisplaySystem::Metric;

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
            'ingredients' => $ingredients,
            'manufacturedIngredients' => $this->manufacturedIngredients($user),
            'ifraGuidance' => $ifraOptions,
            'ifraProductCategories' => $ifraOptions['all_categories'],
            'regulatoryRegimes' => $this->regulatoryRegimes(),
            'defaultIfraProductCategoryId' => $ifraOptions['default_category_id'],
            'costing' => null,
            'costingLoaded' => false,
            'packagingCatalog' => $this->recipeWorkbenchService->packagingCatalogPayload($user),
            'defaultCurrency' => $defaultCurrency,
            'currencies' => $this->currencyCatalog->options(app()->getLocale(), [$defaultCurrency]),
            'numberLocale' => $user instanceof User ? NumberLocale::resolve($user->number_locale) : null,
            'numberLocaleOptions' => NumberLocale::options(),
            'preferredMassUnit' => $massDisplaySystem->preferredUnit($defaultMassGrams)->value,
            'canPersist' => $user instanceof User,
            'formulaItemLimit' => $user instanceof User
                ? $this->recipeFormulaItemLimitService->limitFor($user)
                : null,
            'translations' => $this->translations(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function translations(): array
    {
        $fallbackTranslations = $this->translator->get('workbench', [], $this->translator->getFallback());
        $localizedTranslations = $this->translator->get('workbench', [], $this->translator->getLocale());

        return array_replace_recursive(
            is_array($fallbackTranslations) ? $fallbackTranslations : [],
            is_array($localizedTranslations) ? $localizedTranslations : [],
        );
    }

    /**
     * @return array<int, array{code: string, name: string, version_label: string|null, status: string, allergen_rule_count: int, substance_rule_count: int, milestones: array<string, string>}>
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
            ->get(['id', 'code', 'name', 'version_label', 'status', 'source_data'])
            ->map(fn (RegulatoryRegime $regime): array => [
                'code' => $regime->code,
                'name' => $regime->name,
                'version_label' => $regime->version_label,
                'status' => $regime->status,
                'allergen_rule_count' => (int) $regime->allergen_rule_count,
                'substance_rule_count' => (int) $regime->substance_rule_count,
                'milestones' => is_array($regime->source_data)
                    ? array_filter(array_map(
                        fn (mixed $value): string => (string) $value,
                        $regime->source_data['milestones'] ?? [],
                    ))
                    : [],
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
                'milestones' => [],
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

        $hasSavedFormula = array_key_exists('has_saved_formula', $recipe->getAttributes())
            ? (bool) $recipe->getAttribute('has_saved_formula')
            : ($recipe->relationLoaded('latestPublishedVersion')
                ? $recipe->latestPublishedVersion !== null
                : $recipe->latestPublishedVersion()->exists());

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
            'name' => $productType->localizedName(),
            'slug' => $productType->slug,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productTypes(ProductFamily $productFamily, ?ProductType $selectedProductType): array
    {
        $productTypes = ProductType::query()
            ->whereHas(
                'productFamilies',
                fn (Builder $query): Builder => $query->whereKey($productFamily->id),
            )
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'translations']);

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

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function manufacturedIngredients(?User $user): array
    {
        $workspace = $user?->company();

        if (! $workspace instanceof Workspace) {
            return [];
        }

        return Ingredient::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('owner_type', OwnerType::Workspace)
            ->where('is_active', true)
            ->where('is_manufactured', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name'])
            ->map(fn (Ingredient $ingredient): array => [
                'id' => $ingredient->id,
                'name' => $ingredient->display_name,
            ])
            ->all();
    }
}
