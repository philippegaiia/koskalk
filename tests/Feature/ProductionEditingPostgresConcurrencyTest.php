<?php

use App\Actions\Production\CompleteProductionTask;
use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\FormulaShareTransaction;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionEditingService;
use App\Services\ProductionMutationGuard;
use App\Services\WorkspaceWriteLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FormulaSharePostgresDatabase;
use Tests\Support\FormulaSharePostgresRace;
use Tests\Support\ProductionEditingFixture;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! filter_var(env('VERIFY_FORMULA_SHARING_POSTGRES', false), FILTER_VALIDATE_BOOL) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires the explicitly disposable PostgreSQL database and pcntl.');
    }
    FormulaSharePostgresDatabase::reset();
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
    config(['workspaces.collaboration_enabled' => true]);
});

it('serializes two first acquisitions to exactly one whole-production owner', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $winner = (string) Str::uuid();
    $loser = (string) Str::uuid();
    $result = FormulaSharePostgresRace::run(
        fn (): array => app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $loser),
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$run->id => 0], $winner),
        repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value']['status'])->toBe('blocked');
    $this->assertDatabaseCount('production_edit_leases', 1);
    $this->assertDatabaseHas('production_edit_leases', ['production_run_id' => $run->id, 'token_hash' => hash('sha256', $winner)]);
});

it('blocks a temporary task writer behind a persistent editor in a separate session', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $task = ProductionTask::factory()->for($fixture->workspace)->for($run, 'productionRun')->create();
    $temporary = ProductionEditingFixture::command($fixture->owner, $run);
    $result = FormulaSharePostgresRace::run(
        fn () => app(CompleteProductionTask::class)->handle($fixture->owner, $task, editing: $temporary),
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => $fixture->lease($run), repeatableParent: false,
    );
    expect($result['status'])->toBe('validation')->and($task->fresh()->completed_at)->toBeNull()->and($run->fresh()->edit_revision)->toBe(0);
});

it('rejects an old command after a winning commit without acknowledging uncommitted data', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $old = ProductionEditingFixture::command($fixture->owner, $run);
    $winning = ProductionEditingFixture::command($fixture->owner, $run);
    $result = FormulaSharePostgresRace::run(
        function () use ($fixture, $run, $old): array {
            try {
                app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $old, function (User $actor, Workspace $workspace, Collection $runs) use ($run): ProductionMutationResult {
                    $runs[$run->id]->update(['notes' => 'Old draft']);

                    return new ProductionMutationResult(null, [$run->id]);
                });
            } catch (ValidationException) {
                return ['rejected' => true, 'acknowledged' => $old->acknowledgedRevisions()];
            }

            return ['rejected' => false];
        },
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $winning, function (User $actor, Workspace $workspace, Collection $runs) use ($run): ProductionMutationResult {
            $runs[$run->id]->update(['notes' => 'Winning edit']);

            return new ProductionMutationResult(null, [$run->id]);
        }), repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value'])->toBe(['rejected' => true, 'acknowledged' => []])
        ->and($winning->acknowledgedRevisions())->toBe([$run->id => 1])
        ->and($run->fresh()->notes)->toBe('Winning edit')->and($run->fresh()->edit_revision)->toBe(1);
});

it('normalizes opposite group orders and never acquires a partial competing group', function (): void {
    $fixture = ProductionEditingFixture::create();
    [$first, $second, $third] = ProductionRun::factory()->for($fixture->workspace)->count(3)->create()->all();
    $winner = (string) Str::uuid();
    $result = FormulaSharePostgresRace::run(
        fn () => app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$third->id => 0, $second->id => 0], (string) Str::uuid()),
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => app(ProductionEditingService::class)->acquire($fixture->owner, $fixture->workspace->id, [$second->id => 0, $first->id => 0], $winner), repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value']['status'])->toBe('blocked');
    $this->assertDatabaseCount('production_edit_leases', 2);
    $this->assertDatabaseMissing('production_edit_leases', ['production_run_id' => $third->id]);
    $this->assertDatabaseCount('stock_reservations', 0);
});

it('retains the takeover token when an old departure finishes afterward', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $old = $fixture->lease($run);
    $new = (string) Str::uuid();
    $result = FormulaSharePostgresRace::run(
        fn () => app(ProductionEditingService::class)->release($fixture->owner, $fixture->workspace->id, [$run->id], $old->token),
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => app(ProductionEditingService::class)->takeover($fixture->owner, $fixture->workspace->id, [$run->id => 0], $new, 'Handle production now'), repeatableParent: false,
    );
    expect($result['status'])->toBe('ok');
    $this->assertDatabaseHas('production_edit_leases', ['production_run_id' => $run->id, 'token_hash' => hash('sha256', $new)]);
    $this->assertDatabaseCount('production_edit_takeovers', 1);
});

