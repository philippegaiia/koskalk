<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductionBatchRequest;
use App\Http\Requests\UpdateProductionBatchAnnotationsRequest;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\CurrentAppUserResolver;
use App\Services\ProductionSnapshotService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ProductionBatchController extends Controller
{
    public function store(
        int $recipe,
        StoreProductionBatchRequest $request,
        CurrentAppUserResolver $currentAppUserResolver,
        ProductionSnapshotService $productionSnapshotService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()
            ->with('productFamily')
            ->findOrFail($recipe);

        abort_unless($recipe->isAccessibleBy($user), 404);

        $this->authorize('update', $recipe);

        $validated = $request->validated();

        $version = RecipeVersion::withoutGlobalScopes()
            ->whereKey((int) $validated['recipe_version_id'])
            ->where('recipe_id', $recipe->id)
            ->firstOrFail();

        $productionBatch = $productionSnapshotService->record($recipe, $version, $user, $validated);

        return redirect()
            ->route('production-batches.show', $productionBatch)
            ->with('status', 'Production recorded.');
    }

    public function show(ProductionBatch $productionBatch): View
    {
        $this->authorize('view', $productionBatch);

        $productionBatch->load(['recipe', 'recipeVersion', 'ingredients', 'packagingItems']);

        return view('production-batches.show', [
            'productionBatch' => $productionBatch,
        ]);
    }

    public function update(
        ProductionBatch $productionBatch,
        UpdateProductionBatchAnnotationsRequest $request,
    ): RedirectResponse {
        $this->authorize('update', $productionBatch);

        $validated = $request->validated();
        $updates = [];

        foreach (['production_batch_number', 'production_notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if ($updates !== []) {
            $productionBatch->update($updates);
        }

        $ingredientLotNumbers = $validated['ingredient_lot_numbers'] ?? [];

        if (is_array($ingredientLotNumbers)) {
            $productionBatch->ingredients()->get()->each(function ($ingredient) use ($ingredientLotNumbers): void {
                $lotKey = implode(':', [
                    $ingredient->ingredient_id,
                    $ingredient->phase_key,
                    $ingredient->position,
                ]);

                if (array_key_exists($lotKey, $ingredientLotNumbers)) {
                    $lotNumber = trim((string) $ingredientLotNumbers[$lotKey]);

                    $ingredient->update([
                        'ingredient_lot_number' => $lotNumber === '' ? null : $lotNumber,
                    ]);
                }
            });
        }

        return redirect()
            ->route('production-batches.show', $productionBatch)
            ->with('status', 'Production notes updated.');
    }

    public function print(ProductionBatch $productionBatch): View
    {
        $this->authorize('view', $productionBatch);

        $productionBatch->load(['ingredients', 'packagingItems']);

        return view('production-batches.print', [
            'productionBatch' => $productionBatch,
        ]);
    }

    public function destroy(ProductionBatch $productionBatch): RedirectResponse
    {
        $this->authorize('delete', $productionBatch);

        $recipeId = $productionBatch->recipe_id;
        $batchLabel = $productionBatch->production_batch_number ?: $productionBatch->recipe_name;

        $productionBatch->delete();

        $redirect = $recipeId !== null
            ? redirect()->route('recipes.saved', $recipeId)
            : redirect()->route('recipes.index');

        return $redirect->with('status', "Production batch {$batchLabel} deleted.");
    }
}
