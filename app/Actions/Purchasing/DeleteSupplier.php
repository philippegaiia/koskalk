<?php

namespace App\Actions\Purchasing;

use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class DeleteSupplier
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, Supplier $supplier): bool
    {
        $this->access->assertCanConfigure($actor, $workspace);

        return DB::transaction(function () use ($actor, $supplier, $workspace): bool {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertCanConfigure($actor, $lockedWorkspace);
            $lockedSupplier = Supplier::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->findOrFail($supplier->id);

            if ($lockedSupplier->purchaseOrders()->exists() || $lockedSupplier->listings()->exists()) {
                $lockedSupplier->update(['is_active' => false]);

                return false;
            }

            $lockedSupplier->delete();

            return true;
        }, attempts: 5);
    }
}
