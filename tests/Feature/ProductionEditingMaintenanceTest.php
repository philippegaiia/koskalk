<?php

use App\Actions\Production\BackfillProductionFormulaSnapshot;
use App\Models\ProductionRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('skips an actively reserved snapshot without changing the production revision', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $fixture->lease($run);

    expect(app(BackfillProductionFormulaSnapshot::class)->handle($run))->toBeFalse();

    expect($run->fresh()->formula_snapshot_completed_at)->toBeNull();
    expect($run->fresh()->edit_revision)->toBe(0);
    $this->assertDatabaseCount('production_formula_lines', 0);
});
