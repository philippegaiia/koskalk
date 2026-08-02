<?php

namespace App\Actions\Purchasing;

use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class DeleteSupplierListing
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, SupplierListing $listing): bool
    {
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($listing, $workspace): bool {
            $lockedListing = SupplierListing::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->findOrFail($listing->id);

            if ($lockedListing->purchaseOrderLines()->exists() || $lockedListing->stockLots()->exists()) {
                $lockedListing->update(['is_active' => false]);

                return false;
            }

            $lockedListing->delete();

            return true;
        }, attempts: 5);
    }
}
