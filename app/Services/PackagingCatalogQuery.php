<?php

namespace App\Services;

use App\Models\CurrentMaterialPrice;
use App\Models\PackagingItem;
use Illuminate\Database\Eloquent\Builder;

final class PackagingCatalogQuery
{
    /** @param array<string, mixed> $filters */
    public function query(int $workspaceId, array $filters = []): Builder
    {
        $search = (string) ($filters['search'] ?? '');
        $sortField = in_array($filters['sortField'] ?? '', ['name', 'material_code', 'unit_cost'], true)
            ? $filters['sortField'] : 'name';
        $direction = ($filters['sortDirection'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = PackagingItem::query()->where('workspace_id', $workspaceId)->with('currentPrice')
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $where): Builder => $where
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('material_code', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%')));

        if ($sortField === 'unit_cost') {
            return $query->orderBy(
                CurrentMaterialPrice::query()->select('price_per_canonical_unit')
                    ->whereColumn('packaging_item_id', 'packaging_items.id'),
                $direction,
            )->orderBy('id');
        }

        return $query->orderBy($sortField, $direction)->orderBy('id', $direction);
    }
}
