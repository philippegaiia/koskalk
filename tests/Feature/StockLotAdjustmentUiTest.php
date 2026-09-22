<?php

use App\Enums\StockMovementType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('adjusts a lot from the register without enabling locations', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    expect($workspace->uses_storage_locations)->toBeFalse();
    $this->actingAs($actor);

    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSeeHtml('data-sticky-table-right')
        ->assertSeeHtml('sticky right-0 z-20')
        ->assertActionVisible('adjustStock', ['lot_id' => $lot->id]);
    $component->call('mountAction', 'adjustStock', ['lot_id' => $lot->id]);
    $component->assertStatus(200);
    $component
        ->fillForm([
            'mode' => 'set_counted',
            'quantity' => '0.9',
            'unit' => 'kg',
            'reason' => 'measurement_difference',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertDispatched('app-notification');

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->value('quantity_delta'))
        ->toBe('-100.000000000');
});

it('returns the lot register to its first page when an adjustment exhausts the only lot on page two', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    StockLot::factory()
        ->count(25)
        ->for($workspace)
        ->for($ingredient)
        ->released()
        ->create()
        ->each(fn (StockLot $otherLot) => StockMovement::factory()->for($otherLot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
        ]));
    $this->actingAs($actor);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('perPage', 25)
        ->call('gotoPage', 2, 'materials')
        ->call('gotoPage', 2, 'stock-lots')
        ->assertSet('paginators.stock-lots', 2)
        ->callAction('adjustStock', [
            'mode' => 'set_counted',
            'quantity' => '0',
            'unit' => 'g',
            'reason' => 'measurement_difference',
        ], ['lot_id' => $lot->id])
        ->assertHasNoFormErrors()
        ->assertSet('paginators.stock-lots', 1)
        ->assertSet('paginators.materials', 2);
});

it('adjusts an open lot from material detail and refreshes history', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionVisible('adjustStock', ['lot_id' => $lot->id])
        ->mountAction('adjustStock', ['lot_id' => $lot->id])
        ->fillForm([
            'mode' => 'add',
            'quantity' => '50',
            'unit' => 'g',
            'reason' => 'entry_error',
            'note' => '<script>audit</script>',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertSee('Entry error')
        ->assertSee($actor->name)
        ->assertSee('&lt;script&gt;audit&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>audit</script>', escape: false);
});

it('returns material activity to its first page and shows a saved adjustment', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    StockMovement::factory()
        ->count(25)
        ->for($lot, 'stockLot')
        ->create(['workspace_id' => $workspace->id]);
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->set('perPage', 25)
        ->call('gotoPage', 2, 'supplier-listings')
        ->call('gotoPage', 2, 'activity')
        ->assertSet('paginators.activity', 2)
        ->callAction('adjustStock', [
            'mode' => 'add',
            'quantity' => '50',
            'unit' => 'g',
            'reason' => 'entry_error',
        ], ['lot_id' => $lot->id])
        ->assertHasNoFormErrors()
        ->assertSet('paginators.activity', 1)
        ->assertSet('paginators.supplier-listings', 2)
        ->assertSee('Entry error');
});

it('rejects a lot for another material on the material detail page', function (): void {
    [$actor, $workspace, $ingredient] = adjustmentUiFixture();
    $foreignIngredient = Ingredient::factory()->create();
    $foreignLot = StockLot::factory()->for($workspace)->for($foreignIngredient)->released()->create();
    StockMovement::factory()->for($foreignLot, 'stockLot')->create(['workspace_id' => $workspace->id]);
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->assertActionHidden('adjustStock', ['lot_id' => $foreignLot->id]);

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->count())->toBe(0);
});

it('hides adjustment actions from viewers', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $this->actingAs($viewer);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertActionHidden('adjustStock', ['lot_id' => $lot->id]);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->assertActionHidden('adjustStock', ['lot_id' => $lot->id]);
});

it('does not offer stock adjustment for finished-product lots', function (): void {
    [$actor, $workspace] = adjustmentUiFixture();
    $productLot = StockLot::factory()->for($workspace)->forRecipe()->released()->create([
        'internal_lot_code' => 'FINISHED-LOT',
    ]);
    StockMovement::factory()->for($productLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '10.000000000',
        'original_unit' => 'count',
    ]);
    $this->actingAs($actor);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->assertSee('FINISHED-LOT')
        ->assertActionHidden('adjustStock', ['lot_id' => $productLot->id]);
});

it('hides the stale adjustment notice when the modal is fresh', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->mountAction('adjustStock', ['lot_id' => $lot->id])
        ->assertSchemaComponentHidden('stale_notice');
});

