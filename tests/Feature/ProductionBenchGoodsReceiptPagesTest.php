<?php

use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Enums\GoodsReceiptSource;
use App\Enums\ListingPriceBasis;
use App\Enums\ProcurementStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockUnitKind;
use App\Livewire\ProductionBench\Purchasing\ReceiptCreate;
use App\Livewire\ProductionBench\Purchasing\ReceiptDetail;
use App\Livewire\ProductionBench\Purchasing\ReceiptIndex;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function receiptPageWorkspace(bool $readOnly = false): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $access = app(ProductionBenchAccess::class);
    $access->activate($user, $workspace);

    if ($readOnly) {
        $access->cancel($user, $workspace);
    }

    return [$user, $workspace];
}

function outstandingReceiptOrder(User $user, Workspace $workspace, int $orderedPacks = 3): array
{
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create();
    $order = PurchaseOrder::factory()
        ->for($workspace)
        ->for($supplier)
        ->create([
            'stage' => ProcurementStage::PurchaseOrder,
            'status' => PurchaseOrderStatus::Ordered,
            'issued_at' => now(),
            'created_by_user_id' => $user->id,
        ]);
    $line = PurchaseOrderLine::factory()
        ->for($order)
        ->for($listing, 'supplierListing')
        ->create([
            'ingredient_id' => $listing->ingredient_id,
            'ordered_packs' => $orderedPacks,
            'expected_quantity' => bcmul($listing->canonical_quantity_per_purchase_format, (string) $orderedPacks, 9),
        ]);

    return [$supplier, $listing, $order, $line];
}

it('exposes authenticated workspace-scoped receipt routes and navigation', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $foreignReceipt = GoodsReceipt::factory()->create();

    $this->get(route('production-bench.purchasing.receipts'))->assertRedirect(route('login'));

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.receipts'))
        ->assertOk()
        ->assertSee('Receipts')
        ->assertSeeHtml('aria-label="Purchasing sections"')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.receipts').'"');

    $this->get(route('production-bench.purchasing.receipts.show', $foreignReceipt))->assertNotFound();
});

it('shows a useful empty state and hides mutation controls in read-only mode', function (): void {
    [$owner] = receiptPageWorkspace();

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.receipts'))
        ->assertOk()
        ->assertSee('No receipts yet')
        ->assertSee('Receive purchase order')
        ->assertSee('Direct receipt');

    [$readOnlyOwner] = receiptPageWorkspace(readOnly: true);

    $this->actingAs($readOnlyOwner)
        ->get(route('production-bench.purchasing.receipts'))
        ->assertOk()
        ->assertSee('Read-only')
        ->assertDontSeeHtml('data-receipt-create-action');

    $this->get(route('production-bench.purchasing.receipts.create'))->assertForbidden();
});

it('lists newest receipts with source supplier order and line count', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order] = outstandingReceiptOrder($owner, $workspace);
    $older = GoodsReceipt::factory()->for($workspace)->for($supplier)->for($order, 'purchaseOrder')->create([
        'delivery_reference' => 'OLD-DELIVERY',
        'received_at' => '2026-08-01',
        'created_at' => now()->subDay(),
    ]);
    $newer = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create([
        'delivery_reference' => 'NEW-DELIVERY',
        'received_at' => '2026-08-02',
        'created_at' => now(),
    ]);
    GoodsReceiptLine::factory()->count(2)->direct()->for($newer)->create();

    $response = $this->actingAs($owner)->get(route('production-bench.purchasing.receipts'));

    $response->assertOk()
        ->assertSeeInOrder(['NEW-DELIVERY', 'OLD-DELIVERY'])
        ->assertSee($supplier->name)
        ->assertSee($order->reference)
        ->assertSee('Direct')
        ->assertSee('2026-08-02')
        ->assertSee('Posted')
        ->assertSee('2');
});

