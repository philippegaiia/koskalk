<?php

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Enums\WorkspaceMemberRole;
use App\Jobs\NormalizeMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\Plan;
use App\Models\ProductionDocument;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use App\Services\MediaAssetLibraryService;
use App\Services\MediaAssetProcessingService;
use App\Services\MediaAssetUploadService;
use App\Services\PdfPreviewRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('limits editor cleanup to their own unreferenced pending assets', function () {
    [$owner, $workspace] = mediaRecoveryWorkspace();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $ownProcessing = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
        'status' => MediaAssetStatus::Processing,
    ]);
    $ownFailed = MediaAsset::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
    ]);
    $ownReady = MediaAsset::factory()->ready()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
    ]);
    $otherUploader = User::factory()->create();
    $otherUpload = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $otherUploader->id,
        'status' => MediaAssetStatus::Processing,
    ]);
    $usageAsset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
        'status' => MediaAssetStatus::Failed,
    ]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    MediaAssetUsage::factory()->create([
        'media_asset_id' => $usageAsset->id,
        'usable_type' => Recipe::class,
        'usable_id' => $recipe->id,
        'role' => MediaAssetUsageRole::RecipeFeatured,
    ]);
    $documentAsset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
        'status' => MediaAssetStatus::Processing,
    ]);
    ProductionDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'media_asset_id' => $documentAsset->id,
        'attached_by_user_id' => $owner->id,
    ]);
    $otherOwner = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['owner_user_id' => $otherOwner->id]);
    $crossWorkspace = MediaAsset::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'uploaded_by_user_id' => $editor->id,
        'status' => MediaAssetStatus::Processing,
    ]);

    expect(Gate::forUser($editor)->allows('delete', $ownProcessing))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $ownFailed))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $ownReady))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $otherUpload))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $usageAsset))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $documentAsset))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('delete', $crossWorkspace))->toBeFalse();

    $admin = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($admin)->create([
        'role' => WorkspaceMemberRole::Admin,
    ]);

    expect(Gate::forUser($owner)->allows('delete', $ownReady))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $ownReady))->toBeTrue();
});

it('retains document image source resolution while ordinary images stay capped and PDFs keep the 150 KB limit', function () {
    Storage::fake('local');
    config()->set('media.asset_disk', 'local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media-library.disk_name', 'local');
    config()->set('media-library.conversions_disk_name', 'local');

    [$user, $workspace] = mediaRecoveryWorkspace(limit: 3);
    $documentImage = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('supplier-document.jpg', 1500, 2000),
        [MediaAssetType::Image],
        processSynchronously: true,
        documentImage: true,
    );
    $documentMaster = $documentImage->getFirstMedia('master');

    expect($documentImage->refresh()->document_image)->toBeTrue()
        ->and($documentImage->status)->toBe(MediaAssetStatus::Ready)
        ->and($documentMaster)->not->toBeNull()
        ->and($documentMaster->file_name)->toEndWith('.webp')
        ->and(mediaRecoveryImageDimensions($documentMaster->getPath()))->toBe([1500, 2000])
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user))
        ->toMatchArray(['used' => 1, 'limit' => 3, 'remaining' => 2]);

    $ordinaryImage = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        UploadedFile::fake()->image('ordinary.jpg', 1500, 2000),
        [MediaAssetType::Image],
        processSynchronously: true,
    );
    $ordinaryMaster = $ordinaryImage->getFirstMedia('master');

    expect(mediaRecoveryImageDimensions($ordinaryMaster->getPath()))->toBe([600, 800]);

    mock(PdfPreviewRenderer::class)
        ->shouldReceive('pageCount')->once()->andReturnNull()
        ->shouldReceive('renderFirstPage')->once()->andReturnNull();
    $pdf = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        mediaRecoveryPdfUpload(150 * 1024),
        [MediaAssetType::Image, MediaAssetType::Pdf],
        processSynchronously: true,
        documentImage: true,
    );

    expect($pdf->refresh()->type)->toBe(MediaAssetType::Pdf)
        ->and($pdf->document_image)->toBeFalse()
        ->and($pdf->original_size)->toBe(150 * 1024)
        ->and($pdf->status)->toBe(MediaAssetStatus::Ready)
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user)['used'])->toBe(3);
});

