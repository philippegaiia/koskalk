<?php

use App\Enums\MediaAssetStatus;
use App\Enums\MediaAssetType;
use App\Enums\MediaAssetUsageRole;
use App\Enums\WorkspaceMemberRole;
use App\Jobs\NormalizeMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\MediaAssetUsage;
use App\Models\MediaLabel;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use App\Services\MediaLabelService;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('keeps pending originals local while final media can use private R2', function () {
    expect(config('media.asset_pending_disk'))->toBe('local');

    expect(config('filesystems.disks.r2_private.http'))->toMatchArray([
        'connect_timeout' => 5,
        'timeout' => 45,
    ]);

    expect(config('filesystems.disks.r2_private.retries'))->toBe(0);
});

it('gives media processing enough time for final R2 conversions', function () {
    $job = new NormalizeMediaAssetJob(1, 'processing-token');

    expect($job->timeout)->toBe(300)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

it('runs the development worker against the media queue before the default queue', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $developmentCommand = implode(' ', $composer['scripts']['dev']);

    expect($developmentCommand)
        ->toContain('--queue=media,default')
        ->toContain('--timeout=0');
});

it('creates workspace media assets with an opaque public id and processing state', function () {
    $workspace = Workspace::factory()->create();
    $uploader = User::factory()->create();

    $asset = MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'uploaded_by_user_id' => $uploader->id,
        'original_filename' => 'Olive bottle portrait.HEIC',
    ]);

    expect($asset->public_id)->toBeUuid()
        ->and($asset->status)->toBe(MediaAssetStatus::Processing)
        ->and($asset->type)->toBe(MediaAssetType::Image)
        ->and($asset->workspace->is($workspace))->toBeTrue()
        ->and($asset->uploadedBy->is($uploader))->toBeTrue()
        ->and($asset->getRouteKeyName())->toBe('public_id');
});

it('stores pdf assets with an explicit media type', function () {
    $asset = MediaAsset::factory()->pdf()->create();

    expect($asset->type)->toBe(MediaAssetType::Pdf)
        ->and($asset->original_mime_type)->toBe('application/pdf')
        ->and($asset->original_filename)->toEndWith('.pdf');
});

it('attaches workspace labels to media assets without duplicate normalized names', function () {
    $workspace = Workspace::factory()->create();
    $creator = User::factory()->create();
    $asset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
    $label = MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $creator->id,
        'name' => 'Certificates',
        'normalized_name' => 'certificates',
    ]);

    $asset->labels()->attach($label);

    expect($asset->fresh()->labels)->toHaveCount(1)
        ->and($label->fresh()->mediaAssets->sole()->is($asset))->toBeTrue()
        ->and($workspace->fresh()->mediaLabels->sole()->is($label))->toBeTrue()
        ->and($creator->fresh()->createdMediaLabels->sole()->is($label))->toBeTrue();

    expect(fn () => MediaLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'normalized_name' => 'certificates',
    ]))->toThrow(QueryException::class);

    $label->delete();

    expect($asset->fresh()->labels)->toBeEmpty();
});

it('records reusable polymorphic usages with a role and prevents duplicate references', function () {
    $workspace = Workspace::factory()->create();
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);

    $usage = MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Recipe::class,
        'usable_id' => $recipe->id,
        'role' => MediaAssetUsageRole::RecipeFeatured,
    ]);

    expect($usage->usable->is($recipe))->toBeTrue()
        ->and($usage->mediaAsset->is($asset))->toBeTrue()
        ->and($usage->role)->toBe(MediaAssetUsageRole::RecipeFeatured)
        ->and($asset->fresh()->usages)->toHaveCount(1);

    expect(fn () => MediaAssetUsage::factory()->create([
        'media_asset_id' => $asset->id,
        'usable_type' => Recipe::class,
        'usable_id' => $recipe->id,
        'role' => MediaAssetUsageRole::RecipeFeatured,
    ]))->toThrow(QueryException::class);
});