it('paginates receipts twenty at a time in newest-first order', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();

    foreach (range(1, 21) as $day) {
        GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create([
            'delivery_reference' => sprintf('PAGED-%02d', $day),
            'received_at' => sprintf('2026-07-%02d', $day),
        ]);
    }

    $this->actingAs($owner);

    Livewire::test(ReceiptIndex::class)
        ->assertSee('PAGED-21')
        ->assertDontSee('PAGED-01')
        ->call('setPage', 2)
        ->assertSee('PAGED-01')
        ->assertDontSee('PAGED-21');
});

it('only offers issued purchase orders with outstanding lines and preselects a linked order', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $outstandingOrder, $outstandingLine] = outstandingReceiptOrder($owner, $workspace);
    [, , $draftOrder] = outstandingReceiptOrder($owner, $workspace);
    $draftOrder->update(['status' => PurchaseOrderStatus::Draft, 'issued_at' => null]);
    [, , $receivedOrder] = outstandingReceiptOrder($owner, $workspace);
    $receivedOrder->update(['status' => PurchaseOrderStatus::Received]);

    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $outstandingOrder->public_id])
        ->test(ReceiptCreate::class)
        ->assertSet('source', GoodsReceiptSource::PurchaseOrder->value)
        ->assertSet('orderPublicId', $outstandingOrder->public_id)
        ->assertSee($outstandingOrder->reference)
        ->assertSee((string) $outstandingLine->ordered_packs)
        ->assertSee('Previously received')
        ->assertSee('Remaining')
        ->assertDontSee($draftOrder->reference)
        ->assertDontSee($receivedOrder->reference)
        ->assertSeeHtml('data-receipt-editable-lines')
        ->assertSeeHtml('wire:confirm=')
        ->assertSeeHtml('readonly');
});

it('defaults purchase-order receipt quantities to the full outstanding amount', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace, 5);
    $line->update(['price_amount' => '19.000000000']);
    app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 2,
            'actual_quantity' => '10',
            'actual_unit' => 'kg',
        ]],
    );
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class);

    expect($component->get("lineInputs.{$line->id}.packs_received"))->toBe(3)
        ->and($component->get("lineInputs.{$line->id}.actual_quantity"))->toBe('15.00')
        ->and($component->get("lineInputs.{$line->id}.receipt_price_amount"))->toBe('19.00');

    $component
        ->set("lineInputs.{$line->id}.receipt_price_basis", ListingPriceBasis::PerUnit->value);

    expect($component->get("lineInputs.{$line->id}.receipt_price_unit"))->toBe($line->supplierListing->net_unit);

    $component
        ->set("lineInputs.{$line->id}.receipt_price_basis", ListingPriceBasis::TotalPurchaseFormat->value);

    expect($component->get("lineInputs.{$line->id}.receipt_price_unit"))->toBeNull();
});

it('keeps missing receipt prices blank and preserves four decimal prices', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $line->update([
        'price_amount' => null,
        'pack_price' => null,
    ]);
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class);

    expect($component->get("lineInputs.{$line->id}.receipt_price_amount"))->toBeNull();

    $line->update(['price_amount' => '0.004200000']);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->assertSet("lineInputs.{$line->id}.receipt_price_amount", '0.0042');
});

