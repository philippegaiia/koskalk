<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Enums\ProductionOutputType;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RecipeWorkbenchService
{
    public function __construct(
        private readonly RecipeWorkbenchDraftPayloadMapper $recipeWorkbenchDraftPayloadMapper,
        private readonly RecipeDraftSaver $recipeDraftSaver,
        private readonly RecipeVersionPublisher $recipeVersionPublisher,
        private readonly RecipeVersionCostingSynchronizer $recipeVersionCostingSynchronizer,
        private readonly RecipeWorkbenchPayloadNormalizer $recipeWorkbenchPayloadNormalizer,
        private readonly RecipeWorkbenchPreviewService $recipeWorkbenchPreviewService,
        private readonly RecipeWorkbenchPhaseBlueprints $recipeWorkbenchPhaseBlueprints,
        private readonly RecipeWorkbenchVersionDataService $recipeWorkbenchVersionDataService,
        private readonly LyeLiquidIngredientValidator $lyeLiquidIngredientValidator,
        private readonly EntitlementService $entitlementService,
        private readonly RecipeFormulaItemLimitService $recipeFormulaItemLimitService,
        private readonly RecipeMediaRollbackGuard $recipeMediaRollbackGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(array $payload, ?User $user = null): ?array
    {
        return $this->recipeWorkbenchPreviewService->previewSoapCalculation($payload, $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function phaseBlueprints(?ProductFamily $productFamily = null): array
    {
        return $this->recipeWorkbenchPhaseBlueprints->all($productFamily);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentVersionPayload(?Recipe $recipe): ?array
    {
        return $this->recipeWorkbenchVersionDataService->currentVersionPayload($recipe);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ingredientCatalog
     * @return array<string, mixed>|null
     */
    public function currentVersionPayloadUsingCatalog(?Recipe $recipe, array $ingredientCatalog): ?array
    {
        return $this->recipeWorkbenchVersionDataService->currentVersionPayloadUsingCatalog($recipe, $ingredientCatalog);
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function currentVersionSnapshot(?Recipe $recipe): ?array
    {
        return $this->recipeWorkbenchVersionDataService->currentVersionSnapshot($recipe);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publishedVersionHistory(Recipe $recipe): array
    {
        return $this->recipeWorkbenchVersionDataService->publishedVersionHistory($recipe);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function versionPayload(?Recipe $recipe, int $versionId): ?array
    {
        return $this->recipeWorkbenchVersionDataService->versionPayload($recipe, $versionId);
    }

    /**
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null}|null
     */
    public function versionSnapshot(?Recipe $recipe, int $versionId): ?array
    {
        return $this->recipeWorkbenchVersionDataService->versionSnapshot($recipe, $versionId);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    public function calculationFromWorkbenchDraft(array $draft): ?array
    {
        return $this->recipeWorkbenchPreviewService->calculationFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function previewInci(array $payload, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->previewInci($payload, $calculation);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function inciFromWorkbenchDraft(array $draft): array
    {
        return $this->recipeWorkbenchPreviewService->inciFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function labelingFromWorkbenchDraft(array $draft, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->labelingFromWorkbenchDraft($draft, $calculation);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function previewRestrictions(array $payload, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->previewRestrictions($payload, $calculation);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function restrictionsFromWorkbenchDraft(array $draft, ?array $calculation = null): array
    {
        return $this->recipeWorkbenchPreviewService->restrictionsFromWorkbenchDraft($draft, $calculation);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>, restrictions: array<string, mixed>}
     */
    public function snapshotFromWorkbenchDraft(array $draft): array
    {
        return $this->recipeWorkbenchPreviewService->snapshotFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>, restrictions: array<string, mixed>}
     */
    public function snapshotFromPersistedWorkbenchDraft(array $draft): array
    {
        return $this->recipeWorkbenchPreviewService->snapshotFromPersistedWorkbenchDraft($draft);
    }

    public function save(
        User $user,
        ProductFamily $productFamily,
        array $payload,
        ?Recipe $recipe = null,
        ?Recipe $sopSourceRecipe = null,
        ?Closure $preparePayloadForRecipe = null,
    ): RecipeVersion {
        if ($recipe instanceof Recipe) {
            Gate::forUser($user)->authorize('update', $recipe);
        }

        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload, $productFamily, false, $recipe);
        if ($recipe instanceof Recipe) {
            $this->recipeFormulaItemLimitService->assertUpdateAllowed($user, $normalizedPayload, $recipe);
        } else {
            $this->recipeFormulaItemLimitService->assertCreateAllowed($user, $normalizedPayload);
        }
        $this->validateLyeLiquidIngredients($user, $normalizedPayload);
        $this->validateIngredientAccess($user, $normalizedPayload);
        $this->validateOutputConfiguration($user, $normalizedPayload);
        $this->validateProductReference($user, $normalizedPayload, $recipe);
        $this->validatePreviewableSoapCalculation($productFamily, $normalizedPayload, $user);

        $createdRecipe = null;
        $trackCreatedRecipe = function (Recipe $destinationRecipe) use (&$createdRecipe): void {
            $createdRecipe = $destinationRecipe;
        };
        $save = fn (): RecipeVersion => $this->recipeDraftSaver->save(
            $user,
            $productFamily,
            $normalizedPayload,
            $recipe,
            $sopSourceRecipe,
            $preparePayloadForRecipe,
            $trackCreatedRecipe,
        );

        $currentVersion = $recipe instanceof Recipe
            ? $save()
            : $this->recipeMediaRollbackGuard->run(
                true,
                function () use (&$createdRecipe): ?Recipe {
                    return $createdRecipe;
                },
                fn (): RecipeVersion => $this->entitlementService->withinCompanyQuotaLock(
                    $user,
                    function (Workspace $workspace) use ($save): RecipeVersion {
                        $this->entitlementService->assertCanCreateRecipeInWorkspace($workspace);

                        return $save();
                    },
                    attempts: 1,
                ),
            );

        $this->recipeVersionCostingSynchronizer->reconcileExistingCosting($currentVersion, $user);

        return $currentVersion;
    }

    public function publish(
        User $user,
        ProductFamily $productFamily,
        array $payload,
        ?Recipe $recipe = null,
        ?Closure $preparePayloadForRecipe = null,
    ): RecipeVersion {
        if ($recipe instanceof Recipe) {
            Gate::forUser($user)->authorize('update', $recipe);
        }

        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($payload, $productFamily, true, $recipe);
        if ($recipe instanceof Recipe) {
            $this->recipeFormulaItemLimitService->assertUpdateAllowed($user, $normalizedPayload, $recipe);
        } else {
            $this->recipeFormulaItemLimitService->assertCreateAllowed($user, $normalizedPayload);
        }
        $this->validateLyeLiquidIngredients($user, $normalizedPayload);
        $this->validateIngredientAccess($user, $normalizedPayload);
        $this->validateOutputConfiguration($user, $normalizedPayload);
        $this->validateProductReference($user, $normalizedPayload, $recipe);
        $this->validatePreviewableSoapCalculation($productFamily, $normalizedPayload, $user);

        $createdRecipe = null;
        $trackCreatedRecipe = function (Recipe $destinationRecipe) use (&$createdRecipe): void {
            $createdRecipe = $destinationRecipe;
        };
        $publish = fn (): RecipeVersion => $this->recipeVersionPublisher->publish(
            $user,
            $productFamily,
            $normalizedPayload,
            $recipe,
            $preparePayloadForRecipe,
            $trackCreatedRecipe,
        );

        if ($recipe instanceof Recipe) {
            return $publish();
        }

        return $this->recipeMediaRollbackGuard->run(
            true,
            function () use (&$createdRecipe): ?Recipe {
                return $createdRecipe;
            },
            fn (): RecipeVersion => $this->entitlementService->withinCompanyQuotaLock(
                $user,
                function (Workspace $workspace) use ($publish): RecipeVersion {
                    $this->entitlementService->assertCanCreateRecipeInWorkspace($workspace);

                    return $publish();
                },
                attempts: 1,
            ),
        );
    }

    public function saveAsNewVersion(User $user, ProductFamily $productFamily, array $payload, ?Recipe $recipe = null): RecipeVersion
    {
        return $this->publish($user, $productFamily, $payload, $recipe);
    }

    public function duplicate(
        User $user,
        ProductFamily $productFamily,
        array $payload,
        ?Recipe $sourceRecipe = null,
        ?Closure $preparePayloadForRecipe = null,
    ): RecipeVersion {
        $copyPayload = $payload;
        $copyPayload['name'] = $this->duplicateName((string) ($payload['name'] ?? 'Soap Formula'));
        $copyPayload['product_reference'] = null;

        return $this->save(
            $user,
            $productFamily,
            $copyPayload,
            sopSourceRecipe: $sourceRecipe,
            preparePayloadForRecipe: $preparePayloadForRecipe,
        );
    }

    public function duplicateRecipe(User $user, Recipe $recipe): RecipeVersion
    {
        Gate::forUser($user)->authorize('view', $recipe);

        $workbenchPayload = $this->recipeWorkbenchVersionDataService->currentVersionPayload($recipe);
        $sourceVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', true)
            ->first();

        if (! is_array($workbenchPayload)) {
            throw new InvalidArgumentException('No formula data is available to duplicate.');
        }

        $duplicate = $this->duplicate(
            $user,
            $recipe->productFamily()->withoutGlobalScopes()->firstOrFail(),
            $this->recipeWorkbenchDraftPayloadMapper->toSavePayload($workbenchPayload),
            $recipe,
        );

        $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $duplicate, $user);

        return $duplicate;
    }

    public function restoreCurrentVersion(User $user, Recipe $recipe, int $versionId): RecipeVersion
    {
        $productFamily = $recipe->productFamily()->withoutGlobalScopes()->firstOrFail();
        $targetPayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload(
            $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId),
        );
        $normalizedTargetPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($targetPayload, $productFamily, false, $recipe);
        $this->recipeFormulaItemLimitService->assertRestoreAllowed($user, $normalizedTargetPayload);

        return DB::transaction(function () use ($recipe, $user, $versionId, $productFamily, $targetPayload): RecipeVersion {
            $currentVersion = $this->save(
                $user,
                $productFamily,
                $targetPayload,
                $recipe,
            );

            $sourceVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->findOrFail($versionId);

            $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $currentVersion, $user);
            app(RecipeSopSnapshotService::class)->copySopMediaAssets($sourceVersion, $recipe);
            app(RecipeSopSnapshotService::class)->copySopMediaAssets($sourceVersion, $currentVersion);

            return $currentVersion;
        });
    }

    public function currentVersionWouldBeReplacedByVersion(Recipe $recipe, int $versionId): bool
    {
        $currentVersionPayload = $this->recipeWorkbenchVersionDataService->currentVersionPayload($recipe);

        if (! is_array($currentVersionPayload)) {
            return false;
        }

        $currentVersionSavePayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload($currentVersionPayload);
        $targetVersionSavePayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload(
            $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId),
        );

        $productFamily = $recipe->productFamily()->withoutGlobalScopes()->firstOrFail();

        return $this->recipeWorkbenchPayloadNormalizer->normalize($currentVersionSavePayload, $productFamily, false, $recipe) !==
            $this->recipeWorkbenchPayloadNormalizer->normalize($targetVersionSavePayload, $productFamily, false, $recipe);
    }

    public function restorePublishedFormula(User $user, Recipe $recipe, int $versionId): RecipeVersion
    {
        Gate::forUser($user)->authorize('update', $recipe);

        $sourceVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->findOrFail($versionId);

        $productFamily = $recipe->productFamily()->withoutGlobalScopes()->firstOrFail();
        $versionPayload = $this->recipeWorkbenchVersionDataService->publishedVersionPayload($recipe, $versionId);
        $savePayload = $this->recipeWorkbenchDraftPayloadMapper->toSavePayload($versionPayload);
        $normalizedPayload = $this->recipeWorkbenchPayloadNormalizer->normalize($savePayload, $productFamily, true, $recipe);
        $this->recipeFormulaItemLimitService->assertRestoreAllowed($user, $normalizedPayload);

        return $this->recipeVersionPublisher->restore($user, $recipe, $normalizedPayload, $sourceVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function costingPayload(?Recipe $recipe, ?User $user): array
    {
        return $this->recipeVersionCostingSynchronizer->payload($recipe, $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function packagingCatalogPayload(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return $this->recipeVersionCostingSynchronizer->packagingCatalogPayload($user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function saveCosting(User $user, Recipe $recipe, array $payload): array
    {
        Gate::forUser($user)->authorize('update', $recipe);

        $currentVersion = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', true)
            ->firstOrFail();

        return $this->recipeVersionCostingSynchronizer->save($currentVersion, $user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function savePackagingCatalogItem(User $user, array $payload): array
    {
        return $this->recipeVersionCostingSynchronizer->savePackagingItem($user, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePreviewableSoapCalculation(ProductFamily $productFamily, array $payload, User $user): void
    {
        if ($productFamily->slug !== 'soap') {
            return;
        }

        $this->recipeWorkbenchPreviewService->previewSoapCalculation(
            $this->previewPayloadFromNormalizedSavePayload($payload),
            $user,
            validateLyeIngredients: false,
        );
    }

    /** @param array<string, mixed> $payload */
    private function validateLyeLiquidIngredients(User $user, array $payload): void
    {
        $lyeLiquidPhase = collect($payload['phases'] ?? [])->first(
            fn (mixed $phase): bool => is_array($phase) && ($phase['key'] ?? null) === 'lye_water',
        );

        $this->lyeLiquidIngredientValidator->validate(
            is_array($lyeLiquidPhase) && is_array($lyeLiquidPhase['items'] ?? null)
                ? $lyeLiquidPhase['items']
                : [],
            $user,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateIngredientAccess(User $user, array $payload): void
    {
        $ingredientIds = collect($payload['phases'] ?? [])
            ->filter(fn (mixed $phase): bool => is_array($phase) && ($phase['key'] ?? null) !== 'lye_water')
            ->flatMap(fn (array $phase): array => is_array($phase['items'] ?? null) ? $phase['items'] : [])
            ->filter(fn (mixed $item): bool => is_array($item) && is_numeric($item['ingredient_id'] ?? null))
            ->map(fn (array $item): int => (int) $item['ingredient_id'])
            ->filter(fn (int $ingredientId): bool => $ingredientId > 0)
            ->unique()
            ->values();

        if ($ingredientIds->isEmpty()) {
            return;
        }

        $accessibleIngredientIds = Ingredient::query()
            ->accessibleTo($user)
            ->whereKey($ingredientIds->all())
            ->pluck('id')
            ->map(fn (mixed $ingredientId): int => (int) $ingredientId)
            ->all();

        if ($ingredientIds->diff($accessibleIngredientIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'phase_items' => 'One or more selected ingredients are no longer available.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateOutputConfiguration(User $user, array $payload): void
    {
        $outputType = ProductionOutputType::from($payload['production_output_type']);
        $outputIngredientId = $payload['output_ingredient_id'] ?? null;

        if ($outputType === ProductionOutputType::FinishedProduct && $outputIngredientId !== null) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => __('production_bench.production.validation.finished_output_has_no_ingredient'),
            ]);
        }

        if ($outputType !== ProductionOutputType::ManufacturedIngredient) {
            return;
        }

        $workspace = $user->company();
        $outputIngredient = $workspace === null || $outputIngredientId === null
            ? null
            : Ingredient::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('owner_type', OwnerType::Workspace)
                ->where('is_active', true)
                ->where('is_manufactured', true)
                ->find($outputIngredientId);

        if (! $outputIngredient instanceof Ingredient) {
            throw ValidationException::withMessages([
                'output_ingredient_id' => $outputIngredientId === null
                    ? __('production_bench.production.validation.output_ingredient_required')
                    : __('production_bench.production.validation.output_ingredient_invalid'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateProductReference(User $user, array $payload, ?Recipe $recipe): void
    {
        $productReference = $payload['product_reference'] ?? null;
        $workspace = $user->company();

        if (! is_string($productReference) || $productReference === '' || ! $workspace instanceof Workspace) {
            return;
        }

        $referenceIsUsed = Recipe::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('product_reference', $productReference)
            ->when($recipe instanceof Recipe, fn ($query) => $query->whereKeyNot($recipe->id))
            ->exists();

        if ($referenceIsUsed) {
            throw ValidationException::withMessages([
                'product_reference' => __('workbench.settings.product_reference_unique'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function previewPayloadFromNormalizedSavePayload(array $payload): array
    {
        $calculationContext = is_array($payload['calculation_context'] ?? null)
            ? $payload['calculation_context']
            : [];
        $waterSettings = is_array($payload['water_settings'] ?? null)
            ? $payload['water_settings']
            : [];
        $phaseItems = [];

        foreach (($payload['phases'] ?? []) as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $phaseKey = (string) ($phase['key'] ?? '');

            if ($phaseKey === '') {
                continue;
            }

            $phaseItems[$phaseKey] = is_array($phase['items'] ?? null) ? $phase['items'] : [];
        }

        return [
            'manufacturing_mode' => $payload['manufacturing_mode'] ?? 'saponify_in_formula',
            'exposure_mode' => $payload['exposure_mode'] ?? 'rinse_off',
            'regulatory_regime' => $payload['regulatory_regime'] ?? 'eu',
            'product_type_id' => $payload['product_type_id'] ?? null,
            'oil_weight' => $calculationContext['oil_weight'] ?? $payload['oil_weight'] ?? 0,
            'lye_type' => $calculationContext['lye_type'] ?? 'naoh',
            'koh_purity_percentage' => $calculationContext['koh_purity_percentage'] ?? 90,
            'dual_lye_koh_percentage' => $calculationContext['dual_lye_koh_percentage'] ?? 40,
            'water_mode' => $waterSettings['mode'] ?? 'percent_of_oils',
            'water_value' => $waterSettings['value'] ?? 38,
            'superfat' => $calculationContext['superfat'] ?? 5,
            'phase_items' => $phaseItems,
        ];
    }

    private function duplicateName(string $name): string
    {
        return Str::startsWith($name, 'Copy of ')
            ? $name
            : 'Copy of '.$name;
    }
}
