<?php

use App\Enums\ProductionDocumentType;
use App\Enums\WorkspaceMemberRole;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\MediaAssetUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function rollbackEditorFixture(): array
{
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $asset = app(MediaAssetUploadService::class)->start(
        $editor,
        $workspace,
        UploadedFile::fake()->image('rollback.jpg'),
        processSynchronously: true,
    )->refresh();

    return [$owner, $editor, $workspace, $asset];
}

it('lets an editor roll back only their new unreferenced asset and private files', function (): void {
    [, $editor, $workspace, $asset] = rollbackEditorFixture();

    expect(Storage::disk('local')->allFiles())->not->toBeEmpty();

    app(MediaAssetUploadService::class)->rollbackUnreferencedUpload($editor, $workspace, $asset);

    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects rollback by another uploader even when they can access the workspace', function (): void {
    [$owner, , $workspace, $asset] = rollbackEditorFixture();

    expect(fn () => app(MediaAssetUploadService::class)->rollbackUnreferencedUpload($owner, $workspace, $asset))
        ->toThrow(AuthorizationException::class);

    expect($asset->fresh())->not->toBeNull();
});

it('rejects rollback when the asset has a media usage', function (): void {
    [, $editor, $workspace, $asset] = rollbackEditorFixture();
    MediaAssetUsage::factory()->for($asset)->create();

    expect(fn () => app(MediaAssetUploadService::class)->rollbackUnreferencedUpload($editor, $workspace, $asset))
        ->toThrow(ValidationException::class);

    expect($asset->fresh())->not->toBeNull();
});

it('rejects rollback when the asset is attached to a production document', function (): void {
    [, $editor, $workspace, $asset] = rollbackEditorFixture();
    $lot = StockLot::factory()->for($workspace)->create();
    ProductionDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'media_asset_id' => $asset->id,
        'documentable_type' => $lot->getMorphClass(),
        'documentable_id' => $lot->id,
        'type' => ProductionDocumentType::CertificateOfAnalysis,
        'attached_by_user_id' => $editor->id,
    ]);

    expect(fn () => app(MediaAssetUploadService::class)->rollbackUnreferencedUpload($editor, $workspace, $asset))
        ->toThrow(ValidationException::class);

    expect($asset->fresh())->not->toBeNull();
});