it('renders each purchase-order item as three aligned form rows and disables completed items', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order, $availableLine] = outstandingReceiptOrder($owner, $workspace, 3);
    $completedListing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $completedLine = PurchaseOrderLine::factory()
        ->for($order)
        ->for($completedListing, 'supplierListing')
        ->create([
            'ingredient_id' => $completedListing->ingredient_id,
            'ordered_packs' => 1,
            'expected_quantity' => $completedListing->canonical_quantity_per_purchase_format,
        ]);
    app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: null,
        lines: [[
            'order_line' => $completedLine,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->assertSee('Packages received')
        ->assertSee('Actual received quantity')
        ->assertSeeHtml('data-receipt-line-grid="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-line-summary="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-line-details="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-line-metadata="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-line-checkbox="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-fixed-unit="'.$availableLine->id.'"')
        ->assertSeeHtml('data-receipt-price-unit-locked="'.$availableLine->id.'"')
        ->assertSeeHtml('aria-disabled="true" disabled')
        ->assertSeeHtml('accent-[var(--color-accent)]')
        ->assertSeeHtml('sk-card-elevation-subtle')
        ->assertSeeHtml('xl:grid-cols-4')
        ->assertDontSeeHtml('<span aria-hidden="true" class="hidden xl:block"></span>')
        ->assertSeeInOrder([
            'Ordered',
            'Previously received',
            'Remaining',
            'Packages received',
            'Actual received quantity',
            'Price basis',
            'Receipt price',
            'Price unit',
            'Supplier batch',
            'Expiry / best before',
            'Notes',
        ])
        ->assertSeeHtml('data-receipt-line-fields="'.$completedLine->id.'" disabled')
        ->assertDontSeeHtml('data-receipt-line-fields="'.$availableLine->id.'" disabled')
        ->assertDontSeeHtml('min-w-[1180px]');
});

it('finds eligible purchase orders and direct listings beyond the first hundred results', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, $listing, $targetOrder, $targetLine] = outstandingReceiptOrder($owner, $workspace);
    $targetOrder->update([
        'reference' => 'PO-SEARCH-101',
        'issued_at' => now()->subYear(),
    ]);

    foreach (range(1, 101) as $index) {
        $order = PurchaseOrder::factory()
            ->for($workspace)
            ->for($supplier)
            ->create([
                'reference' => sprintf('PO-RECENT-%03d', $index),
                'stage' => ProcurementStage::PurchaseOrder,
                'status' => PurchaseOrderStatus::Ordered,
                'issued_at' => now(),
                'created_by_user_id' => $owner->id,
            ]);
        PurchaseOrderLine::factory()
            ->for($order)
            ->for($listing, 'supplierListing')
            ->create([
                'ingredient_id' => $listing->ingredient_id,
                'ordered_packs' => 1,
            ]);
    }

    $directSupplier = Supplier::factory()->for($workspace)->create();

    foreach (range(1, 101) as $index) {
        SupplierListing::factory()
            ->for($workspace)
            ->for($directSupplier)
            ->for(Ingredient::factory())
            ->create(['purchase_format' => sprintf('Common format %03d', $index)]);
    }

    $targetListing = SupplierListing::factory()
        ->for($workspace)
        ->for($directSupplier)
        ->for(Ingredient::factory())
        ->create(['purchase_format' => 'Unique searchable format']);
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value])
        ->test(ReceiptCreate::class)
        ->assertDontSee('PO-SEARCH-101')
        ->set('orderSearch', 'PO-SEARCH-101')
        ->assertSee('PO-SEARCH-101')
        ->set('orderPublicId', $targetOrder->public_id)
        ->assertSet("lineInputs.{$targetLine->id}.packs_received", $targetLine->ordered_packs);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $directSupplier->id)
        ->assertDontSee('Unique searchable format')
        ->set('listingSearch', 'Unique searchable')
        ->assertSee('Unique searchable format')
        ->assertSet("lineInputs.{$targetListing->id}.packs_received", 1);
});

it('posts direct listings selected across different searches', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $alphaListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['purchase_format' => 'Alpha searched format']);
    $betaListing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['purchase_format' => 'Beta searched format']);
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set('listingSearch', 'Alpha searched')
        ->set("selected.{$alphaListing->id}", true)
        ->set('listingSearch', 'Beta searched')
        ->set("selected.{$betaListing->id}", true)
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(GoodsReceipt::query()->sole()->lines()->pluck('supplier_listing_id')->sort()->values()->all())
        ->toBe([$alphaListing->id, $betaListing->id]);
});

