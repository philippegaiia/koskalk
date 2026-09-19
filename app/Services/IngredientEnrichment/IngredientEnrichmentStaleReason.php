<?php

namespace App\Services\IngredientEnrichment;

use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatchItem;

/**
 * Explain why an approved item can no longer be applied.
 *
 * Apply refuses when the ingredient moved on after the request captured its snapshot.
 * The status alone says nothing about what moved, so the changed sections are carried
 * into the item's failure message where the admin UI already renders it.
 */
final class IngredientEnrichmentStaleReason
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshots,
    ) {}

    public function message(IngredientEnrichmentBatchItem $item, ?Ingredient $ingredient = null): string
    {
        $changed = $this->changedPaths($item, $ingredient);

        return $changed === []
            ? __('ingredient_enrichment_admin.validation.stale')
            : __('ingredient_enrichment_admin.validation.stale_changed', ['fields' => implode(', ', $changed)]);
    }

    /** @return list<string> */
    public function changedPaths(IngredientEnrichmentBatchItem $item, ?Ingredient $ingredient = null): array
    {
        $ingredient ??= $item->ingredient;
        $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
        $previous = $snapshot['current'] ?? null;

        if (! $ingredient instanceof Ingredient || ! is_array($previous)) {
            return [];
        }

        return $this->snapshots->changedPaths($previous, $this->snapshots->snapshot($ingredient));
    }
}
