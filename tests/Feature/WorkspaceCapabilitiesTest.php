<?php

use App\Enums\ProductionBenchEntitlementStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\EntitlementService;
use App\Services\ProductionBenchAccess;
use App\Services\WorkspaceCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves capabilities from the explicit workspace owner entitlement rather than a member or default plan', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceMember::factory()->for($workspace)->for($member)->create();
    $enabledPlan = Plan::factory()->create([
        'is_default' => true,
        'allows_collaboration' => true,
        'allows_production_bench' => true,
    ]);
    UserEntitlement::factory()->for($member)->for($enabledPlan)->create();
    $capabilities = app(WorkspaceCapabilities::class);

    expect(app(EntitlementService::class)->planForWorkspace($workspace))->toBeNull()
        ->and($capabilities->allowsCollaboration($workspace))->toBeFalse()
        ->and($capabilities->canProvisionProductionBench($workspace))->toBeFalse();

    UserEntitlement::factory()->for($owner)->for($enabledPlan)->create();

    expect($capabilities->allowsCollaboration($workspace))->toBeTrue()
        ->and($capabilities->canProvisionProductionBench($workspace))->toBeTrue();
});

it('does not grant missing or disabled capabilities through unlimited limits', function (?bool $flag): void {
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->hasLimit('workspace_members', null)->create([
        'allows_collaboration' => $flag,
        'allows_production_bench' => $flag,
    ]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($plan)->create();
    $capabilities = app(WorkspaceCapabilities::class);

    expect($capabilities->allowsCollaboration($workspace))->toBeFalse()
        ->and($capabilities->canProvisionProductionBench($workspace))->toBeFalse()
        ->and($capabilities->provisionProductionBench($workspace))->toBeNull()
        ->and(WorkspaceProductionEntitlement::query()->count())->toBe(0);
})->with(['missing' => null, 'disabled' => false]);

it('ignores expired future and cancelled owner entitlements', function (array $attributes): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true, 'allows_production_bench' => true]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($plan)->create($attributes);

    expect(app(EntitlementService::class)->planForWorkspace($workspace))->toBeNull()
        ->and(app(WorkspaceCapabilities::class)->allowsCollaboration($workspace))->toBeFalse();
})->with([
    'expired' => fn (): array => ['ends_at' => now()->subDay()],
    'future' => fn (): array => ['starts_at' => now()->addDay()],
    'cancelled' => fn (): array => ['status' => 'cancelled'],
]);

it('uses the newest active explicit plan without falling back to an older feature grant', function (): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $enabledPlan = Plan::factory()->create(['allows_collaboration' => true]);
    $restrictedPlan = Plan::factory()->create(['allows_collaboration' => false]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($enabledPlan)->create(['starts_at' => now()->subDay()]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($restrictedPlan)->create();
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($enabledPlan)->create(['starts_at' => null]);

    expect(app(EntitlementService::class)->planForWorkspace($workspace)->id)->toBe($restrictedPlan->id)
        ->and(app(EntitlementService::class)->planFor($workspace->owner)->id)->toBe($restrictedPlan->id)
        ->and(app(WorkspaceCapabilities::class)->allowsCollaboration($workspace))->toBeFalse();
});

it('creates an eligible missing production bench grant once', function (): void {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_production_bench' => true]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($plan)->create();
    $capabilities = app(WorkspaceCapabilities::class);
    $grant = $capabilities->provisionProductionBench($workspace);

    expect($grant->status)->toBe(ProductionBenchEntitlementStatus::Active);
    $originalAttributes = $grant->refresh()->getAttributes();
    $this->travel(1)->day();

    expect($capabilities->provisionProductionBench($workspace)->getAttributes())->toBe($originalAttributes)
        ->and(WorkspaceProductionEntitlement::query()->count())->toBe(1);
});

it('preserves existing production bench authority regardless of plan eligibility', function (bool $cancelled): void {
    $workspace = Workspace::factory()->create();
    $factory = WorkspaceProductionEntitlement::factory()->for($workspace);
    $grant = ($cancelled ? $factory->cancelled() : $factory)->create();
    $originalAttributes = $grant->refresh()->getAttributes();

    expect(app(WorkspaceCapabilities::class)->provisionProductionBench($workspace)->getAttributes())->toBe($originalAttributes)
        ->and(app(ProductionBenchAccess::class)->isActive($workspace))->toBe(! $cancelled)
        ->and(app(ProductionBenchAccess::class)->isReadOnly($workspace))->toBe($cancelled);

    $plan = Plan::factory()->create(['allows_production_bench' => true]);
    UserEntitlement::factory()->for($workspace->owner, 'user')->for($plan)->create();

    expect(app(WorkspaceCapabilities::class)->provisionProductionBench($workspace)->getAttributes())->toBe($originalAttributes);
})->with(['active' => false, 'cancelled' => true]);
