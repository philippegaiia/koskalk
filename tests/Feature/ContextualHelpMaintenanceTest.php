<?php

use App\Enums\HelpContentExportStatus;
use App\Filament\Pages\HelpContentMaintenance;
use App\Jobs\ExportHelpContent;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentManifest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function maintenanceHelpPackage(string $title = 'Formula basics'): array
{
    if (! SupportedLocale::query()->where('code', 'en')->exists()) {
        SupportedLocale::factory()->create(['code' => 'en']);
    }
    $uuid = (string) Str::uuid();

    return app(HelpContentManifest::class)->seal([
        'format_version' => 1, 'export_uuid' => (string) Str::uuid(), 'captured_at' => now()->toISOString(),
        'topics' => [[
            'public_id' => (string) Str::uuid(), 'key' => 'shared.formula_basics', 'domain' => 'shared_workbench', 'archived_at' => null,
            'locales' => [[
                'locale' => 'en', 'latest_revision_uuid' => $uuid, 'published_revision_uuid' => null, 'lock_version' => 1, 'published_by_uuid' => null, 'published_at' => null,
                'revisions' => [[
                    'public_id' => $uuid, 'revision_number' => 1, 'title' => $title, 'summary' => 'A concise explanation.', 'body_markdown' => '## Formula\nReview the formula before saving.',
                    'source_english_revision_uuid' => null, 'origin' => 'human', 'ai_model' => null, 'prompt_version' => null, 'created_by_uuid' => null, 'created_at' => now()->startOfSecond()->toISOString(),
                ]],
                'translation_requests' => [],
            ]],
        ]],
    ]);
}

function maintenanceHelpUpload(array $manifest): UploadedFile
{
    return UploadedFile::fake()->createWithContent('help.json', app(HelpContentManifest::class)->encode($manifest));
}

it('denies the maintenance page to non-administrators', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => false]));
    Livewire::test(HelpContentMaintenance::class)->assertForbidden();
});

it('previews a private package and applies selected drafts only', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $manifest = maintenanceHelpPackage();
    $page = Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload($manifest), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasNoErrors()
        ->assertSet('previewState.manifest_hash', $manifest['digest'])
        ->assertSet('selectedChanges', ['0'])
        ->assertSee('Formula basics');
    expect(HelpTopicRevision::query()->count())->toBe(0);
    $page->call('applyImport')->assertHasNoErrors()->assertSet('previewState', []);
    expect(HelpTopicRevision::query()->sole()->title)->toBe('Formula basics');
    expect(HelpTopicLocale::query()->sole()->published_revision_id)->toBeNull();
});

it('locks the reviewed hash and full preview against client tampering', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload(maintenanceHelpPackage()), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasNoErrors();
    expect(fn () => $page->set('previewState.manifest_hash', str_repeat('0', 64)))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(HelpTopicRevision::query()->count())->toBe(0);
});

it('rereads uploaded bytes and rejects a package or mode changed after preview', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload(maintenanceHelpPackage()), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasNoErrors();
    $page->set('data.manifest', [])->fillForm(['manifest' => maintenanceHelpUpload(maintenanceHelpPackage('Changed content')), 'mode' => 'bootstrap'])
        ->call('applyImport')->assertHasErrors(['manifest']);
    expect(HelpTopicRevision::query()->count())->toBe(0);
    $page->call('previewImport')->assertHasNoErrors()
        ->set('data.mode', 'restore-empty')->call('applyImport')->assertHasErrors(['manifest']);
});

it('rejects selections outside the reviewed differences', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload(maintenanceHelpPackage()), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasNoErrors()
        ->set('selectedChanges', ['999'])->call('applyImport')->assertHasErrors(['selectedChanges']);
    expect(HelpTopicRevision::query()->count())->toBe(0);
});

it('preserves a newer draft when an administrator applies a stale merge preview', function (): void {
    Storage::fake('r2_backups');
    $this->actingAs(User::factory()->admin()->create());
    $manifest = maintenanceHelpPackage();
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $page = Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload($manifest), 'mode' => 'merge-drafts'])
        ->call('previewImport')->assertHasNoErrors();
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id]);
    $locale->update(['latest_revision_id' => $revision->id, 'lock_version' => 1]);
    $page->call('applyImport')->assertHasErrors(['manifest']);
    expect(HelpTopicRevision::query()->count())->toBe(1);
    expect($locale->fresh()->latest_revision_id)->toBe($revision->id);
});

