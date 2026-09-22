<?php

use App\Actions\ContextualHelp\ImportHelpContent;
use App\Enums\HelpContentExportStatus;
use App\Enums\HelpContentImportMode;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentImporter;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpContentSnapshot;
use App\Services\ContextualHelp\HelpTopicRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function portableHelpManifest(): array
{
    foreach (['en', 'fr'] as $code) {
        if (! SupportedLocale::query()->where('code', $code)->exists()) {
            SupportedLocale::factory()->create(['code' => $code]);
        }
    }
    $manifest = [];
    try {
        DB::transaction(function () use (&$manifest): void {
            $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
            $english = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
            $published = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
            $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
            $english->update(['latest_revision_id' => $draft->id, 'published_revision_id' => $published->id, 'lock_version' => 3, 'published_at' => now()]);
            $french = HelpTopicLocale::factory()->translated()->create(['help_topic_id' => $topic->id]);
            $translation = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $french->id, 'source_english_revision_id' => $published->id]);
            $french->update(['latest_revision_id' => $translation->id, 'published_revision_id' => $translation->id]);
            HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $french->id, 'source_english_revision_id' => $draft->id, 'status' => 'completed', 'result' => ['title' => 'Candidate', 'summary' => 'Candidate summary', 'body_markdown' => null], 'response_id' => 'resp_test', 'input_tokens' => 40]);
            HelpTranslationRequest::factory()->create(['help_topic_locale_id' => $french->id, 'source_english_revision_id' => $draft->id, 'status' => 'running']);
            $manifest = app(HelpContentSnapshot::class)->capture();
            throw new RuntimeException('rollback fixture');
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'rollback fixture') {
            throw $exception;
        }
    }

    return $manifest;
}

it('round trips published heads newer drafts stale sources and translation audit without creating missing authors', function (): void {
    $manifest = portableHelpManifest();
    $manifest['topics'][0]['archived_at'] = now()->toISOString();
    $manifest['topics'][0]['locales'][0]['revisions'][0]['created_by_uuid'] = (string) Str::uuid();
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::RestoreEmpty);
    $result = $importer->apply($manifest, HelpContentImportMode::RestoreEmpty, $preview['selection'], $preview['manifest_hash']);

    expect($result['imported'])->toBe(3);
    $english = HelpTopicLocale::query()->where('locale', 'en')->sole();
    expect($english->latestRevision->revision_number)->toBe(2);
    expect($english->publishedRevision->revision_number)->toBe(1);
    expect($english->publishedRevision->created_by)->toBeNull();
    expect($english->topic->archived_at)->not->toBeNull();
    expect(HelpTopicLocale::query()->where('locale', 'fr')->sole()->latestRevision->source_english_revision_id)->toBe($english->published_revision_id);
    expect(HelpTranslationRequest::query()->where('status', 'completed')->sole()->result['title'])->toBe('Candidate');
    expect(HelpTranslationRequest::query()->where('status', 'failed')->sole()->error_code)->toBe('restored_not_requeued');
    $export = app(HelpContentSnapshot::class)->capture();
    expect(app(HelpContentManifest::class)->validate($export)['format_version'])->toBe(1);
    expect(array_column($export['topics'][0]['locales'][0]['revisions'], 'public_id'))->toBe(array_column($manifest['topics'][0]['locales'][0]['revisions'], 'public_id'));
});

it('bootstraps registered empty identities and never publishes or overwrites existing content', function (): void {
    $manifest = portableHelpManifest();
    app(HelpTopicRegistry::class)->register();
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    $english = HelpTopicLocale::query()->sole();
    expect($english->locale)->toBe('en');
    expect($english->published_revision_id)->toBeNull();
    expect($english->latestRevision->title)->toBe($manifest['topics'][0]['locales'][0]['revisions'][1]['title']);
    $newDraft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
    $english->update(['latest_revision_id' => $newDraft->id, 'lock_version' => 2]);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect($english->fresh()->latest_revision_id)->toBe($newDraft->id);
});

it('merges selected drafts with a verified backup while preserving publication and newer local history on repeat import', function (): void {
    Storage::fake('r2_backups');
    $manifest = portableHelpManifest();
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics', 'archived_at' => now()]);
    $english = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $published = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id]);
    $english->update(['published_revision_id' => $published->id, 'latest_revision_id' => $published->id]);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, [$preview['selection'][1]], $preview['manifest_hash']);
    expect($english->fresh()->latest_revision_id)->toBe($published->id);
    expect($english->fresh()->published_revision_id)->toBe($published->id);
    expect($topic->fresh()->archived_at)->not->toBeNull();
    $french = $topic->locales()->where('locale', 'fr')->sole();
    expect($french->latestRevision->public_id)->toBe($manifest['topics'][0]['locales'][1]['latest_revision_uuid']);
    $newDraft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $french->id, 'source_english_revision_id' => $published->id]);
    $french->update(['latest_revision_id' => $newDraft->id, 'lock_version' => 2]);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, [$preview['selection'][1]], $preview['manifest_hash']);
    expect($french->fresh()->latest_revision_id)->toBe($newDraft->id);
    expect(HelpContentExport::query()->where('status', 'succeeded')->count())->toBe(2);
});

