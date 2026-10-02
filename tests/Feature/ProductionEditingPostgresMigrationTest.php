<?php

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FormulaSharePostgresDatabase;
use Tests\Support\ProductionEditingFixture;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! filter_var(env('VERIFY_FORMULA_SHARING_POSTGRES', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Requires the explicitly disposable PostgreSQL database.');
    }
    FormulaSharePostgresDatabase::reset();
    $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
});

it('preserves PostgreSQL production history indexes constraints and triggers through the editing migration round trip', function (): void {
    $fixture = ProductionEditingFixture::create();
    $production = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Keep history', 'status' => ProductionRunStatus::Scheduled]);
    app(AssignProductionBatchNumbers::class)->handle($fixture->owner, $fixture->workspace, [$production->id], editing: ProductionEditingFixture::command($fixture->owner, [$production->id]));
    ProductionRequirement::factory()->for($production, 'productionRun')->create();
    ProductionTask::factory()->for($production, 'productionRun')->create(['workspace_id' => $fixture->workspace->id]);
    $tables = ['production_runs', 'production_requirements', 'production_tasks', 'production_run_number_issuances'];
    $history = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => collect((array) $row)->except('edit_revision')->all())->all(),
    ])->all();
    $definitions = function (array $tables): array {
        return [
            'indexes' => DB::table('pg_indexes')->where('schemaname', 'public')->whereIn('tablename', $tables)
                ->orderBy('tablename')->orderBy('indexname')->get(['tablename', 'indexname', 'indexdef'])->toArray(),
            'constraints' => DB::table('pg_constraint as constraint_record')
                ->join('pg_class as relation', 'relation.oid', '=', 'constraint_record.conrelid')
                ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
                ->where('namespace.nspname', 'public')->whereIn('relation.relname', $tables)
                ->selectRaw('relation.relname AS table_name, constraint_record.conname AS name, pg_get_constraintdef(constraint_record.oid, true) AS definition')
                ->orderBy('relation.relname')->orderBy('constraint_record.conname')->get()->toArray(),
            'triggers' => DB::table('pg_trigger as trigger_record')
                ->join('pg_class as relation', 'relation.oid', '=', 'trigger_record.tgrelid')
                ->join('pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
                ->where('namespace.nspname', 'public')->whereIn('relation.relname', $tables)->where('trigger_record.tgisinternal', false)
                ->selectRaw('relation.relname AS table_name, trigger_record.tgname AS name, pg_get_triggerdef(trigger_record.oid, true) AS definition')
                ->orderBy('relation.relname')->orderBy('trigger_record.tgname')->get()->toArray(),
        ];
    };
    $before = $history();
    $objects = $definitions($tables);
    $editingObjects = $definitions(['production_edit_leases', 'production_edit_takeovers']);
    expect($objects['indexes'])->not->toBeEmpty()
        ->and($objects['constraints'])->not->toBeEmpty()
        ->and($objects['triggers'])->not->toBeEmpty();
    $migration = require database_path('migrations/2026_10_01_120000_add_production_editing_protection.php');

    $migration->down();

    expect(Schema::hasColumn('production_runs', 'edit_revision'))->toBeFalse()
        ->and(Schema::hasTable('production_edit_leases'))->toBeFalse()
        ->and(Schema::hasTable('production_edit_takeovers'))->toBeFalse()
        ->and($history())->toBe($before)
        ->and($definitions($tables))->toEqual($objects);

    $migration->up();

    expect($history())->toBe($before)
        ->and($definitions($tables))->toEqual($objects)
        ->and($definitions(['production_edit_leases', 'production_edit_takeovers']))->toEqual($editingObjects)
        ->and($production->fresh()->edit_revision)->toBe(0);
    $this->assertDatabaseCount('production_edit_leases', 0);
    $this->assertDatabaseCount('production_edit_takeovers', 0);
});
