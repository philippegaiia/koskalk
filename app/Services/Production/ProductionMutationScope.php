<?php

namespace App\Services\Production;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ProductionMutationScope
{
    private bool $active = true;

    /** @param list<int> $productionIds */
    private function __construct(
        private readonly int $actorId,
        private readonly int $workspaceId,
        private readonly array $productionIds,
    ) {}

    /** @param Collection<int, ProductionRun> $productions */
    public static function within(User $actor, Workspace $workspace, Collection $productions, Closure $callback): mixed
    {
        if (DB::transactionLevel() < 1 || $productions->isEmpty()
            || $productions->contains(fn (ProductionRun $run): bool => (int) $run->workspace_id !== $workspace->id)) {
            throw new LogicException('Production scope requires an enclosing transaction and one workspace.');
        }
        $scope = new self($actor->id, $workspace->id, $productions->pluck('id')->all());
        try {
            return $callback($scope);
        } finally {
            $scope->active = false;
        }
    }

    public static function withinCreated(User $actor, Workspace $workspace, ProductionRun $production, Closure $callback): mixed
    {
        if (! $production->wasRecentlyCreated) {
            throw new LogicException('Creation scope cannot initialize an existing production.');
        }

        return self::within($actor, $workspace, collect([$production]), $callback);
    }

    public function assertFor(User $actor, ProductionRun $production, Workspace $workspace): void
    {
        if (! $this->active || DB::transactionLevel() < 1 || $actor->id !== $this->actorId
            || $workspace->id !== $this->workspaceId || (int) $production->workspace_id !== $workspace->id
            || ! in_array($production->id, $this->productionIds, true)) {
            throw new LogicException('Production helper has no active command scope for this record.');
        }
    }
}
