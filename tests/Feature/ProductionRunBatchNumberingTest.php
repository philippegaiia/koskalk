<?php

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\SaveProductionRunNumberSettings;
use App\Models\ProductionRun;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\ProductionRunStatus;
use App\Services\Production\ProductionRunNumberService;
use App\Services\ProductionBenchAccess;
use App\WorkspaceMemberRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function activeProductionNumberingWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    (new ProductionBenchAccess)->activate($owner, $workspace);

    return [$owner, $workspace];
}

function productionNumberingRun(Workspace $workspace, array $attributes = []): ProductionRun
{
    return ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-08-10',
        ...$attributes,
    ]);
}

it('formats permanent production batch numbers without truncating serials', function (): void {
    $numbers = new ProductionRunNumberService;

    expect($numbers->formatPermanentNumber('SOAP-', 42, '-FR', 5))->toBe('SOAP-00042-FR')
        ->and($numbers->formatPermanentNumber('B-', 100000, '', 5))->toBe('B-100000');
});

it('allocates temporary planning references from the workspace-specific counter', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $numbers = new ProductionRunNumberService;

    expect($numbers->allocatePlanningReference($workspace))->toBe('T00001')
        ->and($numbers->allocatePlanningReference($workspace))->toBe('T00002')
        ->and(ProductionRunNumberSetting::query()->whereBelongsTo($workspace)->sole()->next_planning_serial)->toBe(3);
});

it('saves valid permanent batch number settings without changing temporary references', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $setting = ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
        'next_planning_serial' => 27,
    ]);

    $saved = (new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService))
        ->handle($owner, $workspace, 'SOAP-', '-FR', 5, 42);

    expect($saved->permanent_prefix)->toBe('SOAP-')
        ->and($saved->permanent_suffix)->toBe('-FR')
        ->and($saved->permanent_padding)->toBe(5)
        ->and($saved->next_permanent_serial)->toBe(42)
        ->and($saved->next_planning_serial)->toBe(27)
        ->and($setting->fresh()->next_planning_serial)->toBe(27);
});

it('rejects unsafe or invalid permanent batch number settings', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $action = new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, 'SOAP ', '', 5, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, 'B-', '', 0, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, 'B-', '', 5, '1.5'))
        ->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, str_repeat('B', 32), str_repeat('S', 32), 120, 1))
        ->toThrow(ValidationException::class);
});

it('rejects terminal next permanent serial settings', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $action = new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, 'B-', '', 5, PHP_INT_MAX))
        ->toThrow(ValidationException::class);
});

it('rejects settings whose next rendered candidate is already a workspace identity', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    productionNumberingRun($workspace, ['planning_batch_number' => 'SOAP-00042-FR']);
    $action = new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, 'SOAP-', '-FR', 5, 42))
        ->toThrow(ValidationException::class);
});

it('restricts number settings to active owner and admin users', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $action = new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService);

    expect($action->handle($admin, $workspace, 'A-', '', 5, 1)->permanent_prefix)->toBe('A-')
        ->and(fn () => $action->handle($editor, $workspace, 'B-', '', 5, 1))->toThrow(AuthorizationException::class)
        ->and(fn () => $action->handle($viewer, $workspace, 'B-', '', 5, 1))->toThrow(AuthorizationException::class);

    (new ProductionBenchAccess)->cancel($owner, $workspace);

    expect(fn () => $action->handle($owner, $workspace, 'B-', '', 5, 1))->toThrow(ValidationException::class);
});

it('assigns permanent numbers in planned date order rather than selected order', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $later = productionNumberingRun($workspace, ['planned_for' => '2026-08-12']);
    $earlier = productionNumberingRun($workspace, ['planned_for' => '2026-08-11']);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    $result = $action->handle($owner, $workspace, [$later->id, $earlier->id]);

    expect($result)->toBe(['assigned' => 2, 'already_assigned' => 0])
        ->and($earlier->fresh()->batch_number)->toBe('B-00001')
        ->and($later->fresh()->batch_number)->toBe('B-00002')
        ->and($earlier->fresh()->batch_number_serial)->toBe(1)
        ->and($earlier->fresh()->batch_number_assigned_by_user_id)->toBe($owner->id)
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(3);
});

it('skips already-numbered runs during idempotent assignment retries', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $first = productionNumberingRun($workspace);
    $second = productionNumberingRun($workspace, ['planned_for' => '2026-08-11']);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    $action->handle($owner, $workspace, [$first->id, $second->id]);

    expect($action->handle($owner, $workspace, [$second->id, $first->id]))
        ->toBe(['assigned' => 0, 'already_assigned' => 2])
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(3);
});

