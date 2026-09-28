<?php

use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CompleteProduction;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Models\Plan;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionRunPrintDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('prints frozen planned formula and reservations without inventing actual quantities', function (string $basis): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'recipe_name_snapshot' => 'Frozen formula',
        'formula_context_snapshot' => ['calculation_basis' => $basis, 'manufacturing_procedure_html' => '<p>Mix <strong>slowly</strong>.</p>'],
    ]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create(['subject_name_snapshot' => 'Frozen oil', 'material_code_snapshot' => 'OIL-01']);
    ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['ingredient_id' => $requirement->ingredient_id, 'subject_name_snapshot' => 'Frozen oil']);
    if ($basis === 'initial_oils') {
        ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['component' => ProductionFormulaComponent::Water, 'subject_name_snapshot' => 'Water', 'planned_mass_grams' => '38', 'sort_order' => 2]);
    }
    $lot = StockLot::factory()->for($workspace)->create(['internal_lot_code' => 'RESERVED-LOT']);
    StockReservation::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))
        ->assertOk()->assertSee('Frozen formula')->assertSee('OIL-01')->assertSee('RESERVED-LOT')->assertSee('<strong>slowly</strong>', false);
    $data = app(ProductionRunPrintDataBuilder::class)->build($run->fresh());
    expect(collect($data['materials'])->pluck('actual')->filter())->toBeEmpty();
})->with(['initial_oils', 'total_formula']);

it('keeps legacy terminal records independent of current lot identity and missing source formula', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Completed, 'recipe_name_snapshot' => 'Historic formula']);
    $lot = StockLot::factory()->for($workspace)->create(['internal_lot_code' => 'DO-NOT-PRINT']);
    ProductionConsumption::factory()->for($run, 'productionRun')->for($lot, 'stockLot')->create(['subject_name_snapshot' => 'Historic oil']);
    $run->recipe->delete();
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))
        ->assertOk()->assertSee('Historic formula')->assertSee('Historic oil')->assertDontSee('DO-NOT-PRINT');
    $before = app(ProductionRunPrintDataBuilder::class)->build($run->fresh());
    $lot->update(['internal_lot_code' => 'CHANGED', 'supplier_batch_number' => 'CHANGED']);
    expect(app(ProductionRunPrintDataBuilder::class)->build($run->fresh()))->toBe($before);
});

it('rejects cancelled runs and another workspace run', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Cancelled]);
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))->assertStatus(409);
    $other = ProductionRun::factory()->create();
    $this->get(route('production-bench.production.print', ['productionRun' => $other, 'workspace' => $workspace->public_id]))->assertNotFound();
});

it('freezes manufacturing lot evidence on completion or abort and never rewrites it during printing', function (bool $aborted): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'recipe_name_snapshot' => 'Original formula',
        'formula_context_snapshot' => ['manufacturing_procedure_html' => '<p>Original procedure</p>'],
        'notes' => 'Manufacturing note',
    ]);
    app(AssignProductionBatchNumbers::class)->handle($owner, $workspace, [$run->id]);
    $run->update(['status' => ProductionRunStatus::InProduction]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create(['subject_name_snapshot' => 'Original material']);
    ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['ingredient_id' => $requirement->ingredient_id, 'subject_name_snapshot' => 'Original material']);
    $lot = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => $requirement->ingredient_id,
        'internal_lot_code' => 'ORIGINAL-LOT',
        'supplier_batch_number' => 'SUPPLIER-001',
        'expires_at' => '2028-01-01',
    ]);
    ProductionConsumption::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['subject_name_snapshot' => 'Original material', 'quantity' => '99', 'note' => 'Weighed twice']);
    if ($aborted) {
        app(AbortProduction::class)->handle($owner, $run, 'Mixing stopped');
    } else {
        app(CompleteProduction::class)->handle($owner, $run, '9', '2026-09-28');
    }
    $builder = app(ProductionRunPrintDataBuilder::class);
    $before = $builder->build($run->fresh());
    expect($before['consumption'][0])->toMatchArray(['lot' => 'ORIGINAL-LOT', 'supplier_batch' => 'SUPPLIER-001', 'expiry' => '2028-01-01', 'quantity' => '99.000000000']);
    $lot->update(['internal_lot_code' => 'EDITED-LOT', 'supplier_batch_number' => 'EDITED-BATCH', 'expires_at' => '2030-01-01', 'historical_unit_cost' => '999']);
    $run->recipe->update(['name' => 'EDITED-FORMULA']);
    $run->recipeVersion->update(['manufacturing_instructions' => '<p>EDITED-PROCEDURE</p>']);
    $requirement->ingredient->update(['display_name' => 'EDITED-MATERIAL']);
    expect($builder->build($run->fresh()))->toBe($before);
    $attributes = $run->fresh()->getRawOriginal();
    $response = $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]));
    $response->assertOk()->assertSee('Original formula')->assertSee('ORIGINAL-LOT')->assertSee('Weighed twice')->assertDontSee('EDITED-')->assertSee('Batch record');
    if ($aborted) {
        $response->assertSee('Mixing stopped')->assertSee('Aborted');
    }
    expect($run->fresh()->getRawOriginal())->toBe($attributes);
})->with([false, true]);

it('allows a viewer to print after bench cancellation and rejects revoked access', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create(['status' => 'cancelled']);
    $run = ProductionRun::factory()->for($workspace)->create();
    $url = route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]);
    $this->actingAs($viewer)->get($url)->assertOk();
    Livewire::actingAs($viewer)->test(ProductionDetail::class, ['productionId' => $run->public_id])->assertSee('Print batch sheet');
    $membership->delete();
    $this->get($url)->assertForbidden();
});

it('rejects a print link after switching the selected workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $other = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create();
    $owner->forceFill(['active_workspace_id' => $other->id])->save();
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))->assertStatus(409);
});

it('retains captured lot identity when a historical lot reference has been deleted', function (): void {
    $run = ProductionRun::factory()->create(['status' => ProductionRunStatus::Completed]);
    $lot = StockLot::factory()->for($run->workspace)->create();
    $row = ProductionConsumption::factory()->for($run, 'productionRun')->for($lot, 'stockLot')->create();
    $run->update(['formula_context_snapshot' => ['execution_lots' => [$row->id => [
        'stock_lot_id' => $lot->id,
        'internal_lot_code' => $lot->internal_lot_code,
        'supplier_batch_number' => $lot->supplier_batch_number,
        'expires_at' => $lot->expires_at?->toDateString(),
    ]]]]);
    $builder = app(ProductionRunPrintDataBuilder::class);
    $before = $builder->build($run->fresh());
    $lot->delete();
    expect($row->fresh()->stock_lot_id)->toBeNull()
        ->and($builder->build($run->fresh()))->toBe($before);
});

it('distinguishes an explicitly empty captured procedure from unavailable legacy history', function (bool $captured): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'formula_context_snapshot' => $captured ? ['manufacturing_procedure_html' => ''] : [],
    ]);
    $response = $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]));
    $response->assertOk();
    if ($captured) {
        $response->assertSee('No procedure was supplied.');
    } else {
        $response->assertDontSee('No procedure was supplied.');
    }
    expect($run->fresh()->formula_context_snapshot)->toBe($captured ? ['manufacturing_procedure_html' => ''] : []);
})->with([false, true]);
