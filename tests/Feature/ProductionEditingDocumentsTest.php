<?php

use App\Actions\Inventory\AttachProductionDocument;
use App\Actions\Inventory\DetachProductionDocument;
use App\Actions\Production\SaveProductionJournalEntry;
use App\Enums\ProductionDocumentType;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Models\MediaAsset;
use App\Models\ProductionRun;
use App\Services\Production\ProductionEditingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('returns an explicit size error for a 1.6 MB PDF without losing the file or note', function (): void {
    Storage::fake('local');
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('beginEditing')->set('journalDocumentUpload', UploadedFile::fake()->create('large.pdf', 1600, 'application/pdf'))
        ->call('executeEditingCommand', 'attachJournalDocument', [], ['journalDocumentNote' => 'Pending PDF'], 'document')
        ->assertReturned(fn (array $reply): bool => ! $reply['ok'] && $reply['errors']['journalDocumentUpload'] === [__('media_library.validation.pdf_size', ['max' => 180])])
        ->assertSet('journalDocumentNote', 'Pending PDF')->assertSet('editingOwnsLease', true)
        ->assertSet('journalDocumentUpload', fn ($file): bool => $file->getClientOriginalName() === 'large.pdf');
    $this->assertDatabaseCount('production_documents', 0);
    $this->assertDatabaseCount('media_assets', 0);
});

it('attaches a completed upload through the editing queue and retains rejected evidence', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);

    $page->call('beginEditing')->set('journalDocumentUpload', UploadedFile::fake()->image('batch.jpg'))
        ->call('executeEditingCommand', 'attachJournalDocument', [], ['journalDocumentNote' => 'Batch evidence'], 'document')
        ->assertReturned(fn (array $reply): bool => $reply['ok'] && $reply['revisions'][$run->id] === 1 && $reply['canonical'] === ['journalDocumentNote' => ''])
        ->assertSet('journalDocumentUpload', null)->assertSet('editingOwnsLease', true);
    $document = $run->documents()->sole();
    expect($document->note)->toBe('Batch evidence')
        ->and($document->mediaAsset->original_filename)->toBe('batch.jpg');

    DB::table('production_runs')->where('id', $run->id)->update(['edit_revision' => 2]);
    $page->set('journalDocumentUpload', UploadedFile::fake()->image('pending.jpg'))
        ->call('executeEditingCommand', 'attachJournalDocument', [], ['journalDocumentNote' => 'Keep this evidence'], 'document')
        ->assertReturned(fn (array $reply): bool => ! $reply['ok'] && $reply['state']['status'] === 'stale')
        ->assertSet('journalDocumentNote', 'Keep this evidence')
        ->assertSet('journalDocumentUpload', fn ($file): bool => $file->getClientOriginalName() === 'pending.jpg');
    $this->assertDatabaseCount('production_documents', 1);
    $this->assertDatabaseCount('media_assets', 1);
});

it('requires ownership for production evidence and leaves duplicate attachments unchanged', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $asset = MediaAsset::factory()->for($fixture->workspace)->create(['status' => 'ready', 'type' => 'pdf']);
    expect(fn () => app(AttachProductionDocument::class)->handle($fixture->owner, $run, $asset, ProductionDocumentType::Other))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('production_documents', 0);
    $context = $fixture->lease($run);

    $document = app(AttachProductionDocument::class)->handle($fixture->owner, $run, $asset, ProductionDocumentType::Other, editing: $context);

    expect($context->acknowledgedRevisions())->toBe([$run->id => 1]);
    $next = new ProductionEditingContext($fixture->workspace->id, $context->token, $context->acknowledgedRevisions());
    app(AttachProductionDocument::class)->handle($fixture->owner, $run, $asset, ProductionDocumentType::Other, editing: $next);
    expect($next->acknowledgedRevisions())->toBe([$run->id => 1]);
    app(DetachProductionDocument::class)->handle($fixture->owner, $document, editing: $next);
    expect($next->acknowledgedRevisions())->toBe([$run->id => 2]);
    $this->assertDatabaseCount('production_documents', 0);
    $this->assertModelExists($asset);
});

it('increments the parent for journal evidence and refuses a second save from an old draft', function (): void {
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $context = $fixture->lease($run);

    app(SaveProductionJournalEntry::class)->handle($fixture->owner, $run, 'Initial observation', editing: $context);

    expect($context->acknowledgedRevisions())->toBe([$run->id => 1]);
    expect(fn () => app(SaveProductionJournalEntry::class)->handle($fixture->owner, $run, 'Stale observation', editing: $context))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('production_journal_entries', 1);
});

it('retains an upload added while a prepared reload is awaiting acceptance', function (): void {
    Storage::fake('local');
    $fixture = ProductionEditingFixture::create();
    $run = ProductionRun::factory()->for($fixture->workspace)->create();
    $page = Livewire::actingAs($fixture->owner)->test(ProductionDetail::class, ['productionId' => $run->public_id]);
    $page->call('reloadProductionEditing');
    $receipt = $page->get('editingPendingReload.id');
    $page->set('journalDocumentUpload', UploadedFile::fake()->image('new-evidence.jpg'));

    $page->call('acceptProductionReload', $receipt)
        ->assertSet('journalDocumentUpload', fn ($file): bool => $file !== null && $file->getClientOriginalName() === 'new-evidence.jpg');
    $this->assertDatabaseCount('production_documents', 0);
});
