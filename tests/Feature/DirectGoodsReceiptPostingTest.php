<?php

use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\PlacePurchaseOrder;
use App\Actions\Purchasing\PostGoodsReceiptLine;
use App\Actions\Purchasing\ReceiveDirectGoodsReceipt;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\GoodsReceiptSource;
use App\ListingPriceBasis;
use App\Models\CurrentMaterialPrice;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{User, Workspace, Supplier, Ingredient, SupplierListing, PackagingItem, SupplierListing}
 */
function directReceiptContext(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $ingredientListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'unit_kind' => StockUnitKind::Mass,
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'canonical_quantity_per_purchase_format' => '5000',
        ]);
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $packagingListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->create([
            'ingredient_id' => null,
            'packaging_item_id' => $packaging->id,
            'unit_kind' => StockUnitKind::Count,
            'net_quantity' => '100',
            'net_unit' => 'count',
            'canonical_quantity_per_purchase_format' => '100',
        ]);

    return [$owner, $workspace, $supplier, $ingredient, $ingredientListing, $packaging, $packagingListing];
}

it('posts direct ingredient and packaging lines through the same immutable inventory workflow', function (): void {
    [$owner, $workspace, $supplier, $ingredient, $ingredientListing, $packaging, $packagingListing] = directReceiptContext();

    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-delivery-one',
        lines: [
            [
                'listing' => $ingredientListing,
                'packs_received' => 2,
                'actual_quantity' => '9.8',
                'actual_unit' => 'kg',
                'receipt_price_basis' => ListingPriceBasis::PerUnit,
                'receipt_price_amount' => '12',
                'receipt_price_unit' => 'kg',
                'currency' => 'eur',
                'supplier_batch_number' => 'SHARED-BATCH',
                'expires_at' => '2027-08-03',
                'notes' => 'Ingredient line',
            ],
            [
                'listing' => $packagingListing,
                'packs_received' => 3,
                'actual_quantity' => '290',
                'actual_unit' => 'count',
                'receipt_price_basis' => ListingPriceBasis::PerUnit,
                'receipt_price_amount' => '0.25',
                'receipt_price_unit' => 'count',
                'currency' => 'EUR',
                'notes' => 'Packaging line',
            ],
        ],
        receivedAt: '2026-08-03',
        deliveryReference: 'SHOP-42',
        notes: 'Direct shop purchase',
    );

    $receipt->load('lines.stockLot.movements');
    $ingredientLine = $receipt->lines->firstWhere('supplier_listing_id', $ingredientListing->id);
    $packagingLine = $receipt->lines->firstWhere('supplier_listing_id', $packagingListing->id);

    expect($receipt->source)->toBe(GoodsReceiptSource::Direct)
        ->and($receipt->purchase_order_id)->toBeNull()
        ->and($receipt->supplier_id)->toBe($supplier->id)
        ->and($receipt->lines)->toHaveCount(2)
        ->and($ingredientLine->purchase_format_price)->toBe('60.000000000')
        ->and($ingredientLine->historical_total_cost)->toBe('120.000000000')
        ->and($ingredientLine->stockLot->historical_unit_cost)->toBe('0.012244898')
        ->and($ingredientLine->stockLot->status)->toBe(StockLotStatus::Released)
        ->and($ingredientLine->stockLot->movements->sole()->type)->toBe(StockMovementType::PurchaseReceipt)
        ->and($packagingLine->purchase_format_price)->toBe('25.000000000')
        ->and($packagingLine->historical_total_cost)->toBe('75.000000000')
        ->and($packagingLine->stockLot->historical_unit_cost)->toBe('0.258620690')
        ->and($packagingLine->stockLot->internal_lot_code)
        ->not->toBe($ingredientLine->stockLot->internal_lot_code)
        ->and(CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole()->price_per_canonical_unit)
        ->toBe('0.012244898000')
        ->and(CurrentMaterialPrice::query()->where('packaging_item_id', $packaging->id)->sole()->price_per_canonical_unit)
        ->toBe('0.258620690000');

    expect(app(StockPositionService::class)->forWorkspaceSubject($workspace, $ingredient))
        ->physical->toBe('9800.000000000')
        ->available->toBe('9800.000000000')
        ->incoming->toBe('0.000000000')
        ->and(app(StockPositionService::class)->forWorkspaceSubject($workspace, $packaging))
        ->physical->toBe('290.000000000')
        ->available->toBe('290.000000000')
        ->incoming->toBe('0.000000000');
});

