<?php

use App\Actions\Inventory\AttachProductionDocument;
use App\Livewire\ProductionBench\PurchasingIndex;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

function legacyDocumentUploadFixture(): array
{
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    config()->set('queue.default', 'database');
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $lot = StockLot::factory()->for($workspace)->create();

    return [$owner, $workspace, $lot];
}

it('finishes a legacy document upload before attaching with an asynchronous queue', function (): void {
    [$owner, , $lot] = legacyDocumentUploadFixture();
    $this->actingAs($owner);

    Livewire::test(PurchasingIndex::class)
        ->set('documentLotId', $lot->id)
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->set('documentUpload', UploadedFile::fake()->image('legacy-coa.jpg'))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect($lot->documents()->sole()->mediaAsset->status->value)->toBe('ready');
});

it('rolls back a legacy upload and private files when attachment fails', function (): void {
    [$owner, , $lot] = legacyDocumentUploadFixture();
    mock(AttachProductionDocument::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(ValidationException::withMessages(['document' => 'The lot changed.']));
    $this->actingAs($owner);

    Livewire::test(PurchasingIndex::class)
        ->set('documentLotId', $lot->id)
        ->set('documentType', ProductionDocumentType::CertificateOfAnalysis->value)
        ->set('documentUpload', UploadedFile::fake()->image('legacy-race.jpg'))
        ->call('uploadDocument')
        ->assertHasErrors('document');

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(ProductionDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media-assets'))->toBeEmpty();
});
