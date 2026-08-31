<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\ProductionBenchAccess;

/**
 * The purchasing catalogue half of a material: every supplier listing that can
 * replenish the subject, whether or not the workspace currently holds stock of
 * it. It is deliberately separate from the stock queries because a listing-only
 * material has no lots at all and must still be usable.
 */
final class WorkspaceMaterialSupplierListingsQuery
{
    private const array ALLOWED_PER_PAGE = [10, 25, 50];

    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function paginate(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        int $perPage = 10,
        string $pageName = 'supplier-listings',
        ?int $page = null,
    ): LengthAwarePaginator {
        $this->access->assertReadable($actor, $workspace);

        $perPage = in_array($perPage, self::ALLOWED_PER_PAGE, true) ? $perPage : 10;

        return SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->with('supplier')
            ->orderByDesc('is_active')
            ->orderBy(
                // Ordered by the supplier's name rather than by supplier_id, so
                // the rows stay alphabetical for a human. The suppliers table is
                // read through the query builder to keep model scopes out of an
                // ordering expression.
                DB::table('suppliers')
                    ->select('name')
                    ->whereColumn('suppliers.id', 'supplier_listings.supplier_id')
                    ->limit(1),
            )
            ->orderBy('id')
            ->paginate($perPage, ['*'], $pageName, $page);
    }
}
