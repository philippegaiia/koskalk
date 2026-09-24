<?php

use App\Actions\ContextualHelp\RequestHelpContentExport;
use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Jobs\ExportHelpContent;
use App\Models\HelpContentExport;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\User;
use App\Services\ContextualHelp\HelpContentExportService;
use App\Services\ContextualHelp\HelpContentManifest;
use App\Services\ContextualHelp\HelpContentSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('verifies private remote bytes and treats duplicate successful delivery as a no-op', function (): void {
    Storage::fake('r2_backups');
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id]);
    $locale->update(['latest_revision_id' => $revision->id]);
    $service = app(HelpContentExportService::class);
    $export = $service->request(HelpContentExportReason::Scheduled, dispatch: false);
    $job = new ExportHelpContent($export->id);
    $job->handle($service);
    $export->refresh();

    expect($export->status)->toBe(HelpContentExportStatus::Succeeded);
    $json = Storage::disk('r2_backups')->get($export->path);
    expect(hash('sha256', $json))->toBe($export->checksum);
    expect(strlen($json))->toBe($export->size_bytes);
    expect(Storage::disk('r2_backups')->getVisibility($export->path))->toBe('private');
    expect(app(HelpContentManifest::class)->decode($json)['export_uuid'])->toBe($export->public_id);
    $job->handle($service);
    expect(Storage::disk('r2_backups')->allFiles())->toHaveCount(1);
    expect($job->connection)->toBe('database');
    expect($job->queue)->toBe(config('ingredient-enrichment.direct_ai.queue'));
});

it('records remote verification failure visibly and can retry without overwriting an earlier attempt', function (): void {
    $storage = Storage::fake('r2_backups');
    $service = app(HelpContentExportService::class);
    $export = $service->request(HelpContentExportReason::Manual, dispatch: false);
    $disk = Mockery::mock($storage)->makePartial();
    $disk->shouldReceive('size')->once()->andReturn(0);
    Storage::set('r2_backups', $disk);
    expect(fn () => $service->run($export))->toThrow(RuntimeException::class, 'Snapshot verification failed.');
    expect($export->refresh()->status)->toBe(HelpContentExportStatus::Failed);
    expect($export->error_code)->toBe('snapshot_failed');
    expect($export->path)->toBeNull();
    Storage::set('r2_backups', $storage);
    $service->run($export);
    expect($export->refresh()->status)->toBe(HelpContentExportStatus::Succeeded);
    expect($storage->allFiles())->toHaveCount(2);
});

it('does not let a delayed worker replace a reclaimed processing token or status', function (): void {
    $storage = Storage::fake('r2_backups');
    $service = app(HelpContentExportService::class);
    $export = $service->request(HelpContentExportReason::Manual, dispatch: false);
    $replacement = (string) Str::uuid();
    $disk = Mockery::mock($storage)->makePartial();
    $disk->shouldReceive('put')->once()->andReturnUsing(function (string $path, string $json, array $options) use ($storage, $export, $replacement): bool {
        HelpContentExport::query()->whereKey($export->id)->update(['processing_token' => $replacement, 'status' => HelpContentExportStatus::Failed]);

        return $storage->put($path, $json, $options);
    });
    Storage::set('r2_backups', $disk);
    $service->run($export);
    expect($export->refresh()->processing_token)->toBe($replacement);
    expect($export->status)->toBe(HelpContentExportStatus::Failed);
    expect($export->path)->toBeNull();
});

it('requires administrator permission for manual snapshots and queues on the database content queue', function (): void {
    Queue::fake();
    $member = User::factory()->create(['is_admin' => false]);
    expect(fn () => app(RequestHelpContentExport::class)->handle($member))->toThrow(AuthorizationException::class);
    expect(HelpContentExport::query()->count())->toBe(0);
    $admin = User::factory()->create(['is_admin' => true]);
    $export = app(RequestHelpContentExport::class)->handle($admin);
    expect($export->requested_by)->toBe($admin->id);
    expect($export->status)->toBe(HelpContentExportStatus::Pending);
    Queue::assertPushed(ExportHelpContent::class, fn (ExportHelpContent $job): bool => $job->exportId === $export->id && $job->connection === 'database' && $job->queue === config('ingredient-enrichment.direct_ai.queue'));
});

it('rejects a remote checksum mismatch even when the remote size is correct', function (): void {
    $storage = Storage::fake('r2_backups');
    $disk = Mockery::mock($storage)->makePartial();
    $disk->shouldReceive('get')->once()->andReturnUsing(function (string $path) use ($storage): string {
        $json = $storage->get($path);

        return 'X'.substr($json, 1);
    });
    Storage::set('r2_backups', $disk);
    $service = app(HelpContentExportService::class);
    $export = $service->request(HelpContentExportReason::Manual, dispatch: false);
    expect(fn () => $service->run($export))->toThrow(RuntimeException::class, 'Snapshot verification failed.');
    expect($export->refresh()->status)->toBe(HelpContentExportStatus::Failed);
    expect($export->checksum)->toBeNull();
});