it('refreshes a stale adjustment while retaining input and clearing acknowledgement', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $this->actingAs($actor);
    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->call('gotoPage', 2, 'activity')
        ->mountAction('adjustStock', ['lot_id' => $lot->id])
        ->fillForm([
            'mode' => 'remove',
            'quantity' => '100',
            'unit' => 'g',
            'reason' => 'spillage',
            'shortage_acknowledged' => true,
        ]);

    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '-10.000000000',
    ]);

    $component->callMountedAction()
        ->assertHasFormErrors(['mode'])
        ->assertSet('stockAdjustmentStale', true)
        ->assertSchemaComponentVisible('stale_notice')
        ->assertSet('paginators.activity', 2)
        ->assertSchemaStateSet([
            'mode' => 'remove',
            'quantity' => '100',
            'reason' => 'spillage',
            'shortage_acknowledged' => false,
        ]);

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->count())->toBe(0);
});

it('renders a no-change validation error on the quantity field', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->mountAction('adjustStock', ['lot_id' => $lot->id])
        ->fillForm([
            'mode' => 'set_counted',
            'quantity' => '1000',
            'unit' => 'g',
            'reason' => 'measurement_difference',
        ])
        ->callMountedAction()
        ->assertHasFormErrors(['quantity']);

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->count())->toBe(0);
});

it('posts a removal from material detail', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->callAction('adjustStock', [
        'mode' => 'remove',
        'quantity' => '100',
        'unit' => 'g',
        'reason' => 'spillage',
    ], ['lot_id' => $lot->id])
        ->assertHasNoFormErrors();

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->value('quantity_delta'))
        ->toBe('-100.000000000');
});

it('shows a quantified reservation shortage and clears acknowledgement when input changes', function (): void {
    [$actor, $workspace, $ingredient, $lot] = adjustmentUiFixture();
    StockReservation::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity' => '900.000000000',
        'created_by_user_id' => $actor->id,
    ]);
    $this->actingAs($actor);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->mountAction('adjustStock', ['lot_id' => $lot->id])
        ->fillForm([
            'mode' => 'set_counted',
            'quantity' => '800',
            'unit' => 'g',
            'reason' => 'measurement_difference',
            'shortage_acknowledged' => true,
        ])
        ->assertSchemaComponentVisible('shortage_warning')
        ->assertSchemaComponentExists('shortage_warning', null, fn ($component): bool => str_contains(
            (string) $component->toHtml(),
            '100 g',
        ))
        ->set('mountedActions.0.data.quantity', '700')
        ->assertSchemaStateSet(['shortage_acknowledged' => false]);
});

/** @return array{User, Workspace, Ingredient, StockLot} */
function adjustmentUiFixture(): array
{
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create([
        'uses_storage_locations' => false,
    ]);
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $actor->update(['active_workspace_id' => $workspace->id]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Adjustment oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'ADJUST-LOT',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '1000.000000000',
    ]);

    return [$actor, $workspace, $ingredient, $lot];
}

it('renders lot action controls without per-lot database queries', function (int $perPage): void {
    [$actor, $workspace, $ingredient] = adjustmentUiFixture();
    $workspace->update(['uses_storage_locations' => true]);
    StockLot::factory()->count($perPage - 1)->for($workspace)->for($ingredient)->released()->create()
        ->each(fn (StockLot $lot) => StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'quantity_delta' => '1000.000000000',
        ]));
    $this->actingAs($actor);
    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $component->set('perPage', $perPage)
            ->assertViewHas('lots', fn ($lots): bool => $lots->count() === $perPage)
            ->assertSee(__('production_bench.inventory.adjustment.action'));
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'workspace_production_entitlements'))->count())
        ->toBeLessThanOrEqual(6)
        ->and($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "stock_lots" where') && str_contains($query['query'], '"stock_lots"."id" ='))->count())
        ->toBe(0);
})->with([25, 100]);

it('renders material lot controls without per-lot database queries', function (int $lotCount): void {
    [$actor, $workspace, $ingredient] = adjustmentUiFixture();
    $workspace->update(['uses_storage_locations' => true]);
    StockLot::factory()->count($lotCount - 1)->for($workspace)->for($ingredient)->released()->create()
        ->each(fn (StockLot $lot) => StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'quantity_delta' => '1000.000000000',
        ]));
    $this->actingAs($actor);

    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        Livewire::test(InventoryMaterialDetail::class, [
            'subject' => $ingredient->public_id,
            'subjectType' => 'ingredient',
        ])
            ->assertViewHas('openLots', fn ($lots): bool => $lots->count() === min($lotCount, 10))
            ->assertSee(__('production_bench.inventory.adjustment.action'));
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], 'workspace_production_entitlements'))->count())
        ->toBeLessThanOrEqual(7)
        ->and($queries->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "stock_lots" where') && str_contains($query['query'], '"stock_lots"."id" ='))->count())
        ->toBe(0);
})->with([10, 100]);
