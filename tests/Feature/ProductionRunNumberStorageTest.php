<?php

use App\Models\ProductionRun;
use App\Models\ProductionRunNumberIssuance;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function issueProductionRunNumber(ProductionRun $run, User $assigner, string $number, int $serial): ProductionRun
{
    $assignedAt = now();

    ProductionRunNumberIssuance::query()->create([
        'workspace_id' => $run->workspace_id,
        'production_run_id' => $run->id,
        'batch_number' => $number,
        'serial' => $serial,
        'issued_by_user_id' => $assigner->id,
        'issued_at' => $assignedAt,
    ]);

    $run->update([
        'batch_number' => $number,
        'batch_number_serial' => $serial,
        'batch_number_assigned_at' => $assignedAt,
        'batch_number_assigned_by_user_id' => $assigner->id,
    ]);

    return $run->fresh();
}

it('stores one numbered-run setting row per workspace with the intended defaults', function (): void {
    $workspace = Workspace::factory()->create();

    $setting = ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
    ]);

    expect(Schema::hasColumns('production_run_number_settings', [
        'workspace_id',
        'next_planning_serial',
        'permanent_prefix',
        'permanent_suffix',
        'permanent_padding',
        'next_permanent_serial',
    ]))->toBeTrue()
        ->and($setting->next_planning_serial)->toBe(1)
        ->and($setting->permanent_prefix)->toBe('B-')
        ->and($setting->permanent_suffix)->toBe('')
        ->and($setting->permanent_padding)->toBe(5)
        ->and($setting->next_permanent_serial)->toBe(1)
        ->and($setting->workspace->is($workspace))->toBeTrue()
        ->and($workspace->productionRunNumberSetting->is($setting))->toBeTrue();

    expect(fn (): ProductionRunNumberSetting => ProductionRunNumberSetting::query()->create([
        'workspace_id' => $workspace->id,
    ]))->toThrow(QueryException::class);

    expect(fn (): ProductionRun => ProductionRun::factory()->create([
        'planning_batch_number' => null,
    ]))->toThrow(QueryException::class);
});

it('keeps rendered run identifiers unique within a workspace while isolating workspaces', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();
    $assigner = User::factory()->create();

    issueProductionRunNumber(
        ProductionRun::factory()->for($firstWorkspace)->create(['planning_batch_number' => 'T00001']),
        $assigner,
        'B-00001',
        1,
    );
    issueProductionRunNumber(
        ProductionRun::factory()->for($secondWorkspace)->create(['planning_batch_number' => 'T00001']),
        $assigner,
        'B-00001',
        1,
    );

    expect(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
        'planning_batch_number' => 'T00001',
    ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
            'batch_number' => 'B-00001',
        ]))->toThrow(QueryException::class);
});

it('keeps issued permanent identifiers unique in a workspace after the run is deleted', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();

    ProductionRunNumberIssuance::factory()->for($firstWorkspace)->create([
        'batch_number' => 'B-00001',
        'serial' => 1,
        'production_run_id' => null,
    ]);
    ProductionRunNumberIssuance::factory()->for($secondWorkspace)->create([
        'batch_number' => 'B-00001',
        'serial' => 1,
        'production_run_id' => null,
    ]);

    expect(fn (): ProductionRunNumberIssuance => ProductionRunNumberIssuance::factory()
        ->for($firstWorkspace)
        ->create([
            'batch_number' => 'B-00001',
            'serial' => 1,
            'production_run_id' => null,
        ]))->toThrow(QueryException::class);
});

it('backfills historic permanent batch number issuances before runs can be deleted', function (): void {
    if (DB::getDriverName() === 'pgsql') {
        $this->markTestSkipped('The applied PostgreSQL guard prevents simulating pre-backfill history.');
    }

    $assigner = User::factory()->create();
    $run = ProductionRun::factory()->create([
        'batch_number' => 'B-19991',
        'batch_number_serial' => 19991,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $assigner->id,
    ]);

    $migrationPath = database_path('migrations/2026_08_10_231300_backfill_production_run_number_issuances.php');

    expect(is_file($migrationPath))->toBeTrue();

    $migration = require $migrationPath;
    $migration->up();
    $migration->up();

    $issuance = ProductionRunNumberIssuance::query()
        ->where('workspace_id', $run->workspace_id)
        ->where('batch_number', 'B-19991')
        ->sole();

    expect($issuance->production_run_id)->toBe($run->id)
        ->and($issuance->serial)->toBe(19991)
        ->and($issuance->issued_by_user_id)->toBe($assigner->id)
        ->and($issuance->issued_at->equalTo($run->batch_number_assigned_at))->toBeTrue()
        ->and($run->fresh()->delete())->toBeTrue()
        ->and($issuance->fresh()->production_run_id)->toBeNull();
});