it('does not send a manually queued snapshot after its requesting administrator is deleted', function () {
    Storage::fake('r2_backups');
    $admin = User::factory()->admin()->create();
    $service = app(HelpContentExportService::class);
    $export = $service->request(HelpContentExportReason::Manual, $admin, dispatch: false);
    $admin->delete();
    expect(fn () => $service->run($export->refresh()))->toThrow(RuntimeException::class)
        ->and($export->refresh()->status)->toBe(HelpContentExportStatus::Failed)
        ->and(Storage::disk('r2_backups')->allFiles())->toBe([]);
});

it('exports authored history without depending on nonexistent portable user identities', function (): void {
    $author = User::factory()->admin()->create();
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id, 'created_by' => $author->id]);
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id, 'published_by' => $author->id, 'published_at' => now()]);
    $manifest = app(HelpContentSnapshot::class)->capture();
    $exported = $manifest['topics'][0]['locales'][0];
    expect($exported['published_by_uuid'])->toBeNull()
        ->and($exported['revisions'][0]['created_by_uuid'])->toBeNull()
        ->and($revision->fresh()->created_by)->toBe($author->id);
});

it('combines publication requests into one delayed backup but starts a new one after processing starts', function (): void {
    Queue::fake();
    $actor = User::factory()->admin()->create();
    $service = app(HelpContentExportService::class);
    $this->travelTo(now()->startOfSecond());

    foreach (range(1, 88) as $index) {
        $service->requestPublication($actor->id);
    }

    $export = HelpContentExport::query()->sole();
    Queue::assertPushed(ExportHelpContent::class, 1);
    Queue::assertPushed(ExportHelpContent::class, fn ($job): bool => $job->delay->equalTo(now()->addMinutes(2)));
    $export->update(['status' => HelpContentExportStatus::Running]);
    $service->requestPublication($actor->id);
    expect(HelpContentExport::query()->count())->toBe(2);
    Queue::assertPushed(ExportHelpContent::class, 2);
});

it('skips unchanged scheduled content but backs up draft and publication changes', function (): void {
    Storage::fake('r2_backups');
    $topic = HelpTopic::factory()->create(['key' => 'shared.formula_basics']);
    $locale = HelpTopicLocale::factory()->create(['help_topic_id' => $topic->id]);
    $revision = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id]);
    $locale->update(['latest_revision_id' => $revision->id]);
    $service = app(HelpContentExportService::class);
    $service->scheduled();
    $this->travel(1)->day();

    $this->artisan('help:snapshot')->expectsOutput('Help content is unchanged; no new backup needed.')->assertSuccessful();
    expect(HelpContentExport::query()->count())->toBe(1);

    $locale->update(['published_revision_id' => $revision->id]);
    expect($service->scheduled()?->status)->toBe(HelpContentExportStatus::Succeeded);
    $draft = HelpTopicRevision::factory()->create(['help_topic_locale_id' => $locale->id, 'revision_number' => 2]);
    $locale->update(['latest_revision_id' => $draft->id]);
    expect($service->scheduled()?->status)->toBe(HelpContentExportStatus::Succeeded);
    expect(Storage::disk('r2_backups')->allFiles())->toHaveCount(3);
});

it('replaces a missing scheduled backup and always allows manual and pre-import backups', function (): void {
    Storage::fake('r2_backups');
    $service = app(HelpContentExportService::class);
    $first = $service->scheduled();
    Storage::disk('r2_backups')->delete($first->path);

    expect($service->scheduled()?->status)->toBe(HelpContentExportStatus::Succeeded);
    expect($service->run($service->request(HelpContentExportReason::Manual, dispatch: false))->status)->toBe(HelpContentExportStatus::Succeeded);
    expect($service->beforeImport()->status)->toBe(HelpContentExportStatus::Succeeded);
    expect(HelpContentExport::query()->count())->toBe(4);
});

it('does not request a publication backup for a rolled back change', function (): void {
    Queue::fake();
    $actor = User::factory()->admin()->create();
    $service = app(HelpContentExportService::class);

    expect(fn () => DB::transaction(function () use ($service, $actor): void {
        $service->requestPublication($actor->id);
        throw new RuntimeException('Publication rolled back.');
    }))->toThrow(RuntimeException::class, 'Publication rolled back.');

    expect(HelpContentExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
