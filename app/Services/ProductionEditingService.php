<?php

namespace App\Services;

use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class ProductionEditingService
{
    public const int LeaseSeconds = 90;

    public const int MaximumGroupSize = 100;

    public function __construct(private readonly WorkspaceWriteLock $workspaceLock, private readonly ProductionBenchAccess $access) {}

    /** @param list<int> $productionIds
     * @return array<string, mixed>
     */
    public function status(User $actor, int $workspaceId, array $productionIds, ?string $token = null): array
    {
        $ids = $this->ids($productionIds);
        if ($token !== null) {
            $this->validateToken($token);
        }
        $freshActor = User::withoutGlobalScopes()->findOrFail($actor->id);
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($workspaceId);
        $this->access->assertReadable($freshActor, $workspace);
        $productions = ProductionRun::query()->where('workspace_id', $workspaceId)->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');

        return $this->state($freshActor, $workspace, $productions, $token, $productions->count() === count($ids) ? null : 'unavailable');
    }

    /** @param array<int, int> $expectedRevisions
     * @return array<string, mixed>
     */
    public function acquire(User $actor, int $workspaceId, array $expectedRevisions, string $token): array
    {
        $this->validateRevisions($expectedRevisions);
        $this->validateToken($token);

        return $this->withLocked($actor, $workspaceId, array_keys($expectedRevisions), function (User $freshActor, Workspace $workspace, Collection $productions) use ($expectedRevisions, $token): array {
            $obstacle = $this->obstacle($productions, $freshActor, $expectedRevisions, $token);
            if ($obstacle !== null) {
                return $this->state($freshActor, $workspace, $productions, $token, $obstacle);
            }
            foreach ($productions as $production) {
                $this->writeLease($production, $freshActor, $token);
            }

            return $this->state($freshActor, $workspace, $productions, $token);
        });
    }

    /** @param array<int, int> $expectedRevisions
     * @return array<string, mixed>
     */
    public function heartbeat(User $actor, int $workspaceId, array $expectedRevisions, string $token): array
    {
        $this->validateRevisions($expectedRevisions);
        $this->validateToken($token);

        return $this->withLocked($actor, $workspaceId, array_keys($expectedRevisions), function (User $freshActor, Workspace $workspace, Collection $productions) use ($expectedRevisions, $token): array {
            foreach ($productions as $production) {
                $this->assertRevision($production, $expectedRevisions[$production->id]);
                $this->assertLease($production, $freshActor, $token);
            }
            foreach ($productions as $production) {
                $this->writeLease($production, $freshActor, $token);
            }

            return $this->state($freshActor, $workspace, $productions, $token);
        });
    }

    /** @param array<int, int> $expectedRevisions
     * @return array<string, mixed>
     */
    public function takeover(User $actor, int $workspaceId, array $expectedRevisions, string $token, string $reason): array
    {
        $this->validateRevisions($expectedRevisions);
        $this->validateToken($token);
        $reason = trim($reason);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']], [
            'reason.required' => __('production_bench.editing.validation.reason'),
            'reason.max' => __('production_bench.editing.validation.reason'),
        ])->validate();

        return $this->withLocked($actor, $workspaceId, array_keys($expectedRevisions), function (User $freshActor, Workspace $workspace, Collection $productions) use ($expectedRevisions, $token, $reason): array {
            $this->access->assertCanConfigure($freshActor, $workspace);
            foreach ($productions as $production) {
                if ($production->edit_revision !== $expectedRevisions[$production->id]) {
                    return $this->state($freshActor, $workspace, $productions, $token, 'stale');
                }
            }
            foreach ($productions as $production) {
                $previous = $this->lease($production);
                DB::table('production_edit_takeovers')->insert([
                    'production_run_id' => $production->id,
                    'actor_user_id' => $freshActor->id,
                    'previous_user_id' => $previous?->user_id,
                    'actor_name' => $freshActor->name,
                    'previous_holder_name' => $previous?->holder_name,
                    'reason' => $reason, 'created_at' => now(),
                ]);
                $this->writeLease($production, $freshActor, $token);
            }

            return $this->state($freshActor, $workspace, $productions, $token);
        });
    }

    /** @param list<int> $productionIds */
    public function release(User $actor, int $workspaceId, array $productionIds, string $token): void
    {
        $this->validateToken($token);
        $ids = $this->ids($productionIds);
        $this->withLocked($actor, $workspaceId, $ids, function (User $freshActor, Workspace $workspace, Collection $productions) use ($token): void {
            DB::table('production_edit_leases')->whereIn('production_run_id', $productions->keys())
                ->where('user_id', $freshActor->id)->where('token_hash', hash('sha256', $token))->delete();
        }, writable: false, allowMissing: true);
    }

    /** @param list<int> $productionIds */
    public function withLocked(User $actor, int $workspaceId, array $productionIds, Closure $callback, bool $writable = true, bool $allowMissing = false): mixed
    {
        $ids = $this->ids($productionIds);

        return DB::transaction(function () use ($actor, $workspaceId, $ids, $callback, $writable, $allowMissing): mixed {
            $freshActor = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($actor->id);
            $workspace = $this->workspaceLock->acquire($workspaceId);
            WorkspaceMember::withoutGlobalScopes()->where('user_id', $freshActor->id)->where('workspace_id', $workspaceId)->lockForUpdate()->get();
            if ($writable) {
                $this->access->assertWritable($freshActor, $workspace);
            } else {
                $this->access->assertReadable($freshActor, $workspace);
            }
            $productions = ProductionRun::query()->where('workspace_id', $workspaceId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (! $allowMissing && $productions->count() !== count($ids)) {
                $this->fail('unavailable');
            }

            return $callback($freshActor, $workspace, $productions);
        }, attempts: 5);
    }

    public function assertLease(ProductionRun $production, User $actor, string $token): void
    {
        $this->validateToken($token);
        $lease = $this->lease($production);
        if (! $this->active($lease) || ! $this->owns($lease, $actor, $token)) {
            $this->fail('lease');
        }
    }

    public function assertRevision(ProductionRun $production, int $expectedRevision): void
    {
        if ($production->edit_revision !== $expectedRevision) {
            $this->fail('revision');
        }
    }

    public function isActivelyReserved(ProductionRun $production): bool
    {
        return $this->active($this->lease($production));
    }

    /** @param list<int> $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        if (count($ids) < 1 || count($ids) > self::MaximumGroupSize || collect($ids)->contains(fn (mixed $id): bool => ! is_int($id) || $id < 1)) {
            $this->fail('selection');
        }

        return collect($ids)->unique()->sort()->values()->all();
    }

    /** @param array<int, int> $revisions */
    private function validateRevisions(array $revisions): void
    {
        $this->ids(array_keys($revisions));
        if (collect($revisions)->contains(fn (mixed $revision): bool => ! is_int($revision) || $revision < 0)) {
            $this->fail('revision');
        }
    }

    private function validateToken(string $token): void
    {
        if (! Str::isUuid($token)) {
            $this->fail('token');
        }
    }

    /** @param Collection<int, ProductionRun> $productions
     * @param  array<int, int>  $revisions
     */
    private function obstacle(Collection $productions, User $actor, array $revisions, string $token): ?string
    {
        foreach ($productions as $production) {
            if ($production->edit_revision !== $revisions[$production->id]) {
                return 'stale';
            }
            $lease = $this->lease($production);
            if ($this->active($lease) && ! $this->owns($lease, $actor, $token)) {
                return 'blocked';
            }
        }

        return null;
    }

    /** @param Collection<int, ProductionRun> $productions
     * @return array<string, mixed>
     */
    private function state(User $actor, Workspace $workspace, Collection $productions, ?string $token, ?string $override = null): array
    {
        $states = $productions->map(function (ProductionRun $production) use ($actor, $token): array {
            $lease = $this->lease($production);
            $active = $this->active($lease);

            return [
                'public_id' => $production->public_id,
                'revision' => $production->edit_revision,
                'status' => ! $active ? 'available' : ($token !== null && $this->owns($lease, $actor, $token) ? 'acquired' : 'blocked'),
                'holder_name' => $active ? $lease->holder_name : null,
                'expires_at' => $active ? Carbon::parse($lease->expires_at)->toIso8601String() : null,
                'label' => $production->recipe_name_snapshot,
                'reference' => $production->batch_number ?? $production->planning_batch_number,
            ];
        })->all();
        $statuses = collect($states)->pluck('status');

        return [
            'status' => $override ?? ($statuses->contains('blocked') ? 'blocked' : ($statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === 'acquired') ? 'acquired' : 'available')),
            'can_edit' => $this->access->canWrite($actor, $workspace),
            'can_take_over' => $this->access->canConfigure($actor, $workspace),
            'release_url' => route('production-bench.production.editing.release'),
            'productions' => $states,
        ];
    }

    private function lease(ProductionRun $production): ?stdClass
    {
        return DB::table('production_edit_leases')->where('production_run_id', $production->id)->first();
    }

    private function active(?stdClass $lease): bool
    {
        return $lease !== null && Carbon::parse($lease->expires_at)->isFuture();
    }

    private function owns(?stdClass $lease, User $actor, string $token): bool
    {
        return $lease !== null && (int) $lease->user_id === $actor->id && hash_equals($lease->token_hash, hash('sha256', $token));
    }

    private function writeLease(ProductionRun $production, User $actor, string $token): void
    {
        $previous = $this->lease($production);
        DB::table('production_edit_leases')->updateOrInsert(['production_run_id' => $production->id], [
            'user_id' => $actor->id, 'token_hash' => hash('sha256', $token), 'holder_name' => $actor->name,
            'expires_at' => now()->addSeconds(self::LeaseSeconds),
            'created_at' => $previous?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['production_editing' => __('production_bench.editing.validation.'.$key)]);
    }
}
