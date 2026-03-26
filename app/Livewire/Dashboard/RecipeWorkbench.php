<?php

namespace App\Livewire\Dashboard;

use App\IngredientCategory;
use App\Models\IfraProductCategory;
use App\Models\IngredientVersion;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\RecipeWorkbenchService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecipeWorkbench extends Component
{
    #[Locked]
    public ?int $actorUserId = null;

    public ?int $recipeId = null;

    public function mount(?Recipe $recipe = null): void
    {
        $this->actorUserId = $this->currentUser()?->id;
        $this->recipeId = $recipe?->id;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveDraft(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be saved.',
            ];
        }

        try {
            $recipeVersion = $recipeWorkbenchService->saveDraft(
                $user,
                $this->soapFamily(),
                $draft,
                $this->currentRecipe(),
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        return [
            'ok' => true,
            'message' => 'Draft saved.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'draft' => $recipeWorkbenchService->draftPayload($recipe),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function saveAsNewVersion(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be versioned.',
            ];
        }

        try {
            $recipeVersion = $recipeWorkbenchService->saveAsNewVersion(
                $user,
                $this->soapFamily(),
                $draft,
                $this->currentRecipe(),
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        $this->recipeId = $recipeVersion->recipe_id;
        $recipe = Recipe::withoutGlobalScopes()->find($recipeVersion->recipe_id);

        return [
            'ok' => true,
            'message' => 'Version saved. A new draft is open for continued editing.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
            'draft' => $recipeWorkbenchService->draftPayload($recipe),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function duplicateFormula(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $user = $this->currentUser();

        if (! $user instanceof User) {
            return [
                'ok' => false,
                'message' => 'You need to be signed in before a formula can be duplicated.',
            ];
        }

        try {
            $recipeVersion = $recipeWorkbenchService->duplicate(
                $user,
                $this->soapFamily(),
                $draft,
            );
        } catch (ValidationException|InvalidArgumentException $exception) {
            return $this->saveErrorResponse($exception);
        }

        return [
            'ok' => true,
            'message' => 'Formula duplicated into a new draft.',
            'redirect' => route('recipes.edit', $recipeVersion->recipe_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function previewCalculation(array $draft, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        return [
            'ok' => true,
            'calculation' => $recipeWorkbenchService->previewSoapCalculation($draft),
        ];
    }

    public function comparisonVersion(int $versionId, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'No saved recipe is available for comparison.',
            ];
        }

        $draft = $recipeWorkbenchService->versionPayload($recipe, $versionId);

        if ($draft === null) {
            return [
                'ok' => false,
                'message' => 'The selected version could not be loaded.',
            ];
        }

        return [
            'ok' => true,
            'draft' => $draft,
            'calculation' => $recipeWorkbenchService->previewSoapCalculation([
                'oil_weight' => $draft['oilWeight'] ?? 0,
                'lye_type' => $draft['lyeType'] ?? 'naoh',
                'koh_purity_percentage' => $draft['kohPurity'] ?? 90,
                'dual_lye_koh_percentage' => $draft['dualKohPercentage'] ?? 40,
                'water_mode' => $draft['waterMode'] ?? 'percent_of_oils',
                'water_value' => $draft['waterValue'] ?? 38,
                'superfat' => $draft['superfat'] ?? 5,
                'phase_items' => $draft['phaseItems'] ?? [],
            ]),
        ];
    }

    public function loadVersion(int $versionId, RecipeWorkbenchService $recipeWorkbenchService): array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return [
                'ok' => false,
                'message' => 'No saved recipe is available to load.',
            ];
        }

        $draft = $recipeWorkbenchService->versionPayload($recipe, $versionId);

        if ($draft === null) {
            return [
                'ok' => false,
                'message' => 'The selected version could not be loaded.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Saved version loaded into the workbench. Save when you want to keep changes.',
            'draft' => $draft,
            'calculation' => $recipeWorkbenchService->previewSoapCalculation([
                'oil_weight' => $draft['oilWeight'] ?? 0,
                'lye_type' => $draft['lyeType'] ?? 'naoh',
                'koh_purity_percentage' => $draft['kohPurity'] ?? 90,
                'dual_lye_koh_percentage' => $draft['dualKohPercentage'] ?? 40,
                'water_mode' => $draft['waterMode'] ?? 'percent_of_oils',
                'water_value' => $draft['waterValue'] ?? 38,
                'superfat' => $draft['superfat'] ?? 5,
                'phase_items' => $draft['phaseItems'] ?? [],
            ]),
        ];
    }

    public function render(RecipeWorkbenchService $recipeWorkbenchService): View
    {
        $soapFamily = $this->soapFamily();
        $savedDraft = $recipeWorkbenchService->draftPayload($this->currentRecipe());

        return view('livewire.dashboard.recipe-workbench', [
            'workbench' => [
                'productFamily' => [
                    'id' => $soapFamily->id,
                    'name' => $soapFamily->name,
                    'slug' => $soapFamily->slug,
                    'calculation_basis' => $soapFamily->calculation_basis,
                ],
                'recipe' => $this->currentRecipeData(),
                'savedDraft' => $savedDraft,
                'initialCalculation' => $savedDraft === null ? null : $recipeWorkbenchService->previewSoapCalculation([
                    'oil_weight' => $savedDraft['oilWeight'] ?? 0,
                    'lye_type' => $savedDraft['lyeType'] ?? 'naoh',
                    'koh_purity_percentage' => $savedDraft['kohPurity'] ?? 90,
                    'dual_lye_koh_percentage' => $savedDraft['dualKohPercentage'] ?? 40,
                    'water_mode' => $savedDraft['waterMode'] ?? 'percent_of_oils',
                    'water_value' => $savedDraft['waterValue'] ?? 38,
                    'superfat' => $savedDraft['superfat'] ?? 5,
                    'phase_items' => $savedDraft['phaseItems'] ?? [],
                ]),
                'versionOptions' => $this->currentRecipe() instanceof Recipe
                    ? $recipeWorkbenchService->versionOptions($this->currentRecipe())
                    : [],
                'phases' => $recipeWorkbenchService->phaseBlueprints(),
                'ingredients' => $this->ingredientCatalog(),
                'ifraProductCategories' => IfraProductCategory::query()
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->get()
                    ->map(fn (IfraProductCategory $category): array => [
                        'id' => $category->id,
                        'code' => $category->code,
                        'name' => $category->name,
                    ])
                    ->all(),
            ],
        ]);
    }

    private function soapFamily(): ProductFamily
    {
        return ProductFamily::query()
            ->where('slug', 'soap')
            ->firstOrFail();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ingredientCatalog(): array
    {
        return IngredientVersion::query()
            ->with(['ingredient', 'sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->where('is_current', true)
            ->where('is_active', true)
            ->whereHas('ingredient', function (Builder $query): void {
                $query->where('is_active', true)
                    ->whereIn('category', [
                        IngredientCategory::CarrierOil->value,
                        IngredientCategory::EssentialOil->value,
                        IngredientCategory::BotanicalExtract->value,
                        IngredientCategory::Co2Extract->value,
                        IngredientCategory::Colorant->value,
                        IngredientCategory::Preservative->value,
                        IngredientCategory::Additive->value,
                    ]);
            })
            ->get()
            ->filter(function (IngredientVersion $version): bool {
                $category = $version->ingredient?->category;

                if ($category === IngredientCategory::CarrierOil) {
                    return $version->ingredient?->isAvailableForInitialSoapCalculation() ?? false;
                }

                return $category !== null;
            })
            ->map(function (IngredientVersion $version): array {
                $category = $version->ingredient?->category;
                $sapProfile = $version->sapProfile;

                return [
                    'id' => $version->id,
                    'ingredient_id' => $version->ingredient_id,
                    'name' => $version->display_name,
                    'inci_name' => $version->inci_name,
                    'category' => $category?->value,
                    'category_label' => $category?->getLabel(),
                    'soap_inci_naoh_name' => $version->soap_inci_naoh_name,
                    'soap_inci_koh_name' => $version->soap_inci_koh_name,
                    'needs_compliance' => $category !== null && in_array($category->value, IngredientCategory::aromaticValues(), true),
                    'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
                    'naoh_sap_value' => $sapProfile?->naoh_sap_value,
                    'fatty_acid_profile' => $version->normalizedFattyAcidProfile(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function currentRecipe(): ?Recipe
    {
        if ($this->recipeId === null) {
            return null;
        }

        $user = $this->currentUser();

        if (! $user instanceof User) {
            return null;
        }

        $recipe = Recipe::withoutGlobalScopes()
            ->whereKey($this->recipeId)
            ->first();

        if (! $recipe instanceof Recipe || ! $recipe->isAccessibleBy($user)) {
            return null;
        }

        return $recipe;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRecipeData(): ?array
    {
        $recipe = $this->currentRecipe();

        if (! $recipe instanceof Recipe) {
            return null;
        }

        return [
            'id' => $recipe->id,
            'name' => $recipe->name,
        ];
    }

    private function currentUser(): ?User
    {
        return app(CurrentAppUserResolver::class)->resolve($this->actorUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function saveErrorResponse(ValidationException|InvalidArgumentException $exception): array
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())
                ->flatten()
                ->first() ?? $exception->getMessage();

            return [
                'ok' => false,
                'message' => $message,
                'errors' => $exception->errors(),
            ];
        }

        return [
            'ok' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'draft' => [$exception->getMessage()],
            ],
        ];
    }
}
