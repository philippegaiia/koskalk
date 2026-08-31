<?php

use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\Workspace;
use App\Services\Inventory\WorkspaceMaterialSupplierListingsQuery;
use App\Enums\WorkspaceMemberRole;
use App\Models\WorkspaceMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('inventoryReadActor')) {
    /**
     * Returns a user who is a member of the workspace, creating an owner when
     * the workspace has no members yet. Used to satisfy the actor-first
     * signature of the inventory read services under test.
     */
    function inventoryReadActor(Workspace $workspace): User
    {
        $member = $workspace->users()->first();

        if ($member instanceof User) {
            return $member;
        }

        $actor = User::factory()->create();
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => WorkspaceMemberRole::Owner]);

        return $actor;
    }
}

it('paginates only supplier listings for the workspace material', function (): void {
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    $active = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['is_active' => true]);
    $inactive = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['is_active' => false]);
    SupplierListing::factory()->for($otherWorkspace)->for(Supplier::factory()->for($otherWorkspace))->for($ingredient)->create();

    $page = app(WorkspaceMaterialSupplierListingsQuery::class)
        ->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 10, pageName: 'supplier-listings');

    expect($page->pluck('id')->all())->toBe([$active->id, $inactive->id]);
});

it('scopes packaging listings to the packaging subject', function (): void {
    $workspace = Workspace::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Box Co']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Amber bottle']);
    $otherPackaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Pump cap']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $packaging->id,
        'unit_kind' => StockUnitKind::Count,
        'purchase_format' => 'Box of 100 units',
        'canonical_quantity_per_purchase_format' => '100',
        'net_quantity' => '100',
        'net_unit' => 'count',
    ]);
    SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $otherPackaging->id,
        'unit_kind' => StockUnitKind::Count,
        'purchase_format' => 'Box of 500 units',
        'canonical_quantity_per_purchase_format' => '500',
        'net_quantity' => '500',
        'net_unit' => 'count',
    ]);

    $page = app(WorkspaceMaterialSupplierListingsQuery::class)
        ->paginate(inventoryReadActor($workspace), $workspace, $packaging, perPage: 10, pageName: 'supplier-listings');

    expect($page->pluck('id')->all())->toBe([$listing->id]);
});

it('orders active listings first, then by supplier name', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $zeta = Supplier::factory()->for($workspace)->create(['name' => 'Zeta Supply']);
    $alpha = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    $activeZeta = SupplierListing::factory()->for($workspace)->for($zeta)->for($ingredient)->create(['is_active' => true]);
    $inactiveAlpha = SupplierListing::factory()->for($workspace)->for($alpha)->for($ingredient)->create(['is_active' => false]);
    $activeAlpha = SupplierListing::factory()->for($workspace)->for($alpha)->for($ingredient)->create(['is_active' => true]);

    $page = app(WorkspaceMaterialSupplierListingsQuery::class)
        ->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 10, pageName: 'supplier-listings');

    expect($page->pluck('id')->all())->toBe([$activeAlpha->id, $activeZeta->id, $inactiveAlpha->id]);
});

it('paginates supplier listings without leaking into the other paginators', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->count(12)
        ->create();

    $query = app(WorkspaceMaterialSupplierListingsQuery::class);

    $firstPage = $query->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 10, pageName: 'supplier-listings', page: 1);
    $secondPage = $query->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 10, pageName: 'supplier-listings', page: 2);

    expect($firstPage->count())->toBe(10)
        ->and($firstPage->total())->toBe(12)
        ->and($firstPage->lastPage())->toBe(2)
        ->and($secondPage->count())->toBe(2)
        ->and($firstPage->pluck('id')->all())->not->toBe($secondPage->pluck('id')->all())
        ->and($firstPage->getPageName())->toBe('supplier-listings');
});

it('clamps a per-page value that is not offered', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    $query = app(WorkspaceMaterialSupplierListingsQuery::class);

    expect($query->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 100000, pageName: 'supplier-listings')->perPage())->toBe(10)
        ->and($query->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 25, pageName: 'supplier-listings')->perPage())->toBe(25);
});

it('eager loads the supplier so the listing table needs no extra queries', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();

    $page = app(WorkspaceMaterialSupplierListingsQuery::class)
        ->paginate(inventoryReadActor($workspace), $workspace, $ingredient, perPage: 10, pageName: 'supplier-listings');

    expect($page->first()->relationLoaded('supplier'))->toBeTrue()
        ->and($page->first()->supplier->name)->toBe('Alpha Oils');
});

it('rejects supplier listing reads from a user outside the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $outsider = User::factory()->create();

    expect(fn () => app(WorkspaceMaterialSupplierListingsQuery::class)->paginate(
        $outsider,
        $workspace,
        $ingredient,
    ))->toThrow(AuthorizationException::class);
});
