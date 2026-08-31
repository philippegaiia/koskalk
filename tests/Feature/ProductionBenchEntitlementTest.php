<?php

use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('activates production bench independently for a workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    $entitlement = app(ProductionBenchAccess::class)->activate($owner, $workspace);

    expect($entitlement->workspace_id)->toBe($workspace->id)
        ->and($entitlement->status)->toBe(ProductionBenchEntitlementStatus::Active)
        ->and($entitlement->activated_at)->not->toBeNull()
        ->and(app(ProductionBenchAccess::class)->isActive($workspace))->toBeTrue()
        ->and(app(ProductionBenchAccess::class)->isReadOnly($workspace))->toBeFalse();
});

it('cancels and resumes production bench without discarding its entitlement record', function (): void {
    Date::setTestNow('2026-07-28 10:00:00');

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $access = app(ProductionBenchAccess::class);
    $activated = $access->activate($owner, $workspace);

    $cancelled = $access->cancel($owner, $workspace);

    expect($cancelled->is($activated))->toBeTrue()
        ->and($cancelled->status)->toBe(ProductionBenchEntitlementStatus::Cancelled)
        ->and($cancelled->cancelled_at?->toDateTimeString())->toBe('2026-07-28 10:00:00')
        ->and($cancelled->archive_eligible_at?->toDateTimeString())->toBe('2030-07-28 10:00:00')
        ->and($access->isActive($workspace))->toBeFalse()
        ->and($access->isReadOnly($workspace))->toBeTrue();

    Date::setTestNow('2026-08-03 09:30:00');

    $resumed = $access->resume($owner, $workspace);

    expect($resumed->is($activated))->toBeTrue()
        ->and($resumed->status)->toBe(ProductionBenchEntitlementStatus::Active)
        ->and($resumed->activated_at?->toDateTimeString())->toBe('2026-08-03 09:30:00')
        ->and($resumed->cancelled_at)->toBeNull()
        ->and($resumed->archive_eligible_at)->toBeNull();
});

it('allows editors to manage production bench but rejects viewers', function (): void {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $access = app(ProductionBenchAccess::class);

    expect($access->activate($editor, $workspace)->status)
        ->toBe(ProductionBenchEntitlementStatus::Active);

    $access->cancel($editor, $workspace);
    $access->resume($viewer, $workspace);
})->throws(AuthorizationException::class);

it('exposes an actor-aware production bench write capability', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    WorkspaceMember::factory()->for($workspace)->for($admin)->create([
        'role' => WorkspaceMemberRole::Admin,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $access = app(ProductionBenchAccess::class);
    $access->activate($owner, $workspace);

    expect($access->canWrite($owner, $workspace))->toBeTrue()
        ->and($access->canWrite($admin, $workspace))->toBeTrue()
        ->and($access->canWrite($editor, $workspace))->toBeTrue()
        ->and($access->canWrite($viewer, $workspace))->toBeFalse()
        ->and($access->canWrite($outsider, $workspace))->toBeFalse();

    $access->cancel($owner, $workspace);

    expect($access->canWrite($owner, $workspace))->toBeFalse()
        ->and($access->canWrite($admin, $workspace))->toBeFalse()
        ->and($access->canWrite($editor, $workspace))->toBeFalse()
        ->and($access->canWrite($viewer, $workspace))->toBeFalse();
});

it('blocks production mutations while the add-on is cancelled', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $access = app(ProductionBenchAccess::class);

    $access->activate($owner, $workspace);
    $access->cancel($owner, $workspace);

    $access->assertWritable($owner, $workspace);
})->throws(ValidationException::class, 'Production Bench is read-only');

it('does not depend on plan limits or team size', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->hasLimit('production_batches', 0)->create([
        'is_default' => true,
    ]);

    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);
    WorkspaceMember::factory()->for($workspace)->for($member)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $entitlement = app(ProductionBenchAccess::class)->activate($member, $workspace);

    expect($entitlement->status)->toBe(ProductionBenchEntitlementStatus::Active)
        ->and(app(ProductionBenchAccess::class)->isActive($workspace))->toBeTrue();
});

it('allows every workspace role to read production bench data', function (WorkspaceMemberRole $role): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $actor = $role === WorkspaceMemberRole::Owner ? $owner : User::factory()->create();

    if ($role !== WorkspaceMemberRole::Owner) {
        WorkspaceMember::factory()->for($workspace)->for($actor)->create(['role' => $role]);
    }

    app(ProductionBenchAccess::class)->assertReadable($actor, $workspace);

    expect(true)->toBeTrue();
})->with(WorkspaceMemberRole::cases());

it('rejects production bench reads from outside the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $outsider = User::factory()->create();

    expect(fn () => app(ProductionBenchAccess::class)->assertReadable($outsider, $workspace))
        ->toThrow(AuthorizationException::class);
});
