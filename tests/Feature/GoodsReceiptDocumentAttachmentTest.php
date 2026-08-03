<?php

use App\Actions\Purchasing\AttachGoodsReceiptDocuments;
use App\MediaAssetStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\MediaAsset;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function receiptDocumentFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $receipt = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create([
        'received_by_user_id' => $owner->id,
    ]);
    $lines = collect(range(1, 2))->map(function () use ($workspace, $supplier, $receipt): GoodsReceiptLine {
        $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create();

        return GoodsReceiptLine::factory()
            ->direct()
            ->for($receipt)
            ->for($listing, 'supplierListing')
            ->create();
    });
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->pdf()->ready()->create();

    return [$owner, $workspace, $receipt, $lines, $asset];
}

it('attaches each receipt document category only to the receipt', function (ProductionDocumentType $type): void {
    [$owner, , $receipt, , $asset] = receiptDocumentFixture();

    $documents = app(AttachGoodsReceiptDocuments::class)->handle(
        actor: $owner,
        receipt: $receipt,
        asset: $asset,
        type: $type,
        note: 'Supplier paperwork',
    );

    expect($documents)->toHaveCount(1)
        ->and($documents->sole()->documentable->is($receipt))->toBeTrue()
        ->and($documents->sole()->note)->toBe('Supplier paperwork');
})->with([
    ProductionDocumentType::Invoice,
    ProductionDocumentType::Receipt,
    ProductionDocumentType::DeliveryNote,
    ProductionDocumentType::Photo,
    ProductionDocumentType::Other,
]);

it('attaches each lot document category to every selected receipt lot', function (ProductionDocumentType $type): void {
    [$owner, , $receipt, $lines, $asset] = receiptDocumentFixture();
    $lotIds = $lines->pluck('stock_lot_id')->all();

    $documents = app(AttachGoodsReceiptDocuments::class)->handle(
        actor: $owner,
        receipt: $receipt,
        asset: $asset,
        type: $type,
        selectedLotIds: $lotIds,
    );

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('media_asset_id')->unique()->all())->toBe([$asset->id])
        ->and($documents->pluck('documentable_id')->sort()->values()->all())->toBe(collect($lotIds)->sort()->values()->all());
})->with([
    ProductionDocumentType::CertificateOfAnalysis,
    ProductionDocumentType::SafetyDataSheet,
    ProductionDocumentType::Specification,
    ProductionDocumentType::Certificate,
]);

it('is idempotent when the same asset type and targets are submitted again', function (): void {
    [$owner, , $receipt, $lines, $asset] = receiptDocumentFixture();
    $lotIds = $lines->pluck('stock_lot_id')->all();
    $action = app(AttachGoodsReceiptDocuments::class);

    $first = $action->handle($owner, $receipt, $asset, ProductionDocumentType::CertificateOfAnalysis, $lotIds);
    $second = $action->handle($owner, $receipt, $asset, ProductionDocumentType::CertificateOfAnalysis, $lotIds);

    expect($first->pluck('id')->all())->toBe($second->pluck('id')->all())
        ->and($receipt->documents()->count() + StockLot::query()->whereIn('id', $lotIds)->withCount('documents')->get()->sum('documents_count'))->toBe(2);
});

it('rejects target and workspace incoherence', function (string $case): void {
    [$owner, $workspace, $receipt, $lines, $asset] = receiptDocumentFixture();
    $type = ProductionDocumentType::CertificateOfAnalysis;
    $lotIds = $lines->pluck('stock_lot_id')->all();

    if ($case === 'receipt type with lot targets') {
        $type = ProductionDocumentType::Invoice;
    } elseif ($case === 'missing lot targets') {
        $lotIds = [];
    } elseif ($case === 'unrelated lot') {
        $lotIds = [StockLot::factory()->for($workspace)->create()->id];
    } elseif ($case === 'foreign lot') {
        $lotIds = [StockLot::factory()->create()->id];
    } elseif ($case === 'foreign asset') {
        $asset = MediaAsset::factory()->pdf()->ready()->create();
        $type = ProductionDocumentType::Invoice;
        $lotIds = [];
    }

    expect(fn () => app(AttachGoodsReceiptDocuments::class)->handle(
        actor: $owner,
        receipt: $receipt,
        asset: $asset,
        type: $type,
        selectedLotIds: $lotIds,
    ))->toThrow(ValidationException::class);
})->with([
    'receipt type with lot targets',
    'missing lot targets',
    'unrelated lot',
    'foreign lot',
    'foreign asset',
]);

it('rejects non-ready and unsupported assets', function (string $case): void {
    [$owner, $workspace, $receipt] = receiptDocumentFixture();
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->pdf()->create([
        'status' => MediaAssetStatus::Processing,
    ]);

    if ($case === 'unsupported') {
        $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->ready()->create();
        DB::table('media_assets')->where('id', $asset->id)->update(['type' => 'video']);
        $asset->refresh();
    }

    expect(fn () => app(AttachGoodsReceiptDocuments::class)->handle(
        $owner,
        $receipt,
        $asset,
        ProductionDocumentType::Invoice,
    ))->toThrow(ValidationException::class);
})->with(['processing', 'unsupported']);

it('blocks attachments while the production bench is read only', function (): void {
    [$owner, $workspace, $receipt, , $asset] = receiptDocumentFixture();
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    expect(fn () => app(AttachGoodsReceiptDocuments::class)->handle(
        $owner,
        $receipt,
        $asset,
        ProductionDocumentType::Invoice,
    ))->toThrow(ValidationException::class);
});

it('rejects attachment by a user outside the receipt workspace', function (): void {
    [, , $receipt, , $asset] = receiptDocumentFixture();
    $outsider = User::factory()->create();
    Workspace::factory()->for($outsider, 'owner')->create();

    expect(fn () => app(AttachGoodsReceiptDocuments::class)->handle(
        $outsider,
        $receipt,
        $asset,
        ProductionDocumentType::Invoice,
    ))->toThrow(AuthorizationException::class);
});