it('exposes pressed source semantics and locked currencies for both receipt sources', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order] = outstandingReceiptOrder($owner, $workspace);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSeeHtml('data-receipt-currency-locked')
        ->assertSee('Currency is fixed');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSee($listing->purchase_format)
        ->assertSeeHtml('data-receipt-currency-locked')
        ->assertSeeHtml('data-receipt-fixed-unit="'.$listing->id.'"')
        ->assertSeeHtml('data-receipt-price-unit-locked="'.$listing->id.'"')
        ->assertSeeHtml('readonly')
        ->assertSee('Currency is fixed');
});

it('shows a manual exchange-rate input for cross-currency receipts', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for(Ingredient::factory())
        ->create(['currency' => 'USD']);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->actingAs($owner)
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSee(__('production_bench.receipt.manual_exchange_rate'))
        ->assertSeeHtml('data-receipt-manual-rate="'.$listing->id.'"');
});

it('renders a single-axis editable receipt layout with associated errors and loading feedback', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->assertSeeHtml('data-receipt-editable-lines')
        ->assertSeeHtml('data-receipt-mobile-context')
        ->assertDontSeeHtml('min-w-[980px]')
        ->assertSeeHtml('id="receipt-order-currency-help"')
        ->assertSeeHtml('aria-label="Currency"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:target="post"')
        ->set('receivedAt', '')
        ->call('post')
        ->assertHasErrors('receivedAt')
        ->assertSeeHtml('aria-describedby="receipt-date-error"')
        ->assertSeeHtml('id="receipt-date-error"')
        ->set('receivedAt', '2026-08-03')
        ->set('orderPublicId', null)
        ->call('post')
        ->assertHasErrors('orderPublicId')
        ->assertSeeHtml('aria-describedby="receipt-order-error"')
        ->assertSeeHtml('id="receipt-order-error"')
        ->assertSeeHtml('data-receipt-error-summary')
        ->assertSeeHtml('aria-live="assertive"');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSee($listing->purchase_format)
        ->set('supplierId', null)
        ->call('post')
        ->assertHasErrors('supplierId')
        ->assertSeeHtml('aria-describedby="receipt-supplier-error"')
        ->assertSeeHtml('id="receipt-supplier-error"');
});

it('rejects a crafted invalid receipt source without throwing', function (): void {
    [$owner] = receiptPageWorkspace();
    $this->actingAs($owner);

    Livewire::test(ReceiptCreate::class)
        ->call('chooseSource', 'not-a-receipt-source')
        ->assertHasErrors('source')
        ->assertSet('source', '');
});

it('synchronizes nominal PO quantity until the actual mass is edited', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace, 4);
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->set("lineInputs.{$line->id}.packs_received", 2);

    expect($component->get("lineInputs.{$line->id}.actual_quantity"))->toBe('10.00');

    $component
        ->set("lineInputs.{$line->id}.actual_quantity", '9.7')
        ->set("lineInputs.{$line->id}.packs_received", 3)
        ->set("selected.{$line->id}", true);

    expect($component->get("lineInputs.{$line->id}.actual_quantity"))->toBe('9.7')
        ->and($component->get("actualQuantityEdited.{$line->id}"))->toBeTrue();

    $component->call('post')->assertHasNoErrors()->assertRedirect();

    expect(GoodsReceipt::query()->sole()->lines()->sole()->original_quantity)->toBe('9.700000000');
});

it('synchronizes nominal direct quantity and preserves a measured override', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create([
        'canonical_quantity_per_purchase_format' => '5123',
        'net_quantity' => '5.123',
    ]);
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("lineInputs.{$listing->id}.packs_received", 2);

    expect($component->get("lineInputs.{$listing->id}.actual_quantity"))->toBe('10.246');

    $component
        ->set("lineInputs.{$listing->id}.actual_quantity", '9.85')
        ->set("lineInputs.{$listing->id}.packs_received", 3);

    expect($component->get("lineInputs.{$listing->id}.actual_quantity"))->toBe('9.85');
});