it('queues manual backups and retries only failed backups', function (): void {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test(HelpContentMaintenance::class)->call('requestExport')->assertHasNoErrors()->assertSee('Pending');
    $pending = HelpContentExport::query()->sole();
    Queue::assertPushed(ExportHelpContent::class, fn (ExportHelpContent $job): bool => $job->exportId === $pending->id);
    $failed = HelpContentExport::factory()->create(['status' => HelpContentExportStatus::Failed, 'error_message' => 'Remote verification failed.']);
    $page->call('retryExport', $failed->public_id)->assertHasNoErrors();
    Queue::assertPushed(ExportHelpContent::class, fn (ExportHelpContent $job): bool => $job->exportId === $failed->id);
    expect(fn () => $page->call('retryExport', $pending->public_id))->toThrow(ModelNotFoundException::class);
});

it('rechecks administrator access on later mutation requests', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test(HelpContentMaintenance::class);
    $admin->update(['is_admin' => false]);
    $page->call('requestExport')->assertForbidden();
    Queue::assertNothingPushed();
});

it('serves only verified private bytes to authenticated administrators by export UUID', function (): void {
    Storage::fake('r2_backups');
    $json = app(HelpContentManifest::class)->encode(maintenanceHelpPackage());
    Storage::disk('r2_backups')->put('help-content/test.json', $json);
    $export = HelpContentExport::factory()->create(['status' => HelpContentExportStatus::Succeeded, 'disk' => 'r2_backups', 'path' => 'help-content/test.json', 'checksum' => hash('sha256', $json), 'size_bytes' => strlen($json), 'completed_at' => now()]);
    $url = route('help-content-exports.download', $export);
    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create());
    $response = $this->get($url)->assertOk()->assertDownload('help-content-'.$export->public_id.'.json')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($json);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->get(route('help-content-exports.download', $export->id))->assertNotFound();
    Storage::disk('r2_backups')->put($export->path, 'X'.substr($json, 1));
    $this->get($url)->assertStatus(409);
    Storage::disk('r2_backups')->put($export->path, 'short');
    $this->get($url)->assertStatus(409);
    $export->update(['status' => HelpContentExportStatus::Failed]);
    $this->get($url)->assertNotFound();
});

it('restores a full package only into an empty help store', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $manifest = maintenanceHelpPackage();
    $manifest['topics'][0]['archived_at'] = now()->startOfSecond()->toISOString();
    $manifest['topics'][0]['locales'][0]['published_revision_uuid'] = $manifest['topics'][0]['locales'][0]['latest_revision_uuid'];
    $manifest['topics'][0]['locales'][0]['published_at'] = now()->startOfSecond()->toISOString();
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload($manifest), 'mode' => 'restore-empty'])
        ->call('previewImport')->assertHasNoErrors()
        ->call('applyImport')->assertHasNoErrors();
    $locale = HelpTopicLocale::query()->sole();
    expect($locale->published_revision_id)->toBe($locale->latest_revision_id);
    expect($locale->topic->archived_at)->not->toBeNull();
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload($manifest), 'mode' => 'restore-empty'])
        ->call('previewImport')->assertHasErrors(['manifest']);
});

it('validates the temporary JSON upload size extension and content before preview', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => UploadedFile::fake()->create('help.json', 10241, 'application/json'), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasFormErrors(['manifest']);
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => UploadedFile::fake()->createWithContent('help.txt', '{}'), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasFormErrors(['manifest']);
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => UploadedFile::fake()->createWithContent('help.json', '{invalid JSON}'), 'mode' => 'bootstrap'])
        ->call('previewImport')->assertHasErrors(['manifest']);
    expect(HelpTopic::query()->count())->toBe(0);
});

it('shows failed backup status and preserves the preview when a required pre-import backup fails', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $manifest = maintenanceHelpPackage();
    HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $disk = Mockery::mock(Storage::fake('r2_backups'))->makePartial();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::set('r2_backups', $disk);
    Livewire::test(HelpContentMaintenance::class)
        ->fillForm(['manifest' => maintenanceHelpUpload($manifest), 'mode' => 'merge-drafts'])
        ->call('previewImport')->assertHasNoErrors()
        ->call('applyImport')->assertHasErrors(['manifest'])
        ->assertSet('previewState.manifest_hash', $manifest['digest'])
        ->assertSee('Failed');
    expect(HelpTopicRevision::query()->count())->toBe(0);
    expect(HelpContentExport::query()->sole()->status)->toBe(HelpContentExportStatus::Failed);
});
