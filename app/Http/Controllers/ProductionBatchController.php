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

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->orderByDesc('version_number')
            ->firstOrFail();

        $productionBatch = $productionSnapshotService->record($recipe, $version, $user, $request->validated());

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
}
