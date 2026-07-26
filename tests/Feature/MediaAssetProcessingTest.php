<?php

use App\Exceptions\MediaAssetProcessingException;
use App\Jobs\NormalizeMediaAssetJob;
use App\Jobs\RegenerateMediaAssetConversionsJob;
use App\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\MediaAssetProcessingService;
use App\Services\MediaAssetUploadService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects a text payload renamed as an image before storing pending media', function () {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$user, $workspace] = mediaProcessingWorkspace(10);
    $upload = UploadedFile::fake()->createWithContent('renamed.jpg', 'plain text, not an image');

    expect(fn () => app(MediaAssetUploadService::class)->start($user, $workspace, $upload))
        ->toThrow(ValidationException::class);

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media-assets/pending'))->toBeEmpty();
});

it('turns pending storage failures into a retryable upload validation error', function () {
    Queue::fake();
    config()->set('media.asset_pending_disk', 'unconfigured-pending-disk');
    [$user, $workspace] = mediaProcessingWorkspace(10);

    expect(fn () => app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('product.png'),
    ))->toThrow(ValidationException::class, 'could not be stored');

    expect(MediaAsset::query()->count())->toBe(0);
});

it('accepts a recognized heic container when finfo reports a generic mime', function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$user, $workspace] = mediaProcessingWorkspace(10);
    $path = tempnam(sys_get_temp_dir(), 'portable-heic-');
    file_put_contents($path, "\0\0\0\x18ftypheicportable");
    $upload = new class($path, 'portable.heic', 'application/octet-stream', null, true) extends UploadedFile
    {
        public function getMimeType(): string
        {
            return 'application/octet-stream';
        }
    };

    $asset = app(MediaAssetUploadService::class)->start($user, $workspace, $upload);

    expect($asset->original_filename)->toBe('portable.heic')
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);
});

it('rejects image extension and content signature mismatches', function (string $filename, string $content) {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    [$user, $workspace] = mediaProcessingWorkspace(10);
    $upload = UploadedFile::fake()->createWithContent($filename, $content);

    expect(fn () => app(MediaAssetUploadService::class)->start($user, $workspace, $upload))
        ->toThrow(ValidationException::class);

    expect(MediaAsset::query()->count())->toBe(0);
})->with([
    'jpeg extension with png content' => ['mismatch.jpg', "\x89PNG\r\n\x1A\ncontent"],
    'png extension with jpeg content' => ['mismatch.png', "\xFF\xD8\xFFcontent"],
    'heic extension with jpeg content' => ['mismatch.heic', "\xFF\xD8\xFFcontent"],
]);

beforeEach(function () {
    Storage::fake('local');
    config()->set('media.asset_disk', 'local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media-library.disk_name', 'local');
    config()->set('media-library.conversions_disk_name', 'local');
});

it('defaults media processing to a 25 megapixel limit', function () {
    expect(config('media.asset_uploads.max_pixels'))->toBe(25_000_000);
});

it('reports the configured megapixel limit when dimensions are excessive', function () {
    config()->set('media.asset_uploads.max_pixels', 25_000_000);
    $method = new ReflectionMethod(MediaAssetProcessingService::class, 'assertPixelLimit');

    expect(fn () => $method->invoke(app(MediaAssetProcessingService::class), 5001, 5000))
        ->toThrow(MediaAssetProcessingException::class, '25 megapixels');
});

it('reserves quota, stores an opaque pending file, and queues processing', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 2);
    $upload = UploadedFile::fake()->image('My product portrait.jpg', 1200, 900);

    $asset = app(MediaAssetUploadService::class)->start($user, $workspace, $upload);

    expect($asset->status)->toBe(MediaAssetStatus::Processing)
        ->and($asset->original_filename)->toBe('My product portrait.jpg')
        ->and($asset->pending_path)->not->toContain('My product portrait')
        ->and(Storage::disk('local')->exists($asset->pending_path))->toBeTrue()
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user)['used'])->toBe(1);

    Queue::assertPushedOn('media', NormalizeMediaAssetJob::class);
});

