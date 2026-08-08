<?php

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Enums\StockLotStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function activeProductionWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

function openingStockListing(Workspace $workspace, Ingredient|PackagingItem $subject): SupplierListing
{
    $supplier = Supplier::factory()->for($workspace)->create();

    if ($subject instanceof Ingredient) {
        return SupplierListing::factory()->for($workspace)->for($supplier)->for($subject)->create();
    }

    return SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->state([
            'ingredient_id' => null,
            'packaging_item_id' => $subject->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
        ])
        ->create();
}

it('posts mass opening stock in canonical grams and is idempotent', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $ingredient = Ingredient::factory()->create();
    $listing = openingStockListing($workspace, $ingredient);

    $action = app(CreateOpeningStockLot::class);
    $lot = $action->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '2.5',
        unit: 'lb',
        pricePerCanonicalUnit: '0.01',
        currency: 'EUR',
        idempotencyKey: 'opening-almond-oil',
        supplierBatchNumber: 'SUP-42',
    );
    $retriedLot = $action->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '2.5',
        unit: 'lb',
        pricePerCanonicalUnit: '0.01',
        currency: 'EUR',
        idempotencyKey: 'opening-almond-oil',
    );

    expect($retriedLot->is($lot))->toBeTrue()
        ->and($lot->unit_kind)->toBe(StockUnitKind::Mass)
        ->and($lot->status)->toBe(StockLotStatus::Released)
        ->and($lot->supplier_batch_number)->toBe('SUP-42')
        ->and($lot->provenance_complete)->toBeTrue()
        ->and($lot->supplier_listing_id)->toBe($listing->id)
        ->and($lot->internal_lot_code)->toMatch('/^SK-\d{6}-\d{4}$/')
        ->and($lot->movements)->toHaveCount(1)
        ->and($lot->movements->first()->quantity_delta)->toBe('1133.980925000')
        ->and($lot->movements->first()->original_quantity)->toBe('2.500000000')
        ->and($lot->movements->first()->original_unit)->toBe('lb')
        ->and(StockMovement::query()->count())->toBe(1);
});

it('requires positive whole counts for packaging opening stock', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $listing = openingStockListing($workspace, $packaging);

    expect(fn () => app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '12.5',
        unit: 'count',
        pricePerCanonicalUnit: '0.25',
        currency: 'EUR',
        idempotencyKey: 'opening-jars',
    ))->toThrow(ValidationException::class);
});

it('changes release state without rewriting stock history', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $ingredient = Ingredient::factory()->create();
    $listing = openingStockListing($workspace, $ingredient);
    $lot = app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '5',
        unit: 'kg',
        pricePerCanonicalUnit: '0.01',
        currency: 'EUR',
        idempotencyKey: 'opening-oil',
    );
    $movementId = $lot->movements()->sole()->id;

    app(QuarantineStockLot::class)->handle($owner, $lot, 'Retesting');
    expect($lot->refresh()->status)->toBe(StockLotStatus::Quarantined);

    app(ReleaseStockLot::class)->handle($owner, $lot, 'CoA checked');
    expect($lot->refresh()->status)->toBe(StockLotStatus::Released)
        ->and($lot->release_note)->toBe('CoA checked')
        ->and($lot->movements()->sole()->id)->toBe($movementId);
});

it('blocks opening stock mutations after cancellation', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $listing = openingStockListing($workspace, Ingredient::factory()->create());

    expect(fn () => app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '1',
        unit: 'kg',
        pricePerCanonicalUnit: '0.01',
        currency: 'EUR',
        idempotencyKey: 'blocked',
    ))->toThrow(ValidationException::class);
});
