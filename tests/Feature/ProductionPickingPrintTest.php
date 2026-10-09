<?php

use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

function pickingFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create(['subject_name_snapshot' => '<b>Oil</b>', 'material_code_snapshot' => 'OIL-1']);
    $lot = StockLot::factory()->for($workspace)->released()->create(['ingredient_id' => $requirement->ingredient_id, 'internal_lot_code' => 'PICK-LOT']);
    StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '150']);
    $payload = ['workspace' => $workspace->public_id, 'runs' => [$run->public_id], 'allocations' => [['requirement_id' => $requirement->id, 'stock_lot_id' => $lot->id, 'quantity' => '25.123456789']]];

    return compact('owner', 'workspace', 'run', 'requirement', 'lot', 'payload');
}

it('prints exact proposed quantities and shortages without reserving or starting production', function (): void {
    extract(pickingFixture());
    $before = $run->fresh()->getAttributes();
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)
        ->assertOk()->assertSee('PICK-LOT')->assertSee('25.123456789')->assertSee('74.876543211')->assertSee('&lt;b&gt;Oil&lt;/b&gt;', false)->assertSee('OIL-1')->assertSee('Proposed — unreserved');
    expect($run->fresh()->getAttributes())->toBe($before);
    expect(StockReservation::count())->toBe(0);
    expect(StockMovement::count())->toBe(1);
});

it('rejects invalid allocations without changing stock', function (string $case): void {
    extract(pickingFixture());
    if ($case === 'duplicate') {
        $payload['allocations'][] = $payload['allocations'][0];
    }
    if ($case === 'negative') {
        $payload['allocations'][0]['quantity'] = '-1';
    }
    if ($case === 'zero') {
        $payload['allocations'][0]['quantity'] = '0';
    }
    if ($case === 'remaining') {
        $payload['allocations'][0]['quantity'] = '101';
    }
    if ($case === 'foreign requirement') {
        $payload['allocations'][0]['requirement_id'] = ProductionRequirement::factory()->create()->id;
    }
    if ($case === 'foreign lot') {
        $payload['allocations'][0]['stock_lot_id'] = StockLot::factory()->released()->create()->id;
    }
    if ($case === 'quarantine') {
        $lot->update(['status' => 'quarantined']);
    }
    if ($case === 'expiry') {
        $lot->update(['expires_at' => $run->planned_for->subDay()]);
    }
    if ($case === 'consumed') {
        StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '-150']);
    }
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertUnprocessable();
    expect(StockReservation::count())->toBe(0);
})->with(['duplicate', 'negative', 'zero', 'remaining', 'foreign requirement', 'foreign lot', 'quarantine', 'expiry', 'consumed']);

it('rejects aggregate allocations above a shared lots free stock', function (): void {
    extract(pickingFixture());
    $second = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $requirement2 = ProductionRequirement::factory()->for($second, 'productionRun')->create(['ingredient_id' => $requirement->ingredient_id]);
    $payload['runs'][] = $second->public_id;
    $payload['allocations'][0]['quantity'] = '80';
    $payload['allocations'][] = ['requirement_id' => $requirement2->id, 'stock_lot_id' => $lot->id, 'quantity' => '80'];
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertUnprocessable();
    $payload['allocations'][1]['quantity'] = '70';
    $this->postJson(route('production-bench.production.picking.print'), $payload)->assertOk();
});

it('shows existing reservations separately and hides stored locations when disabled', function (bool $locations): void {
    extract(pickingFixture());
    $workspace->update(['uses_storage_locations' => $locations]);
    $location = StorageLocation::factory()->for($workspace)->create(['name' => 'Shelf Alpha']);
    $lot->update(['storage_location_id' => $location->id]);
    StockReservation::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['workspace_id' => $workspace->id, 'quantity' => '20']);
    $response = $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertOk()->assertSee('Reserved')->assertSee('54.876543211');
    if ($locations) {
        $response->assertSee('Shelf Alpha');
    } else {
        $response->assertDontSee('Shelf Alpha')->assertDontSee('Storage location');
    }
    expect(StockReservation::count())->toBe(1);
})->with([true, false]);

