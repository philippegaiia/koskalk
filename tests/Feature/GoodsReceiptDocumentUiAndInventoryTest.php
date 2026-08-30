<?php

use App\Actions\Purchasing\AttachGoodsReceiptDocuments;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ProductionDocumentType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\InventoryIndex;
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
use App\Models\WorkspaceMember;
use App\Services\MediaAssetProcessingService;
use App\Services\MediaAssetUploadService;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function receiptDocumentUiFixture(bool $reversed = false): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Document supplier']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    $receipt = GoodsReceipt::factory()->for($workspace)->for($supplier)->direct()->create([
        'received_by_user_id' => $owner->id,
        'received_at' => '2026-08-02',
        'status' => $reversed ? GoodsReceiptStatus::Reversed : GoodsReceiptStatus::Posted,
        'reversed_at' => $reversed ? now() : null,
        'reversal_reason' => $reversed ? 'Returned to supplier' : null,
    ]);
    $line = GoodsReceiptLine::factory()->direct()->for($receipt)->for($listing, 'supplierListing')->create();

    return [$owner, $workspace, $receipt, $line];
}

function goodsReceiptDocumentWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}

it('uploads one private asset and attaches it to selected receipt lots', function (): void {
    [$owner, $workspace, $receipt, $line] = receiptDocumentUiFixture();
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->ready()->create([
        'original_filename' => 'shared-coa.jpg',
    ]);
    mock(MediaAssetUploadService::class)
        ->shouldReceive('start')
        ->once()
        ->andReturn($asset);
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSeeHtml('data-receipt-document-upload')
        ->assertSeeHtml('value="certificate_of_analysis"')
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->assertSeeHtml('value="'.$line->stock_lot_id.'"')
        ->set('documentLotIds', [$line->stock_lot_id])
        ->set('documentUpload', UploadedFile::fake()->image('shared-coa.jpg'))
        ->set('documentNote', 'Approved for use')
        ->call('attachDocument')
        ->assertHasNoErrors()
        ->assertSee('Document attached')
        ->assertSee('shared-coa.jpg')
        ->assertSee('Approved for use')
        ->assertSeeHtml('href="'.route('media.download', $asset).'"');

    expect($line->stockLot->documents()->sole()->media_asset_id)->toBe($asset->id);
});

it('validates the upload and lot target accessibly', function (): void {
    [$owner, , $receipt] = receiptDocumentUiFixture();
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->call('attachDocument')
        ->assertHasErrors(['documentUpload', 'documentLotIds'])
        ->assertSeeHtml('aria-describedby="receipt-document-upload-help receipt-document-upload-error"')
        ->assertSeeHtml('id="receipt-document-upload-error"')
        ->assertSeeHtml('aria-describedby="receipt-document-lots-help receipt-document-lots-error"')
        ->assertSeeHtml('id="receipt-document-lots-error"');
});

it('renders document and reversal loading states with precise targets', function (): void {
    [$owner, , $receipt] = receiptDocumentUiFixture();
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSeeHtml('wire:target="attachDocument"')
        ->assertSeeHtml('wire:target="reverse"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('aria-live="polite"');
});

it('associates document type and note errors with their fields', function (): void {
    [$owner, , $receipt] = receiptDocumentUiFixture();
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', 'unsupported')
        ->set('documentNote', str_repeat('x', 1001))
        ->call('attachDocument')
        ->assertHasErrors(['documentType', 'documentNote'])
        ->assertSeeHtml('aria-describedby="receipt-document-type-error"')
        ->assertSeeHtml('id="receipt-document-type-error"')
        ->assertSeeHtml('aria-describedby="receipt-document-note-error"')
        ->assertSeeHtml('id="receipt-document-note-error"');
});

it('clears selected lots when switching back to a receipt document type', function (): void {
    [$owner, $workspace, $receipt, $line] = receiptDocumentUiFixture();
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->ready()->create();
    mock(MediaAssetUploadService::class)->shouldReceive('start')->once()->andReturn($asset);
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->set('documentLotIds', [$line->stock_lot_id])
        ->set('documentType', ProductionDocumentType::Photo->value)
        ->assertSet('documentLotIds', [])
        ->set('documentUpload', UploadedFile::fake()->image('receipt-photo.jpg'))
        ->call('attachDocument')
        ->assertHasNoErrors();

    expect($receipt->documents()->count())->toBe(1);
});

it('prevalidates forged receipt lot targets without creating media or files', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [$owner, $workspace, $receipt] = receiptDocumentUiFixture();
    $unrelatedLotId = StockLot::factory()->for($workspace)->create()->id;
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->set('documentLotIds', [$unrelatedLotId])
        ->set('documentUpload', UploadedFile::fake()->image('forged-target.jpg'))
        ->call('attachDocument')
        ->assertHasErrors('documentLotIds');

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(ProductionDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media-assets'))->toBeEmpty();
});

it('lets an editor remove the uploaded asset and file if attachment validation loses a race', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [, $workspace, $receipt] = receiptDocumentUiFixture();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $action = mock(AttachGoodsReceiptDocuments::class);
    $action->shouldReceive('validateTargets')->once()->andReturn(new Collection);
    $action->shouldReceive('handle')->once()->andThrow(ValidationException::withMessages([
        'documentLotIds' => 'The selected receipt lot changed.',
    ]));
    $this->actingAs($editor);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::Photo->value)
        ->set('documentUpload', UploadedFile::fake()->image('race.jpg'))
        ->call('attachDocument')
        ->assertHasErrors('documentLotIds');

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(ProductionDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media-assets'))->toBeEmpty();
});