it('rejects planning and permanent identifiers that collide across a workspace or on one run', function (): void {
    $workspace = Workspace::factory()->create();
    $assigner = User::factory()->create();

    issueProductionRunNumber(
        ProductionRun::factory()->for($workspace)->create(['planning_batch_number' => 'T20000']),
        $assigner,
        'B20000',
        20000,
    );

    expect(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
        'planning_batch_number' => 'B20000',
    ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
            'batch_number' => 'T20000',
        ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
            'planning_batch_number' => 'T20001',
            'batch_number' => 'T20001',
            'batch_number_serial' => 20001,
            'batch_number_assigned_at' => now(),
        ]))->toThrow(QueryException::class);
});

it('requires a complete permanent number audit tuple', function (): void {
    $workspace = Workspace::factory()->create();
    $assigner = User::factory()->create();

    expect(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
        'batch_number' => 'B-23001',
    ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
            'batch_number_assigned_at' => now(),
        ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($workspace)->create([
            'batch_number_assigned_by_user_id' => $assigner->id,
        ]))->toThrow(QueryException::class);

    $run = ProductionRun::factory()->for($workspace)->create();

    expect(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
        'batch_number' => 'B-23002',
        'batch_number_serial' => 23002,
    ]))->toThrow(QueryException::class)
        ->and(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
            'batch_number_assigned_at' => now(),
        ]))->toThrow(QueryException::class);
});

it('keeps production runs in their original workspace at the database boundary', function (): void {
    $run = ProductionRun::factory()->create();
    $otherWorkspace = Workspace::factory()->create();

    expect(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
        'workspace_id' => $otherWorkspace->id,
    ]))->toThrow(QueryException::class);
});

it('backfills temporary planning identifiers in workspace and run order without inferring permanent numbers', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();
    $first = ProductionRun::factory()->for($firstWorkspace)->create();
    $second = ProductionRun::factory()->for($firstWorkspace)->create();
    $third = ProductionRun::factory()->for($secondWorkspace)->create();

    DB::statement('DROP TRIGGER IF EXISTS production_runs_number_integrity_update');
    Schema::table('production_runs', function ($table): void {
        $table->string('planning_batch_number', 32)->nullable()->change();
    });
    DB::table('production_runs')->whereIn('id', [$first->id, $second->id, $third->id])->update([
        'planning_batch_number' => null,
        'batch_number' => null,
        'batch_number_serial' => null,
        'batch_number_assigned_at' => null,
        'batch_number_assigned_by_user_id' => null,
    ]);
    DB::table('production_run_number_settings')->delete();

    $migration = require database_path('migrations/2026_08_05_120002_backfill_production_run_planning_references.php');
    $migration->up();

    expect($first->fresh()->planning_batch_number)->toBe('T00001')
        ->and($second->fresh()->planning_batch_number)->toBe('T00002')
        ->and($third->fresh()->planning_batch_number)->toBe('T00001')
        ->and($first->fresh()->batch_number)->toBeNull()
        ->and($second->fresh()->batch_number)->toBeNull()
        ->and($third->fresh()->batch_number)->toBeNull()
        ->and($firstWorkspace->fresh()->productionRunNumberSetting->next_planning_serial)->toBe(3)
        ->and($secondWorkspace->fresh()->productionRunNumberSetting->next_planning_serial)->toBe(2);
});

it('prevents model and mass updates from rewriting assigned identifiers and metadata', function (): void {
    $assigner = User::factory()->create();
    $run = ProductionRun::factory()->create(['planning_batch_number' => 'T01001']);

    $run = issueProductionRunNumber($run, $assigner, 'B-01001', 1001);

    expect($run->fresh()->displayIdentifier())->toBe('B-01001')
        ->and($run->fresh()->batchNumberAssignedBy->is($assigner))->toBeTrue()
        ->and(fn (): bool => $run->fresh()->update(['planning_batch_number' => 'T99999']))
        ->toThrow(QueryException::class)
        ->and(fn (): int => ProductionRun::query()->whereKey($run->id)->update(['batch_number' => 'B-99999']))
        ->toThrow(QueryException::class)
        ->and(fn (): int => ProductionRun::query()->whereKey($run->id)->update(['batch_number' => null]))
        ->toThrow(QueryException::class)
        ->and(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
            'batch_number_assigned_by_user_id' => null,
        ]))->toThrow(QueryException::class)
        ->and(fn (): ?bool => $assigner->delete())
        ->toThrow(QueryException::class);

    // A numbered run without reservations is deletable, and its number is
    // burned with it (the counter never re-issues it).
    $run->fresh()->delete();

    expect(ProductionRun::query()->find($run->id))->toBeNull()
        ->and(ProductionRun::query()->where('batch_number', 'B-01001')->count())->toBe(0);
});