it('does not republish a PDF after cancellation removes it before publishing', function () {
    Storage::fake('local');
    config()->set('media.asset_disk', 'local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media-library.disk_name', 'local');
    config()->set('media-library.conversions_disk_name', 'local');
    Queue::fake();

    [$user, $workspace] = mediaRecoveryWorkspace(limit: 1);
    $asset = app(MediaAssetUploadService::class)->start(
        $user,
        $workspace,
        mediaRecoveryPdfUpload(),
        [MediaAssetType::Pdf],
    );
    $assetId = $asset->id;
    $processingToken = $asset->processing_token;
    $pendingPath = $asset->pending_path;
    $previewPath = mediaRecoveryPreviewPath();

    mock(PdfPreviewRenderer::class)
        ->shouldReceive('pageCount')->once()->andReturn(1)
        ->shouldReceive('renderFirstPage')->once()->andReturnUsing(
            function () use ($asset, $previewPath, $user): string {
                app(MediaAssetLibraryService::class)->remove($user, $asset);

                return $previewPath;
            },
        );

    (new NormalizeMediaAssetJob($assetId, $processingToken))
        ->handle(app(MediaAssetProcessingService::class));

    Queue::assertPushed(NormalizeMediaAssetJob::class);
    expect(MediaAsset::query()->find($assetId))->toBeNull()
        ->and(Media::query()->where('model_type', MediaAsset::class)->where('model_id', $assetId)->exists())
        ->toBeFalse()
        ->and(Storage::disk('local')->exists($pendingPath))->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(file_exists($previewPath))->toBeFalse()
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($user)['used'])->toBe(0);
});

it('exposes editor removal and deletes their pending processing upload', function () {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');

    [$owner, $workspace] = mediaRecoveryWorkspace(limit: 1);
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $pendingPath = 'media-assets/pending/editor-cancel.jpg';
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $editor->id,
        'status' => MediaAssetStatus::Processing,
        'pending_disk' => 'local',
        'pending_path' => $pendingPath,
    ]);
    Storage::disk('local')->put($pendingPath, 'pending');

    $this->actingAs($editor)
        ->getJson(route('media.status', $asset))
        ->assertOk()
        ->assertJsonPath('remove_url', route('media.remove', $asset));

    $this->actingAs($editor)
        ->deleteJson(route('media.remove', $asset))
        ->assertOk()
        ->assertJsonPath('removed', true);

    expect(MediaAsset::query()->find($asset->id))->toBeNull()
        ->and(Storage::disk('local')->exists($pendingPath))->toBeFalse()
        ->and(app(EntitlementService::class)->mediaAssetUsageFor($editor)['used'])->toBe(0);
});

/**
 * @return array{User, Workspace}
 */
function mediaRecoveryWorkspace(?int $limit = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

    if ($limit !== null) {
        $plan = Plan::factory()
            ->hasLimit('media_assets', $limit)
            ->create(['is_default' => true]);

        $user->entitlements()->create([
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);
    }

    return [$user, $workspace];
}

function mediaRecoveryPdfUpload(int $bytes = 150 * 1024): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'media-recovery-pdf-');
    $header = "%PDF-1.4\n";
    file_put_contents($path, $header.str_repeat(' ', $bytes - strlen($header)));

    return new UploadedFile($path, 'supplier-document.pdf', 'application/pdf', null, true);
}

/**
 * @return array{int, int}
 */
function mediaRecoveryImageDimensions(string $path): array
{
    $dimensions = getimagesize($path);

    return [$dimensions[0], $dimensions[1]];
}

function mediaRecoveryPreviewPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'media-recovery-preview-');
    $image = imagecreatetruecolor(600, 800);
    imagewebp($image, $path, 85);
    imagedestroy($image);

    return $path;
}
