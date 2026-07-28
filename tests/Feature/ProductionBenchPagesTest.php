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

it('exposes the production bench as a separate authenticated workspace', function (): void {
    $this->get('/dashboard/production-bench')->assertRedirect('/login');

    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create();

    $this->actingAs($user)
        ->get(route('production-bench.home'))
        ->assertOk()
        ->assertSee('Production Bench')
        ->assertSee('Activate Production Bench');
});

it('activates and later preserves a read only bench', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $this->actingAs($user);

    Livewire::test(HomeIndex::class)
        ->call('activate')
        ->assertSee('Bench is active');

    expect(app(ProductionBenchAccess::class)->isActive($workspace))->toBeTrue();

    Livewire::test(HomeIndex::class)
        ->call('cancel')
        ->assertSee('Read-only');

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
        ->assertSee('Physical')
        ->assertSee('Available');

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