it('rejects empty, missing, cross-workspace, draft, and undated selections', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    [, $otherWorkspace] = activeProductionNumberingWorkspace();
    $draft = productionNumberingRun($workspace, ['status' => ProductionRunStatus::Draft]);
    $undated = productionNumberingRun($workspace, ['planned_for' => null]);
    $foreign = productionNumberingRun($otherWorkspace);
    ProductionRunNumberSetting::query()->create(['workspace_id' => $workspace->id]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, []))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, [999999]))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, [$foreign->id]))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, [$draft->id]))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($owner, $workspace, [$undated->id]))->toThrow(ValidationException::class)
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(1);
});

it('allows Flash runs to receive permanent numbers in the normal assignment workflow', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $flash = productionNumberingRun($workspace, ['source' => 'flash']);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect($action->handle($owner, $workspace, [$flash->id]))
        ->toBe(['assigned' => 1, 'already_assigned' => 0])
        ->and($flash->fresh()->batch_number)->toBe('B-00001');
});

it('scopes locked production rows to the requested workspace', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $run = productionNumberingRun($workspace);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'production_runs')) {
            $queries[] = $query->sql;
        }
    });

    $action->handle($owner, $workspace, [$run->id]);

    $lockQuery = collect($queries)->first(fn (string $query): bool => str_contains(strtolower($query), 'order by')
        && (DB::getDriverName() !== 'pgsql' || str_contains(strtolower($query), 'for update')));

    expect($lockQuery)->not->toBeNull()
        ->and(str_contains(strtolower($lockQuery), 'workspace_id'))->toBeTrue();
});

it('allows owner admin and editor assignment but rejects viewers and inactive access', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create(['role' => WorkspaceMemberRole::Admin]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect($action->handle($owner, $workspace, [productionNumberingRun($workspace)->id]))->toBe(['assigned' => 1, 'already_assigned' => 0])
        ->and($action->handle($admin, $workspace, [productionNumberingRun($workspace)->id]))->toBe(['assigned' => 1, 'already_assigned' => 0])
        ->and($action->handle($editor, $workspace, [productionNumberingRun($workspace)->id]))->toBe(['assigned' => 1, 'already_assigned' => 0])
        ->and(fn () => $action->handle($viewer, $workspace, [productionNumberingRun($workspace)->id]))->toThrow(AuthorizationException::class);

    (new ProductionBenchAccess)->cancel($owner, $workspace);

    expect(fn () => $action->handle($owner, $workspace, [productionNumberingRun($workspace)->id]))->toThrow(ValidationException::class);

    $inactiveOwner = User::factory()->create();
    $inactiveWorkspace = Workspace::factory()->for($inactiveOwner, 'owner')->create();

    expect(fn () => $action->handle($inactiveOwner, $inactiveWorkspace, [productionNumberingRun($inactiveWorkspace)->id]))
        ->toThrow(ValidationException::class);
});

it('rolls back every assignment and the counter when any rendered candidate collides', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    productionNumberingRun($workspace, ['planning_batch_number' => 'B-00001']);
    $first = productionNumberingRun($workspace, ['planned_for' => '2026-08-10']);
    $second = productionNumberingRun($workspace, ['planned_for' => '2026-08-11']);
    ProductionRunNumberSetting::query()->create(['workspace_id' => $workspace->id]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, [$first->id, $second->id]))
        ->toThrow(ValidationException::class)
        ->and($first->fresh()->batch_number)->toBeNull()
        ->and($second->fresh()->batch_number)->toBeNull()
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(1);
});

it('rejects a terminal permanent serial before changing runs or counters', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $run = productionNumberingRun($workspace);
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
        'next_permanent_serial' => PHP_INT_MAX,
    ]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, [$run->id]))
        ->toThrow(ValidationException::class)
        ->and($run->fresh()->batch_number)->toBeNull()
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(PHP_INT_MAX);
});

it('rejects an exhausted counter atomically for a two-run assignment', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $first = productionNumberingRun($workspace, ['planned_for' => '2026-08-10']);
    $second = productionNumberingRun($workspace, ['planned_for' => '2026-08-11']);
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
        'next_permanent_serial' => PHP_INT_MAX - 1,
    ]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect(fn () => $action->handle($owner, $workspace, [$first->id, $second->id]))
        ->toThrow(ValidationException::class)
        ->and($first->fresh()->batch_number)->toBeNull()
        ->and($second->fresh()->batch_number)->toBeNull()
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(PHP_INT_MAX - 1);
});