it('blocks only the new upload at quota and removes its pending file', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 1);

    app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('first.jpg'),
    );

    expect(fn () => app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('blocked.jpg'),
    ))->toThrow(ValidationException::class, '1 media assets');

    expect(MediaAsset::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('media-assets/pending'))->toHaveCount(1);
});

it('marks an invalid image failed, releases quota, and retains the source for retry', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 1);
    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->createWithContent('broken.heic', "\0\0\0\x18ftypheicbroken"),
    );
    $pendingPath = $asset->pending_path;

    (new NormalizeMediaAssetJob($asset->id, $asset->processing_token))
        ->handle(app(MediaAssetProcessingService::class));

    $asset->refresh();

    expect($asset->status)->toBe(MediaAssetStatus::Failed)
        ->and($asset->failure_reason)->not->toBeEmpty()
        ->and(Storage::disk('local')->exists($pendingPath))->toBeTrue()
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user))
        ->toMatchArray([
            'used' => 0,
            'limit' => 1,
            'remaining' => 1,
            'allowed' => true,
        ]);
});

it('re-reserves quota and rotates the processing token when retrying a failure', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 1);
    $asset = MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $user->id,
        'pending_disk' => 'local',
        'pending_path' => 'media-assets/pending/source.heic',
    ]);
    Storage::disk('local')->put($asset->pending_path, 'source');
    $oldToken = $asset->processing_token;

    $retried = app(MediaAssetUploadService::class)->retry($user, $asset);

    expect($retried->status)->toBe(MediaAssetStatus::Processing)
        ->and($retried->processing_token)->not->toBe($oldToken)
        ->and($retried->failure_reason)->toBeNull()
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user)['used'])->toBe(1);

    Queue::assertPushedOn('media', NormalizeMediaAssetJob::class);
});

it('ignores a stale queued job after retry or removal rotates the token', function () {
    [$user, $workspace] = mediaProcessingWorkspace(limit: 2);
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $user->id,
        'processing_token' => fake()->uuid(),
    ]);

    (new NormalizeMediaAssetJob($asset->id, fake()->uuid()))
        ->handle(app(MediaAssetProcessingService::class));

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Processing);
});

it('creates an uncropped 800 master and the required focal and contained conversions', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 2);
    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('portrait.png', 900, 1200),
    );

    (new NormalizeMediaAssetJob($asset->id, $asset->processing_token))
        ->handle(app(MediaAssetProcessingService::class));

    $asset->refresh();
    $master = $asset->getFirstMedia('master');

    expect($asset->status)->toBe(MediaAssetStatus::Ready)
        ->and($asset->progress)->toBe(100)
        ->and($asset->pending_path)->toBeNull()
        ->and($master)->not->toBeNull()
        ->and($master->file_name)->toEndWith('.webp')
        ->and(mediaImageDimensions($master->getPath()))->toBe([600, 800])
        ->and(mediaImageDimensions($master->getPath('recipe-index')))->toBe([360, 480])
        ->and(mediaImageDimensions($master->getPath('catalog')))->toBe([400, 400])
        ->and(mediaImageDimensions($master->getPath('thumbnail')))->toBe([240, 240])
        ->and(mediaImageDimensions($master->getPath('icon')))->toBe([96, 96])
        ->and(Storage::disk('local')->allFiles('media-assets/pending'))->toBeEmpty();
});

it('upscales small source images to every required square conversion size', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 2);
    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('small.png', 80, 60),
    );

    (new NormalizeMediaAssetJob($asset->id, $asset->processing_token))
        ->handle(app(MediaAssetProcessingService::class));

    $master = $asset->refresh()->getFirstMedia('master');

    expect($master)->not->toBeNull()
        ->and(mediaImageDimensions($master->getPath('catalog')))->toBe([400, 400])
        ->and(mediaImageDimensions($master->getPath('thumbnail')))->toBe([240, 240])
        ->and(mediaImageDimensions($master->getPath('icon')))->toBe([96, 96]);
});