it('keeps the supplier currency and snapshots the workspace costing currency', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::response([
            'base' => 'USD',
            'quote' => 'EUR',
            'date' => '2026-08-03',
            'rate' => 0.91,
        ]),
    ]);

    [$owner, $workspace, $supplier, $ingredient] = directReceiptContext();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'currency' => 'USD',
            'price_amount' => '100',
            'total_price' => '100',
        ]);

    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-usd-to-eur',
        lines: [[
            'listing' => $listing,
            'packs_received' => 2,
            'actual_quantity' => '10',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '100',
            'currency' => 'USD',
        ]],
        receivedAt: '2026-08-03',
    );

    $line = $receipt->lines()->with('stockLot')->sole();

    expect($line->currency)->toBe('USD')
        ->and($line->historical_total_cost)->toBe('200.000000000')
        ->and($line->costing_total_cost)->toBe('182.000000000')
        ->and($line->costing_currency)->toBe('EUR')
        ->and($line->exchange_rate)->toBe('0.910000000000')
        ->and($line->exchange_rate_date->toDateString())->toBe('2026-08-03')
        ->and($line->exchange_rate_provider)->toBe('frankfurter')
        ->and($line->exchange_rate_is_manual)->toBeFalse()
        ->and($line->stockLot->historical_unit_cost)->toBe('0.020000000')
        ->and($line->stockLot->costing_unit_cost)->toBe('0.018200000')
        ->and($line->stockLot->costing_currency)->toBe('EUR')
        ->and(CurrentMaterialPrice::query()->where('ingredient_id', $ingredient->id)->sole()->currency)
        ->toBe('EUR');

    Http::assertSentCount(1);
});

it('surfaces an unavailable exchange-rate provider as a validation error', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::failedConnection('provider unavailable'),
    ]);

    [$owner, $workspace, $supplier, $ingredient] = directReceiptContext();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'currency' => 'USD',
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'canonical_quantity_per_purchase_format' => '5000',
        ]);

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-provider-unavailable',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '100',
            'currency' => 'USD',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('uses a manual exchange rate when the provider is unavailable', function (): void {
    Http::fake([
        'https://api.frankfurter.dev/v2/rate/USD/EUR*' => Http::failedConnection('provider unavailable'),
    ]);

    [$owner, $workspace, $supplier, $ingredient] = directReceiptContext();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create([
            'currency' => 'USD',
            'net_quantity' => '5',
            'net_unit' => 'kg',
            'canonical_quantity_per_purchase_format' => '5000',
        ]);

    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-manual-rate',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '100',
            'currency' => 'USD',
            'manual_exchange_rate' => '0.9',
        ]],
        receivedAt: '2026-08-03',
    );

    $line = $receipt->lines()->with('stockLot')->sole();

    expect($line->exchange_rate)->toBe('0.900000000000')
        ->and($line->exchange_rate_provider)->toBe('manual')
        ->and($line->exchange_rate_is_manual)->toBeTrue()
        ->and($line->stockLot->exchange_rate)->toBe('0.900000000000');
});

