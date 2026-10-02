<?php

use App\Actions\Production\AssignProductionBatchNumbers;
use App\Enums\ProductionRunStatus;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('preserves production history and integrity objects through the editing migration round trip', function (): void {
    $fixture = ProductionEditingFixture::create();
    $production = ProductionRun::factory()->for($fixture->workspace)->create(['notes' => 'Keep history', 'status' => ProductionRunStatus::Scheduled]);
    app(AssignProductionBatchNumbers::class)->handle($fixture->owner, $fixture->workspace, [$production->id], editing: ProductionEditingFixture::command($fixture->owner, [$production->id]));
    ProductionRequirement::factory()->for($production, 'productionRun')->create();
    ProductionTask::factory()->for($production, 'productionRun')->create(['workspace_id' => $fixture->workspace->id]);
    $tables = ['production_runs', 'production_requirements', 'production_tasks', 'production_run_number_issuances'];
    $snapshot = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [
        $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => collect((array) $row)->except('edit_revision')->all())->all(),
    ])->all();
    $before = $snapshot();
    $objects = DB::getDriverName() === 'sqlite'
        ? DB::table('sqlite_master')->whereIn('tbl_name', $tables)->whereIn('type', ['index', 'trigger'])->orderBy('name')->get()->toArray()
        : null;
    $migration = require database_path('migrations/2026_10_01_120000_add_production_editing_protection.php');

    $migration->down();
    expect(Schema::hasColumn('production_runs', 'edit_revision'))->toBeFalse();
    expect($snapshot())->toBe($before);
    $migration->up();

    expect($snapshot())->toBe($before);
    expect($production->fresh()->edit_revision)->toBe(0);
    expect(Schema::hasTable('production_edit_leases'))->toBeTrue();
    expect(Schema::hasTable('production_edit_takeovers'))->toBeTrue();
    if ($objects !== null) {
        expect(DB::table('sqlite_master')->whereIn('tbl_name', $tables)->whereIn('type', ['index', 'trigger'])->orderBy('name')->get()->toArray())->toEqual($objects);
    }
});

it('cleans editing ownership and audit for a deleted production without removing another production', function (): void {
    $fixture = ProductionEditingFixture::create();
    [$production, $other] = ProductionRun::factory()->for($fixture->workspace)->count(2)->create()->all();
    DB::table('production_edit_leases')->insert([
        'production_run_id' => $production->id, 'user_id' => $fixture->owner->id,
        'token_hash' => hash('sha256', 'test'), 'holder_name' => 'Owner',
        'expires_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('production_edit_takeovers')->insert([
        'production_run_id' => $production->id, 'actor_user_id' => $fixture->owner->id,
        'actor_name' => 'Owner', 'reason' => 'Required', 'created_at' => now(),
    ]);

    $production->delete();

    $this->assertDatabaseCount('production_edit_leases', 0);
    $this->assertDatabaseCount('production_edit_takeovers', 0);
    $this->assertModelExists($other);
});
