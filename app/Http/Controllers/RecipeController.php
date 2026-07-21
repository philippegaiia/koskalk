<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Services\CurrentAppUserResolver;
use App\Services\EntitlementService;
use App\Services\MediaStorage;
use App\Services\RecipeCsvExporter;
use App\Services\RecipeExportDataBuilder;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeVersionDeletionService;
use App\Services\RecipeVersionViewDataBuilder;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbookExporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeController extends Controller
{
    public function index(): View
    {
        return view('recipes.index');
    }

    public function create(Request $request): View
    {
        $productFamilySlug = $request->string('family')->toString() ?: 'soap';
        $productTypeSlug = $request->string('type')->toString();
        $productFamily = ProductFamily::query()
            ->where('slug', $productFamilySlug)
            ->firstOrFail();

        $productType = $productTypeSlug !== ''
            ? ProductType::query()
                ->whereBelongsTo($productFamily)
                ->where('slug', $productTypeSlug)
                ->where('is_active', true)
                ->firstOrFail()
            : null;

        return view('recipes.workbench', [
            'productFamily' => $productFamily,
            'productType' => $productType,
        ]);
    }

    public function edit(string $recipe, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);

        return view('recipes.workbench', [
            'recipe' => $recipe,
        ]);
    }

    public function saved(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        EntitlementService $entitlementService,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
        RecipeVersionCostPreviewBuilder $recipeVersionCostPreviewBuilder,
    ): View {
        $user = $currentAppUserResolver->resolve();
        [$recipe, $currentFormula] = $this->accessibleCurrentVersion($recipe, $currentAppUserResolver);

        return $this->renderSavedVersion(
            $recipe,
            $currentFormula,
            $request,
            $user,
            $entitlementService,
            $recipeVersionViewDataBuilder,
            $recipeVersionCostPreviewBuilder,
            false,
        );
    }

    private function renderSavedVersion(
        Recipe $recipe,
        RecipeVersion $version,
        Request $request,
        ?User $user,
        EntitlementService $entitlementService,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
        RecipeVersionCostPreviewBuilder $recipeVersionCostPreviewBuilder,
        bool $isHistorical,
    ): View {
        $viewData = $recipeVersionViewDataBuilder->build($recipe, $version, $request->query('oil_weight'), $request->query());
        $canUpdateRecipe = $user !== null && $user->can('update', $recipe);
        $canRecordProduction = $canUpdateRecipe
            && $entitlementService->canCreateProductionBatch($user);

        return view('recipes.version', [
            ...$viewData,
            'isHistorical' => $isHistorical,
            'canRestoreVersion' => $canUpdateRecipe,
            'canRecordProduction' => $canRecordProduction,
            'productionPreview' => $user !== null
                ? $this->productionPreview($recipe, $version, $user, $viewData, $recipeVersionCostPreviewBuilder)
                : null,
            'productionBatches' => $canUpdateRecipe
                ? $recipe->productionBatches()
                    ->where('user_id', $user->id)
                    ->limit(8)
                    ->get()
                : collect(),
        ]);
    }

    public function duplicate(
        string $recipe,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);
        $duplicateDraft = $recipeWorkbenchService->duplicateRecipe($user, $recipe);
        $duplicateRecipe = Recipe::withoutGlobalScopes()->findOrFail($duplicateDraft->recipe_id);

        return redirect()
            ->route('recipes.edit', $duplicateRecipe)
            ->with('status', __('products.status.duplicated'));
    }

    public function lock(string $recipe, CurrentAppUserResolver $currentAppUserResolver): RedirectResponse
    {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);

        $this->authorize('update', $recipe);

        if (! $recipe->isLocked()) {
            $recipe->update([
                'locked_at' => now(),
                'locked_by' => $user->id,
            ]);
        }

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', __('products.status.locked'));
    }

    public function unlock(string $recipe, CurrentAppUserResolver $currentAppUserResolver): RedirectResponse
    {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);

        $this->authorize('update', $recipe);

        if ($recipe->isLocked()) {
            $recipe->update([
                'locked_at' => null,
                'locked_by' => null,
            ]);
        }

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', __('products.status.unlocked'));
    }

    public function editCurrentFormula(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $savedFormula] = $this->accessibleLatestPublishedFormula($recipe, $currentAppUserResolver);

        $this->authorize('update', $recipe);

        if (
            $recipeWorkbenchService->currentVersionWouldBeReplacedByVersion($recipe, $savedFormula->id)
            && ! $request->boolean('confirm_replace_current')
        ) {
            return redirect()
                ->route('recipes.saved', $recipe)
                ->with('currentReplaceConfirmation', [
                    'title' => 'Replace the current formula?',
                    'body' => 'The current formula differs from this saved snapshot. Confirming will replace the formula with the saved snapshot data.',
                    'action_label' => 'Replace formula',
                    'action_url' => route('recipes.saved.edit-current', $recipe),
                ]);
        }

        $recipeWorkbenchService->restoreCurrentVersion($user, $recipe, $savedFormula->id);

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', 'Formula refreshed from the saved snapshot.');
    }

    public function restorePublishedFormula(
        string $recipe,
        string $version,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $version] = $this->accessibleBackupVersion($recipe, $version, $currentAppUserResolver);

        $this->authorize('update', $recipe);

        $recipeWorkbenchService->restorePublishedFormula($user, $recipe, $version->id);

        return redirect()
            ->route('recipes.saved', $recipe)
            ->with('status', 'Formula restored from the selected saved backup.');
    }

    public function version(
        string $recipe,
        string $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        EntitlementService $entitlementService,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
        RecipeVersionCostPreviewBuilder $recipeVersionCostPreviewBuilder,
    ): View {
        $user = $currentAppUserResolver->resolve();
        [$recipe, $version] = $this->accessibleBackupVersion($recipe, $version, $currentAppUserResolver);

        return $this->renderSavedVersion(
            $recipe,
            $version,
            $request,
            $user,
            $entitlementService,
            $recipeVersionViewDataBuilder,
            $recipeVersionCostPreviewBuilder,
            true,
        );
    }

    public function printSavedRecipe(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSavedProductionSheet($recipe, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedProductionSheet(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSheet(
            $recipe,
            $request,
            $currentAppUserResolver,
            $recipeVersionViewDataBuilder,
            'production',
        );
    }

    public function printRecipe(
        string $recipe,
        string $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSheet(
            $recipe,
            $request,
            $currentAppUserResolver,
            $recipeVersionViewDataBuilder,
            'production',
            $version,
        );
    }

    public function printSavedDetails(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSavedTechnicalSheet($recipe, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedTechnicalSheet(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSheet(
            $recipe,
            $request,
            $currentAppUserResolver,
            $recipeVersionViewDataBuilder,
            'technical',
        );
    }

    public function printSavedCostingSheet(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSheet(
            $recipe,
            $request,
            $currentAppUserResolver,
            $recipeVersionViewDataBuilder,
            'costing',
        );
    }

    public function exportSavedWorkbook(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeExportDataBuilder $recipeExportDataBuilder,
        RecipeWorkbookExporter $recipeWorkbookExporter,
    ): StreamedResponse {
        [$recipe, $savedFormula] = $this->accessibleSheetVersion($recipe, $request, $currentAppUserResolver);
        $exportData = $recipeExportDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());
        $filename = $this->exportFilename($recipe, 'xlsx');

        return response()->streamDownload(
            fn (): int => print $recipeWorkbookExporter->export($exportData),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function exportSavedFormulaCsv(
        string $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeExportDataBuilder $recipeExportDataBuilder,
        RecipeCsvExporter $recipeCsvExporter,
    ): StreamedResponse {
        [$recipe, $savedFormula] = $this->accessibleSheetVersion($recipe, $request, $currentAppUserResolver);
        $exportData = $recipeExportDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());
        $filename = $this->exportFilename($recipe, 'csv');

        return response()->streamDownload(
            fn (): int => print $recipeCsvExporter->export($exportData),
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function printDetails(
        string $recipe,
        string $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSheet(
            $recipe,
            $request,
            $currentAppUserResolver,
            $recipeVersionViewDataBuilder,
            'technical',
            $version,
        );
    }

    public function restoreCurrentVersion(
        string $recipe,
        string $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $version] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        $this->authorize('update', $recipe);

        if (
            $recipeWorkbenchService->currentVersionWouldBeReplacedByVersion($recipe, $version->id)
            && ! $request->boolean('confirm_replace_current')
        ) {
            return redirect()
                ->route('recipes.saved', $recipe)
                ->with('currentReplaceConfirmation', [
                    'title' => 'Replace the current formula?',
                    'body' => 'The current formula differs from this saved backup. Confirming will replace the formula with the selected saved state.',
                    'action_label' => 'Replace formula',
                    'action_url' => route('recipes.use-version-as-current', ['recipe' => $recipe, 'version' => $version]),
                ]);
        }

        $recipeWorkbenchService->restoreCurrentVersion($user, $recipe, $version->id);

        return redirect()
            ->route('recipes.edit', $recipe)
            ->with('status', 'Formula replaced with the selected saved backup.');
    }

    public function destroy(
        string $recipe,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()->where('public_id', $recipe)->firstOrFail();

        $this->authorize('delete', $recipe);

        abort_unless($request->string('confirm_name')->toString() === $recipe->name, 403, __('products.validation.confirmation_mismatch'));

        $mediaPaths = $recipe->mediaPaths();

        DB::transaction(function () use ($recipe): void {
            $recipe->delete();
        });

        $mediaPaths->each(function (string $path): void {
            MediaStorage::deleteRecipePath($path);
        });
        MediaStorage::deleteRecipeDirectory($recipe);

        return redirect()
            ->route('recipes.index')
            ->with('status', __('products.status.deleted'));
    }

    public function destroyVersion(
        string $recipe,
        string $version,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
        RecipeVersionDeletionService $recipeVersionDeletionService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()->where('public_id', $recipe)->firstOrFail();
        $version = RecipeVersion::withoutGlobalScopes()->where('public_id', $version)->firstOrFail();

        abort_unless($version->recipe_id === $recipe->id, 404);

        $this->authorize('delete', $recipe);
        $this->authorize('delete', $version);

        if (! $version->is_current) {
            abort_unless($request->string('confirm_name')->toString() === $version->name, 403, __('products.validation.confirmation_mismatch'));
        }

        $deletion = $recipeVersionDeletionService->delete($recipe, $version);
        $status = $deletion['last_published_deleted']
            ? __('products.status.last_version_deleted')
            : __('products.status.version_deleted');

        return redirect()
            ->route('recipes.index')
            ->with('status', $status);
    }

    private function accessibleRecipe(string $recipePublicId, CurrentAppUserResolver $currentAppUserResolver): Recipe
    {
        $user = $currentAppUserResolver->resolve();
        $recipe = Recipe::withoutGlobalScopes()
            ->withExists('publishedVersions as has_saved_formula')
            ->where('public_id', $recipePublicId)
            ->firstOrFail();

        abort_unless($user !== null && $user->can('view', $recipe), 404);

        return $recipe;
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleSavedVersion(
        string $recipePublicId,
        string $versionPublicId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        $recipe = $this->accessibleRecipe($recipePublicId, $currentAppUserResolver);
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('public_id', $versionPublicId)
            ->firstOrFail();

        return [$recipe, $version];
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleBackupVersion(
        string $recipePublicId,
        string $versionPublicId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        [$recipe, $version] = $this->accessibleSavedVersion($recipePublicId, $versionPublicId, $currentAppUserResolver);

        abort_if($version->is_current, 404);

        return [$recipe, $version];
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleLatestPublishedFormula(
        string $recipePublicId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        $recipe = $this->accessibleRecipe($recipePublicId, $currentAppUserResolver);
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', false)
            ->orderByDesc('version_number')
            ->firstOrFail();

        return [$recipe, $version];
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleSheetVersion(
        string $recipePublicId,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        ?string $explicitVersionPublicId = null,
    ): array {
        if ($explicitVersionPublicId === null && ! $request->has('version')) {
            return $this->accessibleLatestPublishedFormula($recipePublicId, $currentAppUserResolver);
        }

        $requestedVersionPublicId = $explicitVersionPublicId ?? $request->string('version')->toString();

        abort_unless(Str::isUuid($requestedVersionPublicId), 404);

        return $this->accessibleBackupVersion($recipePublicId, $requestedVersionPublicId, $currentAppUserResolver);
    }

    /**
     * @param  string  $printMode  Retained while legacy print routes share the canonical working-print renderer.
     */
    private function printSheet(
        string $recipePublicId,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
        string $printMode,
        ?string $explicitVersionPublicId = null,
    ): View {
        [$recipe, $version] = $this->accessibleSheetVersion(
            $recipePublicId,
            $request,
            $currentAppUserResolver,
            $explicitVersionPublicId,
        );

        return view('recipes.print', [
            ...$recipeVersionViewDataBuilder->build($recipe, $version, $request->query('oil_weight'), $request->query()),
            'includeAnalysis' => $request->boolean('include_analysis'),
            'isVersionSelected' => $explicitVersionPublicId !== null || $request->has('version'),
        ]);
    }

    /**
     * The "current formula" is whatever the user is editing right now:
     * the most recent version, whether it is a current version still being polished
     * or the latest published snapshot. The 3 older published versions
     * stay in the database as the recovery history.
     */
    private function accessibleCurrentVersion(
        string $recipePublicId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        $recipe = $this->accessibleRecipe($recipePublicId, $currentAppUserResolver);
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->orderByDesc('version_number')
            ->firstOrFail();

        return [$recipe, $version];
    }

    private function exportFilename(Recipe $recipe, string $extension): string
    {
        $slug = Str::slug($recipe->name);

        return ($slug !== '' ? $slug : 'recipe').'.'.$extension;
    }

    /**
     * @param  array<string, mixed>  $viewData
     * @return array<string, mixed>
     */
    private function productionPreview(
        Recipe $recipe,
        RecipeVersion $version,
        User $user,
        array $viewData,
        RecipeVersionCostPreviewBuilder $recipeVersionCostPreviewBuilder,
    ): array {
        $batchContext = is_array($viewData['batchContext'] ?? null) ? $viewData['batchContext'] : [];
        $basisValue = $this->positiveFloat($batchContext['batch_basis'] ?? null)
            ?? $this->positiveFloat($viewData['selectedOilWeight'] ?? null)
            ?? 0.0;
        $requestedUnitsProduced = $this->positiveInt($batchContext['units_produced'] ?? null);
        $basisLabel = $this->productionBatchBasisLabel($recipe);
        $basisUnit = $version->batch_unit ?: 'g';
        $existingCosting = RecipeVersionCosting::query()
            ->with(['items.ingredient', 'packagingItems.packagingItem'])
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->first();

        $unitsProduced = $requestedUnitsProduced
            ?? ($existingCosting instanceof RecipeVersionCosting ? $this->positiveInt($existingCosting->units_produced) : null);

        $batchBasis = [
            'batch_basis_label' => $basisLabel,
            'batch_basis_value' => $basisValue,
            'batch_basis_unit' => $basisUnit,
            'units_produced' => $unitsProduced,
        ];

        if ($existingCosting instanceof RecipeVersionCosting) {
            return [
                ...$recipeVersionCostPreviewBuilder->buildFromCosting(
                    recipe: $recipe,
                    version: $version,
                    costing: $existingCosting,
                    batchBasisValue: $basisValue,
                    unitsProduced: $unitsProduced,
                ),
                ...$batchBasis,
            ];
        }

        return [
            'currency' => $user->defaultCurrency(),
            'ingredient_rows' => [],
            'packaging_rows' => [],
            'ingredient_total' => 0.0,
            'packaging_total' => 0.0,
            'total_cost' => 0.0,
            'cost_per_unit' => null,
            'has_unpriced_rows' => true,
            ...$batchBasis,
        ];
    }

    private function productionBatchBasisLabel(Recipe $recipe): string
    {
        $recipe->loadMissing('productFamily');

        return $recipe->productFamily?->calculation_basis === 'total_formula'
            ? 'Total batch quantity'
            : 'Oil quantity';
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (float) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