it('lets an editor clean a failed synchronous processing upload', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [, $workspace, $receipt] = receiptDocumentUiFixture();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $processor = mock(MediaAssetProcessingService::class);
    $processor->shouldReceive('process')->once()->andThrow(new RuntimeException('Processing failed.'));
    $processor->shouldReceive('markFailed')->once();
    $this->actingAs($editor);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::Photo->value)
        ->set('documentUpload', UploadedFile::fake()->image('failed-processing.jpg'))
        ->call('attachDocument')
        ->assertHasErrors('documentUpload');

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(ProductionDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media-assets'))->toBeEmpty();
});

it('allows document attachments on reversed receipts and hides mutation controls when read only', function (): void {
    [$owner, $workspace, $receipt, $line] = receiptDocumentUiFixture(reversed: true);
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->pdf()->ready()->create([
        'original_filename' => 'historic-invoice.pdf',
    ]);
    app(AttachGoodsReceiptDocuments::class)->handle($owner, $receipt, $asset, ProductionDocumentType::Invoice);
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSeeHtml('data-receipt-document-upload')
        ->assertSee('historic-invoice.pdf')
        ->assertSeeHtml('href="'.route('media.download', $asset).'"');

    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->assertSee('historic-invoice.pdf')
        ->assertSeeHtml('href="'.route('media.download', $asset).'"')
        ->assertDontSeeHtml('data-receipt-document-upload')
        ->assertDontSee('Attach document');
});

it('links receipt-origin inventory rows to their receipt with stable provenance context without changing positions', function (): void {
    [$owner, , $receipt, $line] = receiptDocumentUiFixture();
    $lot = $line->stockLot;
    $before = app(StockPositionService::class)->forLot($lot);
    $this->actingAs($owner);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->assertSeeHtml('id="lot-'.$lot->public_id.'"')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.receipts.show', $receipt).'"')
        ->assertSee('Document supplier')
        ->assertSee('Direct');

    $after = app(StockPositionService::class)->forLot($lot);

    expect($after)->toBe($before);
});

it('loads movement totals for many inventory lots without per-lot sum queries', function (): void {
    [$owner, $workspace] = goodsReceiptDocumentWorkspace();
    StockLot::factory()->count(15)->for($workspace)->create();
    $this->actingAs($owner);
    $movementQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$movementQueries): void {
        if (str_contains(strtolower($query->sql), 'stock_movements')) {
            $movementQueries[] = $query->sql;
        }
    });

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])->assertOk();

    expect($movementQueries)->toHaveCount(1);
});

it('downloads attached private images through the authenticated media download route', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [$owner, $workspace] = receiptDocumentUiFixture();
    $asset = app(MediaAssetUploadService::class)->start(
        $owner,
        $workspace,
        UploadedFile::fake()->image('delivery-photo.jpg'),
    )->refresh();

    $this->actingAs($owner)
        ->get(route('media.download', $asset))
        ->assertSuccessful()
        ->assertHeader('x-content-type-options', 'nosniff');

    $this->actingAs(User::factory()->create())
        ->get(route('media.download', $asset))
        ->assertNotFound();
});

it('finishes receipt document processing before attaching when the normal queue is asynchronous', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    config()->set('queue.default', 'database');
    [$owner, , $receipt] = receiptDocumentUiFixture();
    $this->actingAs($owner);

    Livewire::test(ReceiptDetail::class, ['goodsReceipt' => $receipt->public_id])
        ->set('documentType', ProductionDocumentType::Photo->value)
        ->set('documentUpload', UploadedFile::fake()->image('delivery.jpg'))
        ->call('attachDocument')
        ->assertHasNoErrors()
        ->assertSee('delivery.jpg');

    expect($receipt->documents()->sole()->mediaAsset->status->value)->toBe('ready');
});