it('returns the original direct receipt when the same submission is retried', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $arguments = [
        'actor' => $owner,
        'workspace' => $workspace,
        'supplier' => $supplier,
        'idempotencyKey' => 'direct-retry',
        'lines' => [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        'receivedAt' => '2026-08-03',
    ];

    $first = app(ReceiveDirectGoodsReceipt::class)->handle(...$arguments);
    $second = app(ReceiveDirectGoodsReceipt::class)->handle(...$arguments);

    expect($second->is($first))->toBeTrue()
        ->and(GoodsReceipt::query()->count())->toBe(1)
        ->and(StockLot::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('rejects a direct submission key already used by another supplier', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $firstReceipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-supplier-collision',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $otherSupplier = Supplier::factory()->for($workspace)->create();
    $otherListing = SupplierListing::factory()
        ->for($workspace)
        ->for($otherSupplier)
        ->for(Ingredient::factory()->create())
        ->create();

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $otherSupplier,
        idempotencyKey: 'direct-supplier-collision',
        lines: [[
            'listing' => $otherListing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->sole()->is($firstReceipt))->toBeTrue()
        ->and(StockLot::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('rejects a direct submission key already used by a purchase order receipt', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $order = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [['listing' => $listing, 'packs' => 1]],
    );
    app(PlacePurchaseOrder::class)->handle($owner, $order);
    $orderReceipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'direct-source-collision',
        deliveryReference: null,
        lines: [[
            'order_line' => $order->lines()->sole(),
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-source-collision',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->sole()->is($orderReceipt))->toBeTrue()
        ->and(StockLot::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(1);
});

it('rejects foreign inactive or supplier-mismatched direct listings atomically', function (string $invalidListing): void {
    [$owner, $workspace, $supplier, , $validListing] = directReceiptContext();
    $otherWorkspace = Workspace::factory()->create();
    $otherSupplier = Supplier::factory()->for($workspace)->create();
    $listing = match ($invalidListing) {
        'workspace' => SupplierListing::factory()->for($otherWorkspace)->create(),
        'supplier' => SupplierListing::factory()->for($workspace)->for($otherSupplier)->create(),
        'inactive' => SupplierListing::factory()
            ->for($workspace)
            ->for($supplier)
            ->for($validListing->ingredient)
            ->create(['is_active' => false]),
    };

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-invalid-'.$invalidListing,
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
})->with(['workspace', 'supplier', 'inactive']);

it('rolls back every line when one direct packaging line has a fractional actual count', function (): void {
    [$owner, $workspace, $supplier, , $ingredientListing, , $packagingListing] = directReceiptContext();

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-atomic-failure',
        lines: [
            [
                'listing' => $ingredientListing,
                'packs_received' => 1,
                'actual_quantity' => '5',
                'actual_unit' => 'kg',
                'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
                'receipt_price_amount' => '50',
                'currency' => 'EUR',
            ],
            [
                'listing' => $packagingListing,
                'packs_received' => 1,
                'actual_quantity' => '99.5',
                'actual_unit' => 'count',
                'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
                'receipt_price_amount' => '25',
                'currency' => 'EUR',
            ],
        ],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class, 'Packaging receipts require a positive whole number of units.');

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(CurrentMaterialPrice::query()->count())->toBe(0);
});

it('requires a receipt date valid currency and positive receipt price', function (?string $date, string $currency, string $price): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-required-'.md5((string) $date.$currency.$price),
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => $price,
            'currency' => $currency,
        ]],
        receivedAt: $date,
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0);
})->with([
    'missing date' => [null, 'EUR', '50'],
    'blank date' => ['', 'EUR', '50'],
    'invalid calendar date' => ['2026-02-31', 'EUR', '50'],
    'invalid currency' => ['2026-08-03', 'EU', '50'],
    'zero price' => ['2026-08-03', 'EUR', '0'],
    'negative price' => ['2026-08-03', 'EUR', '-1'],
]);

it('blocks direct posting after the production bench becomes read only', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-read-only',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('validates direct receipt keys and hashes maximum keys into bounded movement keys', function (string $key, bool $valid): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $post = fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: $key,
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );

    if (! $valid) {
        expect($post)->toThrow(ValidationException::class)
            ->and(GoodsReceipt::query()->count())->toBe(0);

        return;
    }

    $receipt = $post();
    $retried = $post();
    $movementKey = $receipt->lines()->sole()->stockLot->movements()->sole()->idempotency_key;

    expect($retried->is($receipt))->toBeTrue()
        ->and(strlen($movementKey))->toBeLessThanOrEqual(120)
        ->and($movementKey)->toStartWith('receipt-line:')
        ->and(StockMovement::query()->count())->toBe(1);
})->with([
    'blank' => ['', false],
    'whitespace' => ['   ', false],
    'too long' => [str_repeat('k', 121), false],
    'maximum boundary' => [str_repeat('k', 120), true],
]);

it('rejects unsupported or listing-mismatched direct receipt currencies', function (string $currency): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-currency-'.$currency,
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => $currency,
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
})->with(['ZZZ', 'USD']);

