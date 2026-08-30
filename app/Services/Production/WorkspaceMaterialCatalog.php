<?php

namespace App\Services\Production;

use App\Enums\ProductionRunStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\SupplierListing;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves the materials a workspace tracks.
 *
 * A material belongs to a workspace when planned production asks for it or when
 * the workspace holds at least one supplier listing for it. Demand on its own
 * is not enough: a listed material that no run currently asks for is still one
 * the workspace can buy and stock, and deriving the set from requirements alone
 * hid most of a workspace's catalogue from the inventory view.
 *
 * Listings count whatever their active flag says. Deactivating a listing is how
 * the purchasing screens retire one while keeping the purchasing record, and
 * stock can still be held against it, so a deactivated listing is evidence the
 * workspace uses the material rather than evidence it does not.
 */
class WorkspaceMaterialCatalog
{
    /**
     * @return Collection<int, array{key: string, subject: Ingredient|PackagingItem, has_demand: bool, has_listing: bool}>
     */
    public function materials(Workspace $workspace): Collection
    {
        $demandedKeys = $this->demandedKeys($workspace);
        $listedKeys = $this->listedKeys($workspace);

        $ingredientIds = [];
        $packagingItemIds = [];

        foreach ([...$demandedKeys, ...$listedKeys] as $key) {
            if (str_starts_with($key, 'ingredient:')) {
                $ingredientIds[] = (int) substr($key, strlen('ingredient:'));
            } elseif (str_starts_with($key, 'packaging:')) {
                $packagingItemIds[] = (int) substr($key, strlen('packaging:'));
            }
        }

        if ($ingredientIds === [] && $packagingItemIds === []) {
            return collect();
        }

        $ingredients = Ingredient::query()
            ->whereIn('id', array_values(array_unique($ingredientIds)))
            ->with('translations')
            ->get()
            ->keyBy(fn (Ingredient $ingredient): string => 'ingredient:'.$ingredient->id);

        // Packaging is workspace-owned, so it is scoped explicitly. Ingredients
        // are shared across workspaces and have no global scope to lean on.
        $packagingItems = PackagingItem::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', array_values(array_unique($packagingItemIds)))
            ->get()
            ->keyBy(fn (PackagingItem $item): string => 'packaging:'.$item->id);

        return collect([...$demandedKeys, ...$listedKeys])
            ->unique()
            ->values()
            ->map(function (string $key) use ($ingredients, $packagingItems, $demandedKeys, $listedKeys): ?array {
                $subject = $ingredients->get($key) ?? $packagingItems->get($key);

                if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
                    return null;
                }

                return [
                    'key' => $key,
                    'subject' => $subject,
                    'has_demand' => in_array($key, $demandedKeys, true),
                    'has_listing' => in_array($key, $listedKeys, true),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Subjects a planned run asks for, in the form "ingredient:{id}" or
     * "packaging:{id}".
     *
     * @return list<string>
     */
    private function demandedKeys(Workspace $workspace): array
    {
        return [
            ...$this->prefixKeys(
                ProductionRequirement::query()
                    ->whereHas('productionRun', fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('status', [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved]))
                    ->whereNotNull('ingredient_id')
                    ->distinct()
                    ->pluck('ingredient_id')
                    ->all(),
                'ingredient',
            ),
            ...$this->prefixKeys(
                ProductionRequirement::query()
                    ->whereHas('productionRun', fn (Builder $query): Builder => $query
                        ->where('workspace_id', $workspace->id)
                        ->whereIn('status', [ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved]))
                    ->whereNotNull('packaging_item_id')
                    ->distinct()
                    ->pluck('packaging_item_id')
                    ->all(),
                'packaging',
            ),
        ];
    }

    /**
     * Subjects the workspace holds at least one supplier listing for, active or
     * not, in the form "ingredient:{id}" or "packaging:{id}".
     *
     * @return list<string>
     */
    private function listedKeys(Workspace $workspace): array
    {
        $listings = fn (string $column): array => SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->all();

        return [
            ...$this->prefixKeys($listings('ingredient_id'), 'ingredient'),
            ...$this->prefixKeys($listings('packaging_item_id'), 'packaging'),
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function prefixKeys(array $ids, string $prefix): array
    {
        return collect($ids)
            ->map(fn (mixed $id): string => $prefix.':'.$id)
            ->values()
            ->all();
    }
}