it('allows a viewer in a cancelled module and denies revoked membership', function (): void {
    extract(pickingFixture());
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    $workspace->productionEntitlement()->update(['status' => 'cancelled']);
    $this->actingAs($viewer)->postJson(route('production-bench.production.picking.print'), $payload)->assertOk();
    $membership->delete();
    $this->postJson(route('production-bench.production.picking.print'), $payload)->assertForbidden();
});

it('rejects switched workspaces foreign runs inactive modules and terminal runs', function (string $case): void {
    extract(pickingFixture());
    $status = 422;
    if ($case === 'switch') {
        $other = Workspace::factory()->for($owner, 'owner')->create();
        $owner->forceFill(['active_workspace_id' => $other->id])->save();
        $status = 409;
    }
    if ($case === 'foreign') {
        $payload['runs'] = [ProductionRun::factory()->create()->public_id];
        $status = 404;
    }
    if ($case === 'inactive') {
        $workspace->productionEntitlement()->delete();
        $status = 403;
    }
    if ($case === 'terminal') {
        $run->update(['status' => ProductionRunStatus::Completed]);
    }
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertStatus($status);
})->with(['switch', 'foreign', 'inactive', 'terminal']);

it('rejects fractional packaging quantities', function (): void {
    extract(pickingFixture());
    $packaging = ProductionRequirement::factory()->for($run, 'productionRun')->forPackaging()->create();
    $packLot = StockLot::factory()->for($workspace)->forPackaging()->released()->create(['packaging_item_id' => $packaging->packaging_item_id]);
    StockMovement::factory()->for($packLot, 'stockLot')->create(['quantity_delta' => '20']);
    $payload['allocations'] = [['requirement_id' => $packaging->id, 'stock_lot_id' => $packLot->id, 'quantity' => '1.5']];
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertUnprocessable();
    $payload['allocations'][0]['quantity'] = '2';
    $this->postJson(route('production-bench.production.picking.print'), $payload)->assertOk();
});

it('bounds client input and expanded server output', function (string $case): void {
    extract(pickingFixture());
    if ($case === 'runs') {
        $payload['runs'] = array_fill(0, 101, $run->public_id);
    }
    if ($case === 'rows') {
        $payload['allocations'] = array_fill(0, 1001, $payload['allocations'][0]);
    }
    if ($case === 'payload') {
        $payload['padding'] = str_repeat('x', 262145);
    }
    if ($case === 'expanded') {
        StockReservation::factory()->count(1000)->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['workspace_id' => $workspace->id, 'created_by_user_id' => $owner->id, 'quantity' => '0.001']);
    }
    $this->actingAs($owner)->postJson(route('production-bench.production.picking.print'), $payload)->assertUnprocessable();
})->with(['runs', 'rows', 'payload', 'expanded']);

it('runs picking capture and popup failure behavior in node', function (): void {
    $process = new Process(['node', '--test', 'tests/Unit/production-picking-print.test.mjs'], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});

it('reads unconfirmed manual lot choices from the editing draft when printing', function (): void {
    $view = file_get_contents(resource_path('views/livewire/production-bench/production/stock-preparation.blade.php'));

    expect(strpos($view, 'x-data="productionEditing('))->toBeLessThan(strpos($view, 'x-data="productionPickingPrint()"'))
        ->and($view)->toContain(":data-picking-manual=\"value('allocations', 'manualMode.{{ \$requirement->id }}') ? 'true' : 'false'\"")
        ->and($view)->toContain('<input data-picking-manual-lot="{{ $lotRow[\'lot\']->id }}" type="text" inputmode="decimal" wire:ignore :value="value(\'allocations\'');
});