it('posts a partial purchase-order receipt with a stable idempotency key', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class);
    $key = $component->get('idempotencyKey');

    $component
        ->set("selected.{$line->id}", true)
        ->set("lineInputs.{$line->id}.packs_received", 1)
        ->set("lineInputs.{$line->id}.actual_quantity", '4.8')
        ->set("lineInputs.{$line->id}.actual_unit", 'kg')
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    $receipt = GoodsReceipt::query()->sole();

    expect($receipt->idempotency_key)->toBe($key)
        ->and($receipt->lines()->sole()->packs_received)->toBe(1)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

it('posts multiple partial purchase-order lines in one receipt', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order, $firstLine] = outstandingReceiptOrder($owner, $workspace, 3);
    $secondListing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $secondLine = PurchaseOrderLine::factory()
        ->for($order)
        ->for($secondListing, 'supplierListing')
        ->create([
            'ingredient_id' => $secondListing->ingredient_id,
            'ordered_packs' => 4,
            'expected_quantity' => bcmul($secondListing->canonical_quantity_per_purchase_format, '4', 9),
        ]);
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->set("selected.{$firstLine->id}", true)
        ->set("lineInputs.{$firstLine->id}.packs_received", 1)
        ->set("selected.{$secondLine->id}", true)
        ->set("lineInputs.{$secondLine->id}.packs_received", 2)
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(GoodsReceipt::query()->sole()->lines()->count())->toBe(2)
        ->and($firstLine->receiptLines()->sole()->packs_received)->toBe(1)
        ->and($secondLine->receiptLines()->sole()->packs_received)->toBe(2)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

it('returns the original receipt when the same submission key is retried', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $key = fake()->uuid();
    $payload = [[
        'order_line' => $line,
        'packs_received' => 1,
        'actual_quantity' => '5',
        'actual_unit' => 'kg',
    ]];

    $first = app(ReceivePurchaseOrder::class)->handle($owner, $order, $key, null, $payload);
    $retried = app(ReceivePurchaseOrder::class)->handle($owner, $order, $key, null, $payload);

    expect($retried->is($first))->toBeTrue()
        ->and(GoodsReceipt::query()->count())->toBe(1)
        ->and(GoodsReceiptLine::query()->count())->toBe(1)
        ->and($first->lines()->sole()->stockLot->movements()->count())->toBe(1);
});

it('posts an edited purchase-order receipt price snapshot without changing the supplier listing', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, $listing, $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $listingPriceSnapshot = $listing->only([
        'price_basis',
        'price_amount',
        'price_unit',
        'total_price',
        'currency',
    ]);
    $listingPriceRecordedAt = $listing->price_recorded_at;

    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '4.8',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::PerUnit,
            'receipt_price_amount' => '12',
            'receipt_price_unit' => 'kg',
            'currency' => 'EUR',
        ]],
    );
    $receiptLine = $receipt->lines()->sole();

    expect($receiptLine->receipt_price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($receiptLine->receipt_price_amount)->toBe('12.000000000')
        ->and($receiptLine->receipt_price_unit)->toBe('kg')
        ->and($receiptLine->purchase_format_price)->toBe('60.000000000')
        ->and($receiptLine->historical_total_cost)->toBe('60.000000000')
        ->and($receiptLine->stockLot->historical_unit_cost)->toBe('0.012500000')
        ->and($listing->refresh()->only(array_keys($listingPriceSnapshot)))->toBe($listingPriceSnapshot)
        ->and($listing->price_recorded_at?->equalTo($listingPriceRecordedAt))->toBeTrue();
});

