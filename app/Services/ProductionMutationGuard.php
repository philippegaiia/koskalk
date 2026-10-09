<?php

namespace App\Services;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProductionMutationGuard
{
    public function __construct(private readonly ProductionEditingService $editing) {}

    /** @param list<int> $productionIds */
    public function run(User $actor, array $productionIds, ?ProductionEditingContext $context, Closure $callback): mixed
    {
        if ($context === null || collect($productionIds)->unique()->sort()->values()->all() !== collect(array_keys($context->expectedRevisions))->sort()->values()->all()) {
            throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.selection')]);
        }
        [$value, $acknowledged] = $this->editing->withLocked($actor, $context->workspaceId, $productionIds,
            function (User $freshActor, Workspace $workspace, Collection $productions) use ($context, $callback): array {
                foreach ($productions as $production) {
                    $this->editing->assertRevision($production, $context->expectedRevisions[$production->id]);
                    if (! $context->temporary) {
                        $this->editing->assertLease($production, $freshActor, $context->token);
                    } elseif ($this->editing->isActivelyReserved($production)) {
                        throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.busy')]);
                    }
                }
                if ($context->temporary) {
                    $state = $this->editing->acquire($freshActor, $workspace->id, $context->expectedRevisions, $context->token);
                    if ($state['status'] !== 'acquired') {
                        throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.busy')]);
                    }
                }
                $leaseDeadlines = DB::table('production_edit_leases')->whereIn('production_run_id', $productions->keys())->pluck('expires_at', 'production_run_id');
                $result = ProductionMutationScope::within($freshActor, $workspace, $productions,
                    fn (ProductionMutationScope $scope): ProductionMutationResult => $callback($freshActor, $workspace, $productions, $scope));
                if (collect($result->changedProductionIds)->diff($productions->keys())->isNotEmpty()) {
                    throw new LogicException('A production command changed an unguarded production.');
                }
                $acknowledged = [];
                foreach ($productions as $production) {
                    $current = ProductionRun::query()->whereKey($production->id)->first();
                    if ($current === null) {
                        if (Carbon::parse($leaseDeadlines[$production->id])->lessThanOrEqualTo(now())) {
                            throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.lease')]);
                        }
                        $acknowledged[$production->id] = null;

                        continue;
                    }
                    $this->editing->assertLease($current, $freshActor, $context->token);
                    if ($current->edit_revision !== $context->expectedRevisions[$current->id]) {
                        throw new LogicException('A nested production helper advanced the root command revision.');
                    }
                    if (in_array($production->id, $result->changedProductionIds, true)) {
                        $current->increment('edit_revision');
                    }
                    $acknowledged[$production->id] = $current->edit_revision;
                }
                if ($context->temporary) {
                    $this->editing->release($freshActor, $workspace->id, $productions->keys()->all(), $context->token);
                }
                if ($result->value instanceof ProductionRun && ($acknowledged[$result->value->id] ?? null) !== null) {
                    $result->value->edit_revision = $acknowledged[$result->value->id];
                    $result->value->syncOriginalAttribute('edit_revision');
                }

                return [$result->value, $acknowledged];
            });
        $context->acknowledge($acknowledged);

        return $value;
    }
}