it('rejects whole-file corruption and cross references before any content writes', function (string $corruption): void {
    $manifest = portableHelpManifest();
    if ($corruption === 'digest') {
        $manifest['digest'] = str_repeat('0', 64);
    } elseif ($corruption === 'duplicate') {
        $manifest['topics'][0]['locales'][1]['revisions'][0]['public_id'] = $manifest['topics'][0]['locales'][0]['revisions'][0]['public_id'];
    } elseif ($corruption === 'source') {
        $manifest['topics'][0]['locales'][1]['revisions'][0]['source_english_revision_uuid'] = (string) Str::uuid();
    } elseif ($corruption === 'unsafe') {
        $manifest['topics'][0]['locales'][0]['revisions'][0]['body_markdown'] = '<script>alert(1)</script>';
    } elseif ($corruption === 'unknown') {
        $manifest['topics'][0]['key'] = 'unknown.topic';
    } else {
        $manifest['topics'][0]['locales'][0]['revisions'][0]['created_by'] = 1;
    }
    if ($corruption !== 'digest') {
        $manifest = app(HelpContentManifest::class)->seal($manifest);
    }
    expect(fn () => app(HelpContentImporter::class)->preview($manifest, HelpContentImportMode::RestoreEmpty))->toThrow(ValidationException::class);
    expect(HelpTopic::query()->count())->toBe(0);
})->with(['digest', 'duplicate', 'source', 'unsafe', 'unknown', 'numeric author']);

