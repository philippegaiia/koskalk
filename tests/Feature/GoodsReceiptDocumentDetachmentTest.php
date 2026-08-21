<?php

use App\Enums\ProductionDocumentType;
use App\Livewire\Dashboard\MediaLibraryIndex;
use App\Livewire\ProductionBench\Purchasing\ReceiptDetail;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function receiptDetachmentFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Detach supplier']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    $receipt = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create([
        'received_by_user_id' => $owner->id,
        'received_at' => '2026-08-10',
    ]);
    $line = GoodsReceiptLine::factory()->direct()->for($receipt)->for($listing, 'supplierListing')->create();

    return [$owner, $workspace, $receipt, $line];
}

function receiptDocumentFor(MediaAsset $asset, GoodsReceipt $receipt): ProductionDocument
{
    return ProductionDocument::query()->create([
        'workspace_id' => $receipt->workspace_id,
        'media_asset_id' => $asset->id,
        'documentable_type' => $receipt->getMorphClass(),
        'documentable_id' => $receipt->id,
        'type' => ProductionDocumentType::Invoice,
        'attached_by_user_id' => $receipt->workspace->owner_user_id,
    ]);
}

it('detaches a receipt-level document and frees the asset for library removal', function (): void {
    [$owner, , $receipt] = receiptDetachmentFixture();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $receipt->workspace_id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    $document = receiptDocumentFor($asset, $receipt);

    Livewire::actingAs($owner)
        ->test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->call('detachDocument', $document->id)
        ->assertHasNoErrors();

    expect(ProductionDocument::query()->find($document->id))->toBeNull()
        ->and(MediaAsset::query()->find($asset->id))->not->toBeNull();

    Livewire::actingAs($owner)
        ->test(MediaLibraryIndex::class)
        ->call('remove', $asset->id)
        ->assertSet('statusType', 'success');

    expect(MediaAsset::query()->find($asset->id))->toBeNull();
});

it('detaches a lot-level document from its receipt', function (): void {
    [$owner, , $receipt, $line] = receiptDetachmentFixture();
    $lot = StockLot::query()->findOrFail($line->stock_lot_id);
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $receipt->workspace_id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    $document = ProductionDocument::query()->create([
        'workspace_id' => $receipt->workspace_id,
        'media_asset_id' => $asset->id,
        'documentable_type' => $lot->getMorphClass(),
        'documentable_id' => $lot->id,
        'type' => ProductionDocumentType::CertificateOfAnalysis,
        'attached_by_user_id' => $owner->id,
    ]);

    Livewire::actingAs($owner)
        ->test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->call('detachDocument', $document->id)
        ->assertHasNoErrors();

    expect(ProductionDocument::query()->find($document->id))->toBeNull()
        ->and(MediaAsset::query()->find($asset->id))->not->toBeNull();
});

it('refuses to detach documents that do not belong to the receipt', function (): void {
    [$owner, , $receipt] = receiptDetachmentFixture();
    [, , $otherReceipt] = receiptDetachmentFixture();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $receipt->workspace_id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    $foreign = receiptDocumentFor($asset, $otherReceipt);

    Livewire::actingAs($owner)
        ->test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->call('detachDocument', $foreign->id)
        ->assertStatus(404);

    expect(ProductionDocument::query()->find($foreign->id))->not->toBeNull();
});

it('shows production document references in the media library usage tab and counts', function (): void {
    [$owner, , $receipt] = receiptDetachmentFixture();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $receipt->workspace_id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    receiptDocumentFor($asset, $receipt);
    $this->actingAs($owner);

    Livewire::test(MediaLibraryIndex::class)
        ->set('usageFilter', 'used')
        ->assertSeeHtml('wire:key="media-asset-'.$asset->id.'"')
        ->assertSee(trans_choice('media_library.usage', 1, ['count' => 1]))
        ->call('openAssetPanel', $asset->id, 'usage')
        ->assertSee('Production documents')
        ->assertSee('Detach supplier')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.receipts.show', $receipt).'"');

    expect($asset->fresh()->productionDocuments)->toHaveCount(1);
});

it('drops the document reference from the usage tab after detachment', function (): void {
    [$owner, , $receipt] = receiptDetachmentFixture();
    $asset = MediaAsset::factory()->ready()->create([
        'workspace_id' => $receipt->workspace_id,
        'uploaded_by_user_id' => $owner->id,
    ]);
    $document = receiptDocumentFor($asset, $receipt);
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->call('detachDocument', $document->id)
        ->assertHasNoErrors();

    Livewire::test(MediaLibraryIndex::class)
        ->set('usageFilter', 'unused')
        ->assertSeeHtml('wire:key="media-asset-'.$asset->id.'"');
});
