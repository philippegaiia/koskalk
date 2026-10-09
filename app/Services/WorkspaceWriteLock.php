<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceWriteLock
{
    /** Advance the MVCC row version so a stale PostgreSQL repeatable-read writer retries. */
    public function acquire(int $workspaceId): Workspace
    {
        $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspaceId);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::table('workspaces')->where('id', $workspaceId)->update(['updated_at' => DB::raw('updated_at')]);
        }

        return $workspace;
    }
}