it('allows the highest serial that can still be incremented for one assignment', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $run = productionNumberingRun($workspace);
    ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
        'next_permanent_serial' => PHP_INT_MAX - 1,
    ]);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    expect($action->handle($owner, $workspace, [$run->id]))
        ->toBe(['assigned' => 1, 'already_assigned' => 0])
        ->and($run->fresh()->batch_number_serial)->toBe(PHP_INT_MAX - 1)
        ->and($workspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(PHP_INT_MAX);
});

it('isolates permanent batch numbers and counters between workspaces', function (): void {
    [$firstOwner, $firstWorkspace] = activeProductionNumberingWorkspace();
    [$secondOwner, $secondWorkspace] = activeProductionNumberingWorkspace();
    $first = productionNumberingRun($firstWorkspace);
    $second = productionNumberingRun($secondWorkspace);
    $action = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    $action->handle($firstOwner, $firstWorkspace, [$first->id]);
    $action->handle($secondOwner, $secondWorkspace, [$second->id]);

    expect($first->fresh()->batch_number)->toBe('B-00001')
        ->and($second->fresh()->batch_number)->toBe('B-00001')
        ->and($firstWorkspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(2)
        ->and($secondWorkspace->fresh()->productionRunNumberSetting->next_permanent_serial)->toBe(2);
});

it('retains permanent number audit metadata when a production is cancelled', function (): void {
    [$owner, $workspace] = activeProductionNumberingWorkspace();
    $run = productionNumberingRun($workspace);
    $assignment = new AssignProductionBatchNumbers(new ProductionBenchAccess, new ProductionRunNumberService);

    $assignment->handle($owner, $workspace, [$run->id]);
    $assigned = $run->fresh();
    $assigned->load('workspace');
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'workspaces')
            || str_contains(strtolower($query->sql), 'production_runs')) {
            $queries[] = $query->sql;
        }
    });

    $cancelled = (new CancelProduction(new ProductionBenchAccess))->handle($owner, $assigned, 'Customer postponed the batch.');
    $workspaceQueryIndex = collect($queries)->search(fn (string $query): bool => str_contains(strtolower($query), 'workspaces'));
    $productionQueryIndex = collect($queries)->search(fn (string $query): bool => str_contains(strtolower($query), 'production_runs'));
    $productionLockQuery = collect($queries)->first(fn (string $query): bool => str_contains(strtolower($query), 'production_runs'));

    expect($cancelled->status)->toBe(ProductionRunStatus::Cancelled)
        ->and($cancelled->batch_number)->toBe($assigned->batch_number)
        ->and($cancelled->batch_number_serial)->toBe($assigned->batch_number_serial)
        ->and($cancelled->batch_number_assigned_by_user_id)->toBe($assigned->batch_number_assigned_by_user_id)
        ->and($cancelled->batch_number_assigned_at?->equalTo($assigned->batch_number_assigned_at))->toBeTrue()
        ->and($workspaceQueryIndex)->toBeLessThan($productionQueryIndex)
        ->and(str_contains(strtolower($productionLockQuery), 'workspace_id'))->toBeTrue();
});

it('serializes settings initialization against a concurrent PostgreSQL workspace lock', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only two-connection contention test.');
    }

    if (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    (new ProductionBenchAccess)->activate($owner, $workspace);

    $secondConnectionName = 'production_numbering_contention';
    $defaultConnectionName = config('database.default');
    config(['database.connections.'.$secondConnectionName => config('database.connections.'.$defaultConnectionName)]);
    DB::purge($secondConnectionName);
    $secondConnection = DB::connection($secondConnectionName);

    DB::beginTransaction();
    DB::table('workspaces')->where('id', $workspace->id)->lockForUpdate()->first();
    $secondConnection->statement("SET lock_timeout = '250ms'");
    config(['database.default' => $secondConnectionName]);

    try {
        $action = new SaveProductionRunNumberSettings(new ProductionBenchAccess, new ProductionRunNumberService);

        expect(fn () => $action->handle($owner, $workspace, 'C-', '', 5, 1))
            ->toThrow(QueryException::class);
    } finally {
        config(['database.default' => $defaultConnectionName]);
        DB::rollBack();
        $secondConnection->disconnect();
        DB::table('workspaces')->where('id', $workspace->id)->delete();
        DB::table('users')->where('id', $owner->id)->delete();
    }
});