it('preserves a changed listing projection while costing from the immutable PO format', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, $listing, $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $listing->update([
        'canonical_quantity_per_purchase_format' => '10000',
        'net_quantity' => '10',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
        'price_amount' => '99',
        'price_unit' => null,
        'total_price' => '99',
    ]);

    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '4.9',
            'actual_unit' => 'kg',
            'receipt_price_basis' => ListingPriceBasis::PerUnit,
            'receipt_price_amount' => '12',
            'receipt_price_unit' => 'kg',
            'currency' => 'EUR',
        ]],
    );

    expect($receipt->lines()->sole()->purchase_format_price)->toBe('60.000000000')
        ->and($listing->refresh()->price_basis)->toBe(ListingPriceBasis::TotalPurchaseFormat)
        ->and($listing->price_amount)->toBe('99.000000000')
        ->and($listing->total_price)->toBe('99.000000000');
});

it('posts a direct receipt from active supplier listings and rejects foreign listings', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create(['purchase_format' => 'Active receipt drum']);
    $inactive = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create(['purchase_format' => 'Inactive receipt drum', 'is_active' => false]);
    $otherSupplier = Supplier::factory()->for($workspace)->create();
    $wrongSupplier = SupplierListing::factory()->for($workspace)->for($otherSupplier)->for(Ingredient::factory())->create(['purchase_format' => 'Other supplier drum']);
    $foreignListing = SupplierListing::factory()->create();
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->assertSee($listing->purchase_format)
        ->assertDontSee('Inactive receipt drum')
        ->assertDontSee('Other supplier drum')
        ->set("selected.{$foreignListing->id}", true)
        ->set("lineInputs.{$foreignListing->id}.packs_received", 1)
        ->set("lineInputs.{$foreignListing->id}.actual_quantity", '5')
        ->set("lineInputs.{$foreignListing->id}.actual_unit", 'kg')
        ->call('post')
        ->assertHasErrors('selected');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("selected.{$listing->id}", true)
        ->set("lineInputs.{$listing->id}.packs_received", 1)
        ->set("lineInputs.{$listing->id}.actual_quantity", '5')
        ->set("lineInputs.{$listing->id}.actual_unit", 'kg')
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(GoodsReceipt::query()->sole()->source)->toBe(GoodsReceiptSource::Direct);
});

it('keeps receipt and purchase-order mutations hidden when the bench is read-only', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: null,
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
        ]],
    );
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSee('Read-only')
        ->assertDontSee('Reverse receipt')
        ->assertDontSeeHtml('data-receipt-reversal-action-bar');

    $this->get(route('production-bench.purchasing.procurement.show', $order))
        ->assertOk()
        ->assertDontSee('Receive delivery');
});

it('renders immutable receipt relationships and requires a reason to reverse', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: fake()->uuid(),
        deliveryReference: 'DN-42',
        lines: [[
            'order_line' => $line,
            'packs_received' => 1,
            'actual_quantity' => '5',
            'actual_unit' => 'kg',
            'supplier_batch_number' => 'SHARED-BATCH',
        ]],
    );
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSee('DN-42')
        ->assertSee($supplier->name)
        ->assertSee($order->reference)
        ->assertSee('SHARED-BATCH')
        ->assertSee($receipt->lines()->sole()->stockLot->internal_lot_code)
        ->assertSee('Inventory')
        ->assertDontSeeHtml('wire:model="deliveryReference"')
        ->set('reversalReason', '   ')
        ->call('reverse')
        ->assertHasErrors('reversalReason')
        ->assertSeeHtml('aria-describedby="reversal-reason-error"')
        ->assertSeeHtml('id="reversal-reason-error"')
        ->set('reversalReason', str_repeat('x', 1001))
        ->call('reverse')
        ->assertHasErrors('reversalReason')
        ->set('reversalReason', 'Delivery entered twice')
        ->call('reverse')
        ->assertHasNoErrors()
        ->assertSee('Delivery entered twice')
        ->assertSee('Reversed');
});