it('rejects a stale fractional count purchase format instead of truncating it', function (): void {
    [$owner, $workspace, $supplier, , , , $listing] = directReceiptContext();
    DB::table($listing->getTable())->where('id', $listing->id)->update(['net_quantity' => '100.5']);

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'fractional-listing-count',
        lines: [[
            'listing' => $listing->refresh(),
            'packs_received' => 1,
            'actual_quantity' => '100',
            'actual_unit' => 'count',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '25',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('rejects forged listings that point to another workspaces private subjects', function (string $subjectType): void {
    [$owner, $workspace, $supplier] = directReceiptContext();
    $foreignWorkspace = Workspace::factory()->create();

    if ($subjectType === 'ingredient') {
        $foreignSubject = Ingredient::factory()->create([
            'owner_type' => OwnerType::Workspace,
            'owner_id' => $foreignWorkspace->id,
            'workspace_id' => $foreignWorkspace->id,
            'visibility' => Visibility::Workspace,
        ]);
        $listing = SupplierListing::factory()
            ->for($workspace)
            ->for($supplier)
            ->for($foreignSubject)
            ->create();
    } else {
        $foreignSubject = PackagingItem::factory()->for($foreignWorkspace)->create();
        $listing = SupplierListing::factory()
            ->for($workspace)
            ->for($supplier)
            ->create([
                'ingredient_id' => null,
                'packaging_item_id' => $foreignSubject->id,
                'unit_kind' => StockUnitKind::Count,
                'net_quantity' => '10',
                'net_unit' => 'count',
                'canonical_quantity_per_purchase_format' => '10',
            ]);
    }

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'foreign-direct-subject-'.$subjectType,
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => $subjectType === 'ingredient' ? '5' : '10',
            'actual_unit' => $subjectType === 'ingredient' ? 'kg' : 'count',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '50',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0);
})->with(['ingredient', 'packaging']);

it('keeps the supplier listing price independent from immutable receipt and lot costs', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $listingPriceSnapshot = $listing->only([
        'price_basis',
        'price_amount',
        'price_unit',
        'total_price',
        'currency',
    ]);
    $listingPriceRecordedAt = $listing->price_recorded_at;

    $receipt = app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'direct-listing-projection',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '4.8',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::PerUnit,
            'receipt_price_amount' => '12',
            'receipt_price_unit' => 'kg',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    );
    $line = $receipt->lines()->sole();

    expect($listing->refresh()->only(array_keys($listingPriceSnapshot)))->toBe($listingPriceSnapshot)
        ->and($listing->price_recorded_at?->equalTo($listingPriceRecordedAt))->toBeTrue()
        ->and($line->receipt_price_amount)->toBe('12.000000000')
        ->and($line->purchase_format_price)->toBe('60.000000000')
        ->and($line->historical_total_cost)->toBe('60.000000000')
        ->and($line->stockLot->historical_unit_cost)->toBe('0.012500000');

    $listing->update([
        'price_amount' => '99',
        'total_price' => '99',
    ]);

    expect($line->refresh()->receipt_price_amount)->toBe('12.000000000')
        ->and($line->purchase_format_price)->toBe('60.000000000')
        ->and($line->stockLot->refresh()->historical_unit_cost)->toBe('0.012500000');
});

it('validates direct receipt metadata before any write', function (string $case): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $line = [
        'listing' => $listing,
        'packs_received' => 1,
        'actual_quantity' => '5',
        'actual_unit' => 'kg',
        'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'receipt_price_amount' => '50',
        'currency' => 'EUR',
    ];
    $deliveryReference = null;
    $notes = null;

    match ($case) {
        'expiry' => $line['expires_at'] = '2026-02-31',
        'supplier batch' => $line['supplier_batch_number'] = str_repeat('b', 121),
        'line notes' => $line['notes'] = str_repeat('n', 5001),
        'delivery reference' => $deliveryReference = str_repeat('r', 256),
        'receipt notes' => $notes = str_repeat('n', 5001),
    };

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'invalid-direct-metadata-'.$case,
        lines: [$line],
        receivedAt: '2026-08-03',
        deliveryReference: $deliveryReference,
        notes: $notes,
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0);
})->with(['expiry', 'supplier batch', 'line notes', 'delivery reference', 'receipt notes']);

it('defensively rejects mismatched inputs to the shared line poster before inventory writes', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $foreignWorkspace = Workspace::factory()->create();
    $receipt = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create();

    expect(fn () => app(PostGoodsReceiptLine::class)->handle(
        actor: $owner,
        workspace: $foreignWorkspace,
        receipt: $receipt,
        listing: $listing,
        purchaseOrderLine: null,
        packsReceived: 1,
        actualQuantity: '5000',
        originalQuantity: '5',
        originalUnit: 'kg',
        receiptPriceBasis: ListingPriceBasis::TotalPurchaseFormat,
        receiptPriceAmount: '50',
        receiptPriceUnit: null,
        purchaseFormatPrice: '50',
        currency: 'EUR',
        movementIdempotencyKey: 'bounded-key',
    ))->toThrow(ValidationException::class);

    expect($receipt->lines()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rejects a historical unit cost that rounds to zero without partial writes', function (): void {
    [$owner, $workspace, $supplier, , $listing] = directReceiptContext();
    $listingPriceBefore = $listing->only([
        'price_basis',
        'price_amount',
        'price_unit',
        'total_price',
        'currency',
    ]);
    $priceRecordedAtBefore = $listing->price_recorded_at;

    expect(fn () => app(ReceiveDirectGoodsReceipt::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        idempotencyKey: 'rounded-zero-historical-cost',
        lines: [[
            'listing' => $listing,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'receipt_price_amount' => '0.000000001',
            'currency' => 'EUR',
        ]],
        receivedAt: '2026-08-03',
    ))->toThrow(ValidationException::class);

    expect(GoodsReceipt::query()->count())->toBe(0)
        ->and(StockLot::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(CurrentMaterialPrice::query()->count())->toBe(0)
        ->and($listing->refresh()->only(array_keys($listingPriceBefore)))->toBe($listingPriceBefore)
        ->and($listing->price_recorded_at?->equalTo($priceRecordedAtBefore))->toBeTrue();
});
