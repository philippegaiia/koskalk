<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\CurrentAppUserResolver;
use App\Services\MediaStorage;
use App\Services\RecipeCsvExporter;
use App\Services\RecipeExportDataBuilder;
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

        if ($productFamily->slug === 'cosmetic' && $productTypeSlug === '') {
            return view('recipes.product-type-selector', [
                'productFamily' => $productFamily,
                'productTypes' => ProductType::query()
                    ->whereBelongsTo($productFamily)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get(),
            ]);
        }

        $productType = $productTypeSlug !== ''
            ? ProductType::query()
                ->whereBelongsTo($productFamily)
                ->where('slug', $productTypeSlug)
                ->where(function ($query): void {
                    $query->where('is_active', true);
                })
                ->firstOrFail()
            : null;

        return view('recipes.workbench', [
            'productFamily' => $productFamily,
            'productType' => $productType,
        ]);
    }

    public function edit(int $recipe, CurrentAppUserResolver $currentAppUserResolver): View
    {
        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);

        return view('recipes.workbench', [
            'recipe' => $recipe,
        ]);
    }

    public function saved(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);
        $viewData = $recipeVersionViewDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());

        return view('recipes.version', $viewData);
    }

    public function duplicate(
        int $recipe,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = $this->accessibleRecipe($recipe, $currentAppUserResolver);
        $duplicateDraft = $recipeWorkbenchService->duplicateRecipe($user, $recipe);

        return redirect()
            ->route('recipes.edit', $duplicateDraft->recipe_id)
            ->with('status', 'Formula duplicated into a new draft.');
    }

    public function editSavedFormulaInDraft(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);

        if (
            $recipeWorkbenchService->draftWouldBeReplacedByVersion($recipe, $savedFormula->id)
            && ! $request->boolean('confirm_replace_draft')
        ) {
            return redirect()
                ->route('recipes.saved', $recipe->id)
                ->with('draftReplaceConfirmation', [
                    'title' => 'Replace the current draft?',
                    'body' => 'The current draft differs from the official recipe. Confirming will replace the draft with the current official recipe data.',
                    'action_label' => 'Replace draft with official recipe',
                    'action_url' => route('recipes.saved.edit-in-draft', $recipe->id),
                ]);
        }

        $recipeWorkbenchService->useVersionAsDraft($user, $recipe, $savedFormula->id);

        return redirect()
            ->route('recipes.edit', $recipe->id)
            ->with('status', 'Draft refreshed from the current official recipe.');
    }

    public function restoreSavedFormula(
        int $recipe,
        int $version,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $version] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        $recipeWorkbenchService->restoreSavedFormula($user, $recipe, $version->id);

        return redirect()
            ->route('recipes.saved', $recipe->id)
            ->with('status', 'Official recipe restored from the selected recovery snapshot.');
    }

    public function version(
        int $recipe,
        int $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        return $this->saved($recipe->id, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedRecipe(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSavedProductionSheet($recipe, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedProductionSheet(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);

        return view('recipes.print', [
            ...$recipeVersionViewDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query()),
            'printMode' => 'production',
        ]);
    }

    public function printRecipe(
        int $recipe,
        int $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        return $this->printSavedRecipe($recipe->id, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedDetails(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        return $this->printSavedTechnicalSheet($recipe, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function printSavedTechnicalSheet(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);

        return view('recipes.print', [
            ...$recipeVersionViewDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query()),
            'printMode' => 'technical',
        ]);
    }

    public function printSavedCostingSheet(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);

        return view('recipes.print', [
            ...$recipeVersionViewDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query()),
            'printMode' => 'costing',
        ]);
    }

    public function exportSavedWorkbook(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeExportDataBuilder $recipeExportDataBuilder,
        RecipeWorkbookExporter $recipeWorkbookExporter,
    ): StreamedResponse {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);
        $exportData = $recipeExportDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());
        $filename = $this->exportFilename($recipe, 'xlsx');

        return response()->streamDownload(
            fn (): int => print $recipeWorkbookExporter->export($exportData),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function exportSavedFormulaCsv(
        int $recipe,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeExportDataBuilder $recipeExportDataBuilder,
        RecipeCsvExporter $recipeCsvExporter,
    ): StreamedResponse {
        [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);
        $exportData = $recipeExportDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());
        $filename = $this->exportFilename($recipe, 'csv');

        return response()->streamDownload(
            fn (): int => print $recipeCsvExporter->export($exportData),
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function printDetails(
        int $recipe,
        int $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    ): View {
        [$recipe] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        return $this->printSavedDetails($recipe->id, $request, $currentAppUserResolver, $recipeVersionViewDataBuilder);
    }

    public function useVersionAsDraft(
        int $recipe,
        int $version,
        Request $request,
        CurrentAppUserResolver $currentAppUserResolver,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);
        [$recipe, $version] = $this->accessibleSavedVersion($recipe, $version, $currentAppUserResolver);

        if (
            $recipeWorkbenchService->draftWouldBeReplacedByVersion($recipe, $version->id)
            && ! $request->boolean('confirm_replace_draft')
        ) {
            return redirect()
                ->route('recipes.saved', $recipe->id)
                ->with('draftReplaceConfirmation', [
                    'title' => 'Replace the current draft?',
                    'body' => 'The current draft differs from this recovery snapshot. Confirming will replace the draft with the selected saved state.',
                    'action_label' => 'Replace draft',
                    'action_url' => route('recipes.use-version-as-draft', ['recipe' => $recipe->id, 'version' => $version->id]),
                ]);
        }

        $recipeWorkbenchService->useVersionAsDraft($user, $recipe, $version->id);

        return redirect()
            ->route('recipes.edit', $recipe->id)
            ->with('status', 'Working draft replaced with the selected recovery snapshot.');
    }

    public function destroy(
        int $recipe,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipe);

        $this->authorize('delete', $recipe);

        abort_unless($request->string('confirm_name')->toString() === $recipe->name, 403, 'Confirmation name does not match.');

        $mediaPaths = $recipe->mediaPaths();

        DB::transaction(function () use ($recipe): void {
            $recipe->delete();
        });

        $mediaPaths->each(function (string $path): void {
            MediaStorage::deletePublicPath($path);
        });

        return redirect()
            ->route('recipes.index')
            ->with('status', 'Recipe deleted.');
    }

    public function destroyVersion(
        int $recipe,
        int $version,
        CurrentAppUserResolver $currentAppUserResolver,
        Request $request,
        RecipeVersionDeletionService $recipeVersionDeletionService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipe);
        $version = RecipeVersion::withoutGlobalScopes()->findOrFail($version);

        abort_unless($version->recipe_id === $recipe->id, 404);

        $this->authorize('delete', $version);

        if (! $version->is_draft) {
            abort_unless($request->string('confirm_name')->toString() === $version->name, 403, 'Confirmation name does not match.');
        }

        $deletion = $recipeVersionDeletionService->delete($recipe, $version);
        $status = $deletion['last_published_deleted']
            ? 'Last published version deleted. Recipe has no published versions.'
            : 'Version deleted.';

        return redirect()
            ->route('recipes.index')
            ->with('status', $status);
    }

    private function accessibleRecipe(int $recipeId, CurrentAppUserResolver $currentAppUserResolver): Recipe
    {
        $user = $currentAppUserResolver->resolve();
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($recipeId);

        abort_unless($user !== null && $recipe->isAccessibleBy($user), 404);

        return $recipe;
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleSavedVersion(
        int $recipeId,
        int $versionId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        $recipe = $this->accessibleRecipe($recipeId, $currentAppUserResolver);
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->whereKey($versionId)
            ->firstOrFail();

        return [$recipe, $version];
    }

    /**
     * @return array{0: Recipe, 1: RecipeVersion}
     */
    private function accessibleCurrentSavedFormula(
        int $recipeId,
        CurrentAppUserResolver $currentAppUserResolver,
    ): array {
        $recipe = $this->accessibleRecipe($recipeId, $currentAppUserResolver);
        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->orderByDesc('version_number')
            ->firstOrFail();

        return [$recipe, $version];
    }

    private function exportFilename(Recipe $recipe, string $extension): string
    {
        $slug = Str::slug($recipe->name);

        return ($slug !== '' ? $slug : 'recipe').'.'.$extension;
    }
}