it('keeps planning references immutable before permanent assignment', function (): void {
    $run = ProductionRun::factory()->create(['planning_batch_number' => 'T21001']);

    expect(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
        'planning_batch_number' => 'T21002',
    ]))->toThrow(QueryException::class);
});

it('rejects non-positive number serial settings and run assignment serials', function (): void {
    $setting = ProductionRunNumberSetting::query()->create([
        'workspace_id' => Workspace::factory()->create()->id,
    ]);
    $run = ProductionRun::factory()->create();

    expect(fn (): int => DB::table('production_run_number_settings')->where('id', $setting->id)->update([
        'next_planning_serial' => 0,
    ]))->toThrow(QueryException::class)
        ->and(fn (): int => DB::table('production_run_number_settings')->where('id', $setting->id)->update([
            'next_permanent_serial' => 0,
        ]))->toThrow(QueryException::class)
        ->and(fn (): int => DB::table('production_run_number_settings')->where('id', $setting->id)->update([
            'permanent_padding' => 0,
        ]))->toThrow(QueryException::class)
        ->and(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
            'batch_number_serial' => 0,
        ]))->toThrow(QueryException::class);
});

it('enforces permanent number integrity on PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only trigger integration test.');
    }

    $assigner = User::factory()->create();
    $run = ProductionRun::factory()->create();
    $run = issueProductionRunNumber($run, $assigner, 'B-22001', 22001);

    expect(fn (): int => ProductionRun::query()->whereKey($run->id)->update([
        'batch_number' => 'B-22002',
    ]))->toThrow(QueryException::class)
        ->and(fn (): int => ProductionRun::query()->whereKey($run->id)->update([
            'batch_number_assigned_at' => now()->addMinute(),
        ]))->toThrow(QueryException::class);

    expect($run->fresh()->delete())->toBeTrue();
});

it('requires PostgreSQL permanent numbers to match their issuance history', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only trigger integration test.');
    }

    $assigner = User::factory()->create();
    $run = ProductionRun::factory()->create();

    expect(fn (): int => DB::table('production_runs')->where('id', $run->id)->update([
        'batch_number' => 'B-22991',
        'batch_number_serial' => 22991,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $assigner->id,
    ]))->toThrow(QueryException::class);

    $assignedAt = now();

    ProductionRunNumberIssuance::query()->create([
        'workspace_id' => $run->workspace_id,
        'production_run_id' => $run->id,
        'batch_number' => 'B-22991',
        'serial' => 22991,
        'issued_by_user_id' => $assigner->id,
        'issued_at' => $assignedAt,
    ]);

    expect(DB::table('production_runs')->where('id', $run->id)->update([
        'batch_number' => 'B-22991',
        'batch_number_serial' => 22991,
        'batch_number_assigned_at' => $assignedAt,
        'batch_number_assigned_by_user_id' => $assigner->id,
    ]))->toBe(1);
});

it('serializes PostgreSQL workspace number writes before cross-field collision checks', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only trigger locking integration test.');
    }

    $definition = (string) DB::selectOne(<<<'SQL'
        SELECT pg_get_functiondef('production_runs_enforce_batch_number_integrity()'::regprocedure) AS definition
    SQL)->definition;

    expect($definition)->toContain('pg_advisory_xact_lock(NEW.workspace_id)')
        ->and($definition)->toContain('pg_advisory_xact_lock(OLD.workspace_id)');
});

it('ships a forward migration that reapplies production run number hardening', function (): void {
    $migrationPath = database_path('migrations/2026_08_10_180000_harden_production_run_reservation_delete_guard.php');

    expect(is_file($migrationPath))->toBeTrue();

    $migration = require $migrationPath;
    $migration->up();
    $migration->up();

    $assigner = User::factory()->create();
    $run = ProductionRun::factory()->create();
    $run = issueProductionRunNumber($run, $assigner, 'B-24001', 24001);

    expect(fn (): int => ProductionRun::query()->whereKey($run->id)->update([
        'batch_number' => 'B-24002',
    ]))->toThrow(QueryException::class)
        ->and(fn (): ?bool => $assigner->delete())->toThrow(QueryException::class);

    expect($run->fresh()->delete())->toBeTrue()
        ->and(fn (): mixed => $migration->down())
        ->toThrow(RuntimeException::class, 'forward-only');
});

it('gives factory runs distinct temporary identifiers and falls back to them for display', function (): void {
    $runs = ProductionRun::factory()->count(2)->create();

    expect($runs->pluck('planning_batch_number')->unique())->toHaveCount(2)
        ->and($runs->every(fn (ProductionRun $run): bool => preg_match('/^T\\d{5,}$/', $run->planning_batch_number) === 1))->toBeTrue()
        ->and($runs->every(fn (ProductionRun $run): bool => $run->batch_number === null))->toBeTrue()
        ->and($runs->every(fn (ProductionRun $run): bool => $run->displayIdentifier() === $run->planning_batch_number))->toBeTrue();
});
