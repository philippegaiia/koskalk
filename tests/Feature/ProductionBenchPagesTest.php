<?php

use App\Livewire\ProductionBench\HomeIndex;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\PurchasingIndex;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses factual production bench copy', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $this->actingAs($user)
        ->get(route('production-bench.home'))
        ->assertOk()
        ->assertSee('Active')
        ->assertSee('Quarantined')
        ->assertSee('Incoming')
        ->assertDontSee('Bench is active')
        ->assertDontSee('lots waiting for release')
        ->assertDontSee('orders still incoming')
        ->assertDontSee('Optional professional workspace')
        ->assertDontSee('Production without the ERP headache.')
        ->assertDontSee('Ready when your production grows')
        ->assertDontSee('next checkpoint');

    $this->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Inventory')
        ->assertSee('Physical includes quarantined stock.')
        ->assertSee('Negative balances are allowed.')
        ->assertDontSee('what production can actually use')
        ->assertDontSee('What is here, and what is usable.');
});

it('exposes the production bench as a separate authenticated workspace', function (): void {
    $this->get('/dashboard/production-bench')->assertRedirect('/login');

    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();

    $this->actingAs($user)
        ->get(route('production-bench.home'))
        ->assertOk()
        ->assertSee('Production Bench')
        ->assertSee('Activate Production Bench');

    $this->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Inactive')
        ->assertSee('Production Bench')
        ->assertDontSee('Activate the bench to start inventory.')
        ->assertDontSee('Go to Production Bench home');
});

it('activates and later preserves a read only bench', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user);

    Livewire::test(HomeIndex::class)
        ->call('activate')
        ->assertSee('Active')
        ->assertDontSee('Bench is active');

    expect(app(ProductionBenchAccess::class)->isActive($workspace))->toBeTrue();

    Livewire::test(HomeIndex::class)
        ->call('cancel')
        ->assertSee('Read-only');

    Livewire::test(InventoryIndex::class)
        ->assertSee('Read-only. Resume to edit.')
        ->assertDontSee('all stock history is preserved')
        ->assertDontSee('Resume the Production Bench to post changes.');

    expect(app(ProductionBenchAccess::class)->isReadOnly($workspace))->toBeTrue();
});

it('renders opening stock and purchasing workspaces', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    Ingredient::factory()->create(['display_name' => 'Olive oil']);

    $this->actingAs($user)
        ->get(route('production-bench.inventory'))
        ->assertOk()
        ->assertSee('Opening stock')
        ->assertSee('Stock positions')
        ->assertSee('Physical')
        ->assertSee('Available')
        ->assertDontSee('Add a lot already on your shelves')
        ->assertDontSee('No lots yet. Add the stock already on your shelves above.');

    $this->actingAs($user)
        ->get(route('production-bench.purchasing'))
        ->assertRedirectToRoute('production-bench.purchasing.suppliers');

    $this->actingAs($user)
        ->get(route('production-bench.purchasing.suppliers'))
        ->assertOk()
        ->assertSee('Suppliers')
        ->assertDontSee('Receive delivery');

    Livewire::test(InventoryIndex::class)->assertOk();
    Livewire::test(PurchasingIndex::class)->assertOk();
});