it('shows total mass costs without per-gram noise and labels packaging costs per unit', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $massListing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    $packagingListing = SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => PackagingItem::factory(),
        'unit_kind' => StockUnitKind::Count,
        'purchase_format' => 'Box of 100 units',
        'canonical_quantity_per_purchase_format' => '100',
        'net_quantity' => '100',
        'net_unit' => 'count',
    ]);
    $receipt = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create();
    $massLine = GoodsReceiptLine::factory()->direct()->for($receipt)->for($massListing, 'supplierListing')->create([
        'historical_total_cost' => '50.000000000',
        'costing_total_cost' => '50.000000000',
        'original_quantity' => '5',
        'original_unit' => 'kg',
    ]);
    $packagingLine = GoodsReceiptLine::factory()->direct()->for($receipt)->for($packagingListing, 'supplierListing')->create([
        'historical_total_cost' => '20.000000000',
        'costing_total_cost' => '20.000000000',
        'original_quantity' => '100',
        'original_unit' => 'count',
        'receipt_price_basis' => ListingPriceBasis::PerUnit,
        'receipt_price_amount' => '0.20',
        'receipt_price_unit' => 'count',
    ]);

    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSeeHtml('data-receipt-detail-line="'.$massLine->id.'"')
        ->assertSeeHtml('sk-card-elevation-subtle')
        ->assertSeeHtml('line-clamp-2 min-h-8 leading-4')
        ->assertSee('Purchased price')
        ->assertSee('Inventory value')
        ->assertSee('50.00 EUR')
        ->assertSee('20.00 EUR')
        ->assertDontSee('50.0000 EUR')
        ->assertDontSee('20.0000 EUR')
        ->assertDontSee('/ g')
        ->assertDontSee('/ count')
        ->assertSee('/ unit');

    expect($massLine->fresh()->costing_total_cost)->toBe('50.000000000')
        ->and($packagingLine->fresh()->costing_total_cost)->toBe('20.000000000');
});

it('renders field-level errors for dynamic PO and direct receipt inputs', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [$supplier, , $order, $line] = outstandingReceiptOrder($owner, $workspace);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $this->actingAs($owner);

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->set("selected.{$line->id}", true)
        ->set("lineInputs.{$line->id}.actual_quantity", '')
        ->call('post')
        ->assertHasErrors("lineInputs.{$line->id}.actual_quantity")
        ->assertSeeHtml('aria-describedby="line-'.$line->id.'-actual-quantity-error"')
        ->assertSeeHtml('id="line-'.$line->id.'-actual-quantity-error"');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::PurchaseOrder->value, 'order' => $order->public_id])
        ->test(ReceiptCreate::class)
        ->set("selected.{$line->id}", true)
        ->set("lineInputs.{$line->id}.currency", 'USD')
        ->call('post')
        ->assertHasErrors("lineInputs.{$line->id}.currency")
        ->assertSeeHtml('aria-describedby="receipt-order-currency-help line-'.$line->id.'-currency-error"');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("selected.{$listing->id}", true)
        ->set("lineInputs.{$listing->id}.packs_received", 0)
        ->call('post')
        ->assertHasErrors("lineInputs.{$listing->id}.packs_received")
        ->assertSeeHtml('aria-describedby="line-'.$listing->id.'-packs-error"')
        ->assertSeeHtml('id="line-'.$listing->id.'-packs-error"');

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("selected.{$listing->id}", true)
        ->set("lineInputs.{$listing->id}.currency", 'USD')
        ->call('post')
        ->assertHasErrors("lineInputs.{$listing->id}.currency")
        ->assertSeeHtml('aria-describedby="receipt-direct-currency-help line-'.$listing->id.'-currency-error"');
});

it('shows receive delivery only for writable issued orders with outstanding quantity', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, , $order] = outstandingReceiptOrder($owner, $workspace);
    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.procurement.show', $order))
        ->assertOk()
        ->assertSee('Receive delivery')
        ->assertSeeHtml('order='.$order->public_id);

    $order->update(['status' => PurchaseOrderStatus::Received]);
    $this->get(route('production-bench.purchasing.procurement.show', $order))
        ->assertOk()
        ->assertDontSee('Receive delivery');
});