it('rolls back a command whose lease expires before the final check in its worker session', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $editing = $fixture->lease($run);
    $result = FormulaSharePostgresRace::run(
        function () use ($fixture, $run, $editing): array {
            try {
                app(ProductionMutationGuard::class)->run($fixture->owner, [$run->id], $editing, function (User $actor, Workspace $workspace, Collection $runs) use ($run): ProductionMutationResult {
                    $runs[$run->id]->update(['notes' => 'Must roll back']);
                    Carbon::setTestNow(now()->addSeconds(91));

                    return new ProductionMutationResult(null, [$run->id]);
                });
            } catch (ValidationException) {
                return ['rejected' => true, 'acknowledged' => $editing->acknowledgedRevisions()];
            } finally {
                Carbon::setTestNow();
            }

            return ['rejected' => false];
        },
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id),
        fn () => null, repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value'])->toBe(['rejected' => true, 'acknowledged' => []])
        ->and($run->fresh()->notes)->toBe('Original')->and($run->fresh()->edit_revision)->toBe(0);
});

it('rechecks authority after a membership workspace or entitlement writer wins serialization', function (string $change): void {
    $fixture = ProductionEditingFixture::create();
    $editor = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    $member = WorkspaceMember::factory()->for($fixture->workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $other = Workspace::factory()->for($editor, 'owner')->create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Original']);
    $editing = new ProductionEditingContext($fixture->workspace->id, (string) Str::uuid(), [$run->id => 0], temporary: true);
    $result = FormulaSharePostgresRace::run(
        fn () => app(ProductionMutationGuard::class)->run($editor, [$run->id], $editing, function (User $actor, Workspace $workspace, Collection $runs) use ($run): ProductionMutationResult {
            $runs[$run->id]->update(['notes' => 'Unauthorized']);

            return new ProductionMutationResult(null, [$run->id]);
        }),
        fn () => User::withoutGlobalScopes()->lockForUpdate()->findOrFail($editor->id),
        function () use ($change, $fixture, $editor, $member, $other): void {
            app(WorkspaceWriteLock::class)->acquire($fixture->workspace->id);
            match ($change) {
                'downgrade' => $member->update(['role' => WorkspaceMemberRole::Viewer]),
                'remove' => $member->delete(),
                'workspace' => $editor->forceFill(['active_workspace_id' => $other->id])->save(),
                'entitlement' => $fixture->workspace->productionEntitlement()->update(['status' => ProductionBenchEntitlementStatus::Cancelled, 'cancelled_at' => now()]),
            };
        }, repeatableParent: false,
    );
    expect($result['status'])->toBeIn(['authorization', 'validation'])
        ->and($run->fresh()->notes)->toBe('Original')->and($run->fresh()->edit_revision)->toBe(0);
    $this->assertDatabaseCount('production_edit_leases', 0);
})->with(['downgrade', 'remove', 'workspace', 'entitlement']);

it('fences a repeatable-read sharing writer behind a read-committed production group without changing business timestamps', function (): void {
    $fixture = ProductionEditingFixture::create();
    $admin = User::factory()->create(['active_workspace_id' => $fixture->workspace->id]);
    WorkspaceMember::factory()->for($fixture->workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $second = ProductionRun::factory()->for($fixture->workspace)->create();
    $timestamp = $fixture->workspace->getRawOriginal('updated_at');
    $context = ProductionEditingFixture::command($fixture->owner, [$second->id, $run->id]);
    $result = FormulaSharePostgresRace::run(
        fn () => app(FormulaShareTransaction::class)->run($admin, [$fixture->workspace->id], function (): string {
            return DB::selectOne('SHOW transaction_isolation')->transaction_isolation;
        }),
        function () use ($fixture): void {
            User::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->owner->id);
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($fixture->workspace->id);
        },
        fn () => app(ProductionMutationGuard::class)->run($fixture->owner, [$second->id, $run->id], $context, function (User $actor, Workspace $workspace, Collection $runs): ProductionMutationResult {
            expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
            foreach ($runs as $production) {
                $production->update(['notes' => 'Production owns this edit']);
            }

            return new ProductionMutationResult(null, $runs->keys()->all());
        }), repeatableParent: false,
    );
    expect($result['status'])->toBe('ok')->and($result['value'])->toBe('repeatable read')
        ->and($fixture->workspace->fresh()->getRawOriginal('updated_at'))->toBe($timestamp)
        ->and($run->fresh()->notes)->toBe('Production owns this edit')->and($run->fresh()->edit_revision)->toBe(1)
        ->and($second->fresh()->notes)->toBe('Production owns this edit')->and($second->fresh()->edit_revision)->toBe(1);
});
