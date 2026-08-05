<?php

use App\Models\ProductionRun;
use App\Models\ProductionRunNumberSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

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

    ProductionRun::factory()->for($firstWorkspace)->create([
        'planning_batch_number' => 'T00001',
        'batch_number' => 'B-00001',
        'batch_number_serial' => 1,
        'batch_number_assigned_at' => now(),
    ]);
    ProductionRun::factory()->for($secondWorkspace)->create([
        'planning_batch_number' => 'T00001',
        'batch_number' => 'B-00001',
        'batch_number_serial' => 1,
        'batch_number_assigned_at' => now(),
    ]);

    expect(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
        'planning_batch_number' => 'T00001',
    ]))->toThrow(QueryException::class)
        ->and(fn (): ProductionRun => ProductionRun::factory()->for($firstWorkspace)->create([
            'batch_number' => 'B-00001',
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

    $run->update([
        'batch_number' => 'B-01001',
        'batch_number_serial' => 1001,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $assigner->id,
    ]);

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
        ->and(fn (): ?bool => $run->fresh()->delete())
        ->toThrow(QueryException::class);
});

it('gives factory runs distinct temporary identifiers and falls back to them for display', function (): void {
    $runs = ProductionRun::factory()->count(2)->create();

    expect($runs->pluck('planning_batch_number')->unique())->toHaveCount(2)
        ->and($runs->every(fn (ProductionRun $run): bool => preg_match('/^T\\d{5,}$/', $run->planning_batch_number) === 1))->toBeTrue()
        ->and($runs->every(fn (ProductionRun $run): bool => $run->batch_number === null))->toBeTrue()
        ->and($runs->every(fn (ProductionRun $run): bool => $run->displayIdentifier() === $run->planning_batch_number))->toBeTrue();
});
