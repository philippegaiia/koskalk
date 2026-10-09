<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FormulaShareTransaction
{
    public function __construct(private readonly WorkspaceWriteLock $workspaceLock) {}

    /** @param list<int> $workspaceIds @param Closure(User, array<int, Workspace>): mixed $callback */
    public function run(User $actor, array $workspaceIds, Closure $callback, bool $write = true): mixed
    {
        $outermost = DB::transactionLevel() === 0;
        $actorId = $actor->id;
        sort($workspaceIds, SORT_NUMERIC);

        return DB::transaction(function () use ($outermost, $actorId, $workspaceIds, $callback, $write): mixed {
            if (DB::connection()->getDriverName() === 'pgsql') {
                if ($outermost) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                } elseif (! in_array(DB::selectOne('SHOW transaction_isolation')->transaction_isolation, ['repeatable read', 'serializable'], true)) {
                    throw new RuntimeException('Formula sharing inside a transaction requires repeatable-read or serializable isolation.');
                }
            }
            $freshActor = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($actorId);
            $workspaces = [];
            foreach (array_unique($workspaceIds) as $id) {
                $workspaces[$id] = $write ? $this->workspaceLock->acquire($id) : Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
            }
            WorkspaceMember::withoutGlobalScopes()->where('user_id', $actorId)
                ->whereIn('workspace_id', $workspaceIds)->orderBy('workspace_id')->lockForUpdate()->get();

            return $callback($freshActor, $workspaces);
        }, attempts: 5);
    }
}