it('preserves an already compliant WebP master without re-encoding it', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 1);
    $sourcePath = tempnam(sys_get_temp_dir(), 'media-webp-source-');

    expect($sourcePath)->not->toBeFalse();

    $sourceImage = imagecreatetruecolor(680, 510);
    imagewebp($sourceImage, $sourcePath, 85);
    imagedestroy($sourceImage);

    $sourceBytes = file_get_contents($sourcePath);

    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        new UploadedFile($sourcePath, 'already-optimized.webp', 'image/webp', null, true),
    );
    $pendingPath = $asset->pending_path;

    (new NormalizeMediaAssetJob($asset->id, $asset->processing_token))
        ->handle(app(MediaAssetProcessingService::class));

    $asset->refresh();
    $master = $asset->getFirstMedia('master');

    expect($asset->status)->toBe(MediaAssetStatus::Ready)
        ->and($master)->not->toBeNull()
        ->and(hash_file('sha256', $master->getPath()))->toBe(hash('sha256', $sourceBytes))
        ->and(Storage::disk('local')->exists($pendingPath))->toBeFalse();
});

it('recognizes a metadata-free WebP container without relying on Imagick', function () {
    $sourcePath = tempnam(sys_get_temp_dir(), 'media-webp-container-');

    expect($sourcePath)->not->toBeFalse();

    $sourceImage = imagecreatetruecolor(680, 510);
    imagewebp($sourceImage, $sourcePath, 85);
    imagedestroy($sourceImage);

    $method = new ReflectionMethod(MediaAssetProcessingService::class, 'webpContainerDetails');
    $details = $method->invoke(app(MediaAssetProcessingService::class), $sourcePath);

    expect($details)->toBe([
        'width' => 680,
        'height' => 510,
        'has_orientation_or_metadata' => false,
    ]);
});

it('rejects a WebP container with bytes trailing beyond its declared RIFF boundary', function () {
    $sourcePath = metadataFreeWebpPath();
    file_put_contents($sourcePath, "\0", FILE_APPEND);

    expect(webpContainerDetails($sourcePath))->toBeNull();
});

it('rejects a WebP container containing duplicate image chunks', function () {
    $sourcePath = metadataFreeWebpPath();
    $contents = file_get_contents($sourcePath);
    $firstChunk = substr($contents, 12);
    $contents .= $firstChunk;
    $contents = substr_replace($contents, pack('V', strlen($contents) - 8), 4, 4);
    file_put_contents($sourcePath, $contents);

    expect(webpContainerDetails($sourcePath))->toBeNull();
});

it('uses a unique processing identity that permits a retried token', function () {
    $first = new NormalizeMediaAssetJob(42, 'first-token');
    $retry = new NormalizeMediaAssetJob(42, 'retry-token');

    expect($first)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($first->uniqueId())->toBe('media-asset:42:first-token')
        ->and($retry->uniqueId())->toBe('media-asset:42:retry-token');
});

it('uses a unique conversion regeneration identity per asset', function () {
    $job = new RegenerateMediaAssetConversionsJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('media-asset:42');
});

it('marks an asset failed when framework-level job failure handling runs', function () {
    Queue::fake();
    [$user, $workspace] = mediaProcessingWorkspace(limit: 1);
    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('escaping-failure.jpg'),
    );

    (new NormalizeMediaAssetJob($asset->id, $asset->processing_token))
        ->failed(new RuntimeException('Worker failure'));

    expect($asset->refresh()->status)->toBe(MediaAssetStatus::Failed)
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user)['used'])->toBe(0);
});

/**
 * @return array{User, Workspace}
 */
function mediaProcessingWorkspace(int $limit): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    $plan = Plan::factory()
        ->hasLimit('media_assets', $limit)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    return [$user, $workspace];
}

/**
 * @return array{int, int}
 */
function mediaImageDimensions(string $path): array
{
    $dimensions = getimagesize($path);

    return [$dimensions[0], $dimensions[1]];
}

function metadataFreeWebpPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'media-webp-malicious-');
    $image = imagecreatetruecolor(64, 48);
    imagewebp($image, $path, 85);
    imagedestroy($image);

    return $path;
}

/**
 * @return array{width: int, height: int, has_orientation_or_metadata: bool}|null
 */
function webpContainerDetails(string $path): ?array
{
    $method = new ReflectionMethod(MediaAssetProcessingService::class, 'webpContainerDetails');

    return $method->invoke(app(MediaAssetProcessingService::class), $path);
}