it('counts processing and ready assets provisionally while failed assets release quota', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $plan = Plan::factory()
        ->hasLimit('media_assets', 2)
        ->create(['is_default' => true]);

    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    MediaAsset::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => MediaAssetStatus::Processing,
    ]);
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    MediaAsset::factory()->failed()->create(['workspace_id' => $workspace->id]);

    $usage = app(EntitlementService::class)->mediaAssetUsageFor($owner);

    expect($usage)->toMatchArray([
        'used' => 2,
        'limit' => 2,
        'remaining' => 0,
        'allowed' => false,
    ]);

    expect(fn () => app(EntitlementService::class)->assertCanUploadMediaAssetInWorkspace($workspace))
        ->toThrow(ValidationException::class, '2 media assets');
});

it('allows existing assets to remain accessible when a reduced plan blocks new uploads', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $plan = Plan::factory()
        ->hasLimit('media_assets', 1)
        ->create(['is_default' => true]);

    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $existingAsset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    $usage = app(EntitlementService::class)->mediaAssetUsageFor($owner);

    expect($usage)->toMatchArray([
        'used' => 2,
        'limit' => 1,
        'remaining' => 0,
        'allowed' => false,
    ])
        ->and(Gate::forUser($owner)->allows('view', $existingAsset))->toBeTrue();
});

it('authorizes workspace members by role', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);

    foreach ([
        $admin->id => WorkspaceMemberRole::Admin,
        $editor->id => WorkspaceMemberRole::Editor,
        $viewer->id => WorkspaceMemberRole::Viewer,
    ] as $userId => $role) {
        WorkspaceMember::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    expect(Gate::forUser($owner)->allows('view', $asset))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $asset))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('view', $asset))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $asset))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $asset))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $asset))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $asset))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('update', $asset))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $asset))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('delete', $asset))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $asset))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $asset))->toBeFalse();
});

it('seeds a 200 asset limit for the free plan', function () {
    $this->seed(PlanSeeder::class);

    $plan = Plan::query()
        ->where('slug', 'free-beta')
        ->with('limits')
        ->firstOrFail();

    expect($plan->limits->pluck('value', 'key'))
        ->get('media_assets')->toBe(200)
        ->get('media_labels')->toBe(20);
});

it('lets workspace editors create and assign bounded labels', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $plan = Plan::factory()->hasLimit('media_labels', 20)->create();
    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $service = app(MediaLabelService::class);

    $label = $service->create($editor, $workspace, '  Safety   Data  ');
    $service->sync($editor, $asset, [$label->id]);

    expect($label->name)->toBe('Safety Data')
        ->and($label->normalized_name)->toBe('safety data')
        ->and($asset->fresh()->labels->sole()->is($label))->toBeTrue();

    expect(fn () => $service->create($editor, $workspace, 'SAFETY DATA'))
        ->toThrow(ValidationException::class);
});

it('prevents viewers and outsiders from mutating workspace labels', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $viewer->id,
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $label = MediaLabel::factory()->create(['workspace_id' => $workspace->id]);
    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $service = app(MediaLabelService::class);

    expect(fn () => $service->create($viewer, $workspace, 'COA'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $service->sync($outsider, $asset, [$label->id]))
        ->toThrow(AuthorizationException::class);
});

it('enforces workspace and per-asset label limits', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $plan = Plan::factory()->hasLimit('media_labels', 2)->create();
    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
    $service = app(MediaLabelService::class);

    $first = $service->create($owner, $workspace, 'One');
    $second = $service->create($owner, $workspace, 'Two');

    expect(fn () => $service->create($owner, $workspace, 'Three'))
        ->toThrow(ValidationException::class, '2 media labels');

    $asset = MediaAsset::factory()->ready()->create(['workspace_id' => $workspace->id]);
    $labels = MediaLabel::factory()->count(9)->create(['workspace_id' => $workspace->id]);

    expect(fn () => $service->sync($owner, $asset, $labels->pluck('id')->all()))
        ->toThrow(ValidationException::class, '8 labels')
        ->and($first->workspace->is($workspace))->toBeTrue()
        ->and($second->workspace->is($workspace))->toBeTrue();
});