it('rejects conflicting immutable UUIDs and stale selected heads atomically', function (): void {
    Storage::fake('r2_backups');
    $manifest = portableHelpManifest();
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    HelpTopicLocale::query()->where('locale', 'en')->increment('lock_version');
    expect(fn () => $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']))->toThrow(ValidationException::class);
    expect(HelpTopicLocale::query()->where('locale', 'fr')->exists())->toBeFalse();
    $manifest['topics'][0]['locales'][0]['revisions'][0]['title'] = 'Conflicting title';
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    expect(fn () => $importer->preview($manifest, HelpContentImportMode::MergeDrafts))->toThrow(ValidationException::class);
});

it('requires entirely empty content tables for restore', function (): void {
    $manifest = portableHelpManifest();
    HelpTopic::factory()->create(['key' => 'shared.costing']);
    expect(fn () => app(HelpContentImporter::class)->preview($manifest, HelpContentImportMode::RestoreEmpty))->toThrow(ValidationException::class);
});

it('aborts a merge before content writes when its offsite backup fails', function (): void {
    $manifest = portableHelpManifest();
    HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $storage = Storage::fake('r2_backups');
    $disk = Mockery::mock($storage)->makePartial();
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::set('r2_backups', $disk);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    expect(fn () => $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']))->toThrow(RuntimeException::class);
    expect(HelpTopicRevision::query()->count())->toBe(0);
    expect(HelpContentExport::query()->sole()->status)->toBe(HelpContentExportStatus::Failed);
});

it('rolls back every restored row when persistence fails partway through the graph', function (): void {
    $manifest = portableHelpManifest();
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::RestoreEmpty);
    $dispatcher = HelpTopicRevision::getEventDispatcher();
    $isolated = clone $dispatcher;
    HelpTopicRevision::setEventDispatcher($isolated);
    HelpTopicRevision::creating(function (HelpTopicRevision $revision): void {
        if ($revision->revision_number === 2) {
            throw new RuntimeException('Test persistence failure');
        }
    });
    try {
        expect(fn () => $importer->apply($manifest, HelpContentImportMode::RestoreEmpty, $preview['selection'], $preview['manifest_hash']))->toThrow(RuntimeException::class, 'Test persistence failure');
    } finally {
        HelpTopicRevision::setEventDispatcher($dispatcher);
    }
    expect(HelpTopic::query()->count())->toBe(0);
    expect(HelpTopicLocale::query()->count())->toBe(0);
    expect(HelpTopicRevision::query()->count())->toBe(0);
});

it('rejects a changed manifest hash and unauthorized import without writing content', function (): void {
    $manifest = portableHelpManifest();
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    expect(fn () => $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], str_repeat('0', 64)))->toThrow(ValidationException::class);
    $member = User::factory()->create(['is_admin' => false]);
    expect(fn () => app(ImportHelpContent::class)->handle($member, $manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']))->toThrow(AuthorizationException::class);
    expect(HelpTopic::query()->count())->toBe(0);
});

it('limits input bytes and rejects unsupported locales and oversized content', function (): void {
    expect(fn () => app(HelpContentManifest::class)->decode(str_repeat(' ', HelpContentManifest::MAX_BYTES + 1)))->toThrow(ValidationException::class);
    $manifest = portableHelpManifest();
    $manifest['topics'][0]['locales'][1]['locale'] = 'zz';
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    expect(fn () => app(HelpContentManifest::class)->validate($manifest))->toThrow(ValidationException::class);
    $manifest['topics'][0]['locales'][1]['locale'] = 'fr';
    $manifest['topics'][0]['locales'][0]['revisions'][0]['title'] = str_repeat('A', 161);
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    expect(fn () => app(HelpContentManifest::class)->validate($manifest))->toThrow(ValidationException::class);
});

it('rejects malformed nested entries and ambiguous uppercase UUID identity', function (): void {
    $manifest = portableHelpManifest();
    $malformed = $manifest;
    $malformed['topics'][0]['locales'][0]['revisions'][0] = 'invalid';
    $malformed = app(HelpContentManifest::class)->seal($malformed);
    expect(fn () => app(HelpContentManifest::class)->validate($malformed))->toThrow(ValidationException::class);
    $manifest['topics'][0]['public_id'] = strtoupper($manifest['topics'][0]['public_id']);
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    expect(fn () => app(HelpContentManifest::class)->validate($manifest))->toThrow(ValidationException::class);
});

it('leaves an archived registered topic without locales untouched during bootstrap', function () {
    $manifest = portableHelpManifest();
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics', 'archived_at' => now()]);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::Bootstrap);
    expect($preview['changes'][0]['will_change'])->toBeFalse();
    $importer->apply($manifest, HelpContentImportMode::Bootstrap, $preview['selection'], $preview['manifest_hash']);
    expect($topic->locales()->count())->toBe(0)->and($topic->fresh()->archived_at)->not->toBeNull();
});

it('merges candidate audit even when the target has no translated draft and rejects request UUID conflicts', function () {
    Storage::fake('r2_backups');
    $manifest = portableHelpManifest();
    $manifest['topics'][0]['locales'][1]['revisions'] = [];
    $manifest['topics'][0]['locales'][1]['latest_revision_uuid'] = null;
    $manifest['topics'][0]['locales'][1]['published_revision_uuid'] = null;
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']);
    expect(HelpTranslationRequest::count())->toBe(2)
        ->and(HelpTranslationRequest::where('status', 'failed')->sole()->error_code)->toBe('restored_not_requeued')
        ->and(HelpTopicLocale::where('locale', 'fr')->sole()->latest_revision_id)->toBeNull();
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']);
    expect(HelpTranslationRequest::count())->toBe(2);
    $manifest['topics'][0]['locales'][1]['translation_requests'][0]['model'] = 'conflicting-model';
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    expect(fn () => $importer->preview($manifest, HelpContentImportMode::MergeDrafts))->toThrow(ValidationException::class);
});

it('preserves historical request timestamps and provider model across repeated imports', function () {
    Storage::fake('r2_backups');
    $manifest = portableHelpManifest();
    $request = &$manifest['topics'][0]['locales'][1]['translation_requests'][0];
    $request['created_at'] = '2025-01-02T03:04:05.000000Z';
    $request['updated_at'] = '2025-01-03T04:05:06.000000Z';
    $request['response_model'] = 'provider-model-2025-01';
    $requestUuid = $request['public_id'];
    unset($request);
    $manifest = app(HelpContentManifest::class)->seal($manifest);
    $importer = app(HelpContentImporter::class);
    $preview = $importer->preview($manifest, HelpContentImportMode::RestoreEmpty);
    $importer->apply($manifest, HelpContentImportMode::RestoreEmpty, $preview['selection'], $preview['manifest_hash']);
    $restored = HelpTranslationRequest::query()->where('public_id', $requestUuid)->sole();
    expect($restored->created_at->toISOString())->toBe('2025-01-02T03:04:05.000000Z');
    expect($restored->updated_at->toISOString())->toBe('2025-01-03T04:05:06.000000Z');
    expect($restored->model)->toBe('test-model');
    expect($restored->response_model)->toBe('provider-model-2025-01');
    $export = app(HelpContentSnapshot::class)->capture();
    expect($export['topics'][0]['locales'][1]['translation_requests'][0]['response_model'])->toBe('provider-model-2025-01');
    app(HelpContentManifest::class)->validate($export);
    $preview = $importer->preview($manifest, HelpContentImportMode::MergeDrafts);
    $importer->apply($manifest, HelpContentImportMode::MergeDrafts, $preview['selection'], $preview['manifest_hash']);
    expect(HelpTranslationRequest::query()->where('public_id', $requestUuid)->count())->toBe(1);
    expect($restored->refresh()->updated_at->toISOString())->toBe('2025-01-03T04:05:06.000000Z');
});
