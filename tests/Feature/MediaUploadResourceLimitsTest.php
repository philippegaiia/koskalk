<?php

use App\Enums\MediaAssetStatus;
use App\Enums\WorkspaceMemberRole;
use App\Jobs\NormalizeMediaAssetJob;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\MediaAssetUploadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['workspaces.collaboration_enabled' => true, 'media.asset_pending_disk' => 'local']);
    Storage::fake('local');
    Queue::fake([NormalizeMediaAssetJob::class]);
});

/** @return array{User, Workspace, User} */
function resourceLimitedMediaCompany(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->hasLimit('media_assets', 100)->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($owner)->for($plan)->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);

    return [$owner, $workspace, $editor];
}

it('shares the upload budget between company members and restores it after the window', function (): void {
    config(['media.asset_uploads.workspace_attempts_per_minute' => 1]);
    [$owner, $workspace, $editor] = resourceLimitedMediaCompany();
    $uploads = app(MediaAssetUploadService::class);
    $uploads->start($owner, $workspace, UploadedFile::fake()->image('first.png'));

    expect(fn () => $uploads->start($editor, $workspace, UploadedFile::fake()->image('blocked.png')))
        ->toThrow(ValidationException::class, 'too many uploads');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('media_assets', 1);
    Queue::assertPushed(NormalizeMediaAssetJob::class, 1);

    $this->travel(61)->seconds();
    $uploads->start($editor, $workspace, UploadedFile::fake()->image('later.png'));
    $this->assertDatabaseCount('media_assets', 2);
});

it('isolates company budgets and rejects foreign uploads before charging them', function (): void {
    config(['media.asset_uploads.workspace_attempts_per_minute' => 1]);
    [$owner, $workspace] = resourceLimitedMediaCompany();
    [$other, $otherWorkspace] = resourceLimitedMediaCompany();
    $uploads = app(MediaAssetUploadService::class);

    expect(fn () => $uploads->start($owner, $otherWorkspace, UploadedFile::fake()->image('foreign.png')))
        ->toThrow(AuthorizationException::class);
    $uploads->start($owner, $workspace, UploadedFile::fake()->image('one.png'));
    $uploads->start($other, $otherWorkspace, UploadedFile::fake()->image('two.png'));
    $this->assertDatabaseCount('media_assets', 2);
});

it('charges retries to the same company processing budget as new uploads', function (): void {
    config(['media.asset_uploads.workspace_attempts_per_minute' => 1]);
    [$owner, $workspace, $editor] = resourceLimitedMediaCompany();
    $uploads = app(MediaAssetUploadService::class);
    $asset = $uploads->start($owner, $workspace, UploadedFile::fake()->image('retry.png'));
    $asset->update(['status' => MediaAssetStatus::Failed]);

    expect(fn () => $uploads->retry($editor, $asset))->toThrow(ValidationException::class, 'too many uploads');
    expect($asset->fresh()->status)->toBe(MediaAssetStatus::Failed);
    Queue::assertPushed(NormalizeMediaAssetJob::class, 1);

    $this->travel(61)->seconds();
    expect($uploads->retry($editor, $asset)->status)->toBe(MediaAssetStatus::Processing);
    expect(fn () => $uploads->start($owner, $workspace, UploadedFile::fake()->image('blocked.png')))
        ->toThrow(ValidationException::class, 'too many uploads');
    $this->assertDatabaseCount('media_assets', 1);
});

it('bounds retained failed uploads without deleting sources or blocking their retry', function (): void {
    config(['media.asset_uploads.max_pending_assets' => 1]);
    [$owner, $workspace, $editor] = resourceLimitedMediaCompany();
    $uploads = app(MediaAssetUploadService::class);
    $asset = $uploads->start($owner, $workspace, UploadedFile::fake()->image('failed.png'));
    $asset->update(['status' => MediaAssetStatus::Failed]);

    expect(fn () => $uploads->start($editor, $workspace, UploadedFile::fake()->image('blocked.png')))
        ->toThrow(ValidationException::class, 'pending or failed uploads');
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    Storage::disk('local')->assertExists($asset->pending_path);
    $this->assertDatabaseCount('media_assets', 1);
    expect($uploads->retry($editor, $asset)->status)->toBe(MediaAssetStatus::Processing);

    $asset->update(['status' => MediaAssetStatus::Ready, 'pending_disk' => null, 'pending_path' => null]);
    $uploads->start($editor, $workspace, UploadedFile::fake()->image('new.png'));
    $this->assertDatabaseCount('media_assets', 2);
});

it('rejects an exhausted commercial media allowance before storing another source', function (): void {
    [$owner, $workspace] = resourceLimitedMediaCompany();
    $owner->entitlements()->firstOrFail()->plan->limits()->where('key', 'media_assets')->update(['value' => 0]);

    expect(fn () => app(MediaAssetUploadService::class)->start($owner, $workspace, UploadedFile::fake()->image('blocked.png')))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
    $this->assertDatabaseCount('media_assets', 0);
    Queue::assertNothingPushed();
});
