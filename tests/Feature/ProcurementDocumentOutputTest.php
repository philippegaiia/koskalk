<?php

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\ProcurementStage;
use App\Services\ProcurementDocumentFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('formats price-free quotation and priced purchase-order email text from issued snapshots', function (): void {
    $quotation = PurchaseOrder::factory()->create([
        'stage' => ProcurementStage::Quotation,
        'quotation_reference' => 'RFQ-2608-0001',
        'quotation_requested_at' => now(),
        'quotation_snapshot' => [
            'reference' => 'RFQ-2608-0001',
            'supplier' => ['name' => 'Original Oils'],
            'lines' => [[
                'catalogue_name' => 'Olive oil',
                'supplier_sku' => 'OO-5',
                'purchase_format' => '5 kg pail',
                'ordered_purchase_formats' => 3,
                'price' => null,
                'currency' => 'EUR',
            ]],
            'notes' => 'Please confirm lead time.',
        ],
    ]);
    $purchaseOrder = PurchaseOrder::factory()->create([
        'stage' => ProcurementStage::PurchaseOrder,
        'reference' => 'PO-2608-0002',
        'issued_at' => now(),
        'purchase_order_snapshot' => [
            'reference' => 'PO-2608-0002',
            'supplier' => ['name' => 'Original Oils'],
            'lines' => [[
                'catalogue_name' => 'Olive oil',
                'supplier_sku' => 'OO-5',
                'purchase_format' => '5 kg pail',
                'ordered_purchase_formats' => 3,
                'price' => '49.000000000',
                'expected_cost' => '147.000000000',
                'currency' => 'EUR',
            ]],
            'subtotal' => '147.000000000',
            'shipping' => '0.000000000',
            'discount' => '0.000000000',
            'tax' => '0.000000000',
            'total' => '147.000000000',
            'notes' => null,
        ],
    ]);

    $formatter = app(ProcurementDocumentFormatter::class);
    $quotationText = $formatter->emailText($quotation);
    $orderText = $formatter->emailText($purchaseOrder);

    expect($quotationText)->toContain('Quotation request RFQ-2608-0001')
        ->and($quotationText)->toContain('3 × 5 kg pail — Olive oil (OO-5)')
        ->and($quotationText)->not->toContain('49.00 EUR')
        ->and($orderText)->toContain('Purchase order PO-2608-0002')
        ->and($orderText)->toContain('49.00 EUR each')
        ->and($orderText)->toContain('Total: 147.00 EUR');

    $quotation->update(['stage' => ProcurementStage::PurchaseOrder]);

    expect($formatter->emailText($quotation->refresh()))
        ->toContain('Quotation request RFQ-2608-0001');
});

it('renders an issued procurement snapshot as a printable document for its workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $supplier = Supplier::factory()->for($workspace)->create();
    $order = PurchaseOrder::factory()->for($workspace)->for($supplier)->create([
        'stage' => ProcurementStage::PurchaseOrder,
        'reference' => 'PO-2608-0042',
        'issued_at' => now(),
        'purchase_order_snapshot' => [
            'reference' => 'PO-2608-0042',
            'supplier' => ['name' => 'Original Oils', 'email' => 'orders@example.test'],
            'delivery_address' => ['name' => 'Atelier Savon', 'city' => 'Lyon', 'country_code' => 'FR'],
            'lines' => [[
                'catalogue_name' => 'Olive oil',
                'supplier_sku' => 'OO-5',
                'purchase_format' => '5 kg pail',
                'ordered_purchase_formats' => 3,
                'price' => '49.000000000',
                'expected_cost' => '147.000000000',
                'currency' => 'EUR',
            ]],
            'subtotal' => '147.000000000',
            'shipping' => '0.000000000',
            'discount' => '0.000000000',
            'tax' => '0.000000000',
            'total' => '147.000000000',
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.documents.print', $order))
        ->assertOk()
        ->assertSee('Purchase order')
        ->assertSee('PO-2608-0042')
        ->assertSee('Original Oils')
        ->assertSee('147.00 EUR');

    $otherOwner = User::factory()->create();
    Workspace::factory()->for($otherOwner, 'owner')->create();

    $this->actingAs($otherOwner)
        ->get(route('production-bench.purchasing.documents.print', $order))
        ->assertNotFound();
});
