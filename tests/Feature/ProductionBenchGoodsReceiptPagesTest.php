<?php

use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\GoodsReceiptSource;
use App\ListingPriceBasis;
use App\Livewire\ProductionBench\Purchasing\ReceiptCreate;
use App\Livewire\ProductionBench\Purchasing\ReceiptDetail;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
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
        ->assertSeeHtml('data-receipt-responsive-table')
        ->assertSeeHtml('wire:confirm=')
        ->assertSeeHtml('readonly');
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
        ->assertSeeHtml('readonly')
        ->assertSee('Currency is fixed');
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

    expect((float) $component->get("lineInputs.{$line->id}.actual_quantity"))->toBe(10.0);

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
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for(Ingredient::factory())->create();
    $this->actingAs($owner);

    $component = Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("lineInputs.{$listing->id}.packs_received", 2);

    expect((float) $component->get("lineInputs.{$listing->id}.actual_quantity"))->toBe(10.0);

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

it('posts an edited purchase-order receipt price snapshot and updates the listing projection', function (): void {
    [$owner, $workspace] = receiptPageWorkspace();
    [, $listing, $order, $line] = outstandingReceiptOrder($owner, $workspace);

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
        ->and($listing->refresh()->price_amount)->toBe('12.000000000')
        ->and($listing->price_unit)->toBe('kg');
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

    Livewire::withQueryParams(['source' => GoodsReceiptSource::Direct->value])
        ->test(ReceiptCreate::class)
        ->set('supplierId', $supplier->id)
        ->set("selected.{$listing->id}", true)
        ->set("lineInputs.{$listing->id}.packs_received", 0)
        ->call('post')
        ->assertHasErrors("lineInputs.{$listing->id}.packs_received")
        ->assertSeeHtml('aria-describedby="line-'.$listing->id.'-packs-error"')
        ->assertSeeHtml('id="line-'.$listing->id.'-packs-error"');
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
