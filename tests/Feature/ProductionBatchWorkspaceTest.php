<?php

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function batchWorkspaceFixture(WorkspaceMemberRole $role = WorkspaceMemberRole::Editor): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->create(['user_id' => $owner->id, 'plan_id' => $plan->id]);
    $member = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => $role]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $version = RecipeVersion::factory()->create(['recipe_id' => $recipe->id, 'workspace_id' => $workspace->id, 'owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id]);
    $batch = ProductionBatch::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id, 'recipe_id' => $recipe->id, 'recipe_version_id' => $version->id]);

    return compact('owner', 'workspace', 'plan', 'member', 'recipe', 'version', 'batch');
}

it('uses company authority rather than batch authorship', function (WorkspaceMemberRole $role, bool $edit, bool $delete): void {
    extract(batchWorkspaceFixture($role));
    expect(Gate::forUser($member)->allows('view', $batch))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $batch))->toBe($edit)
        ->and(Gate::forUser($member)->allows('delete', $batch))->toBe($delete)
        ->and(Gate::forUser($owner)->allows('delete', $batch))->toBeTrue();

    WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', $member->id)->delete();
    expect(Gate::forUser($member)->allows('view', $batch))->toBeFalse();
})->with([
    'admin' => [WorkspaceMemberRole::Admin, true, true],
    'editor' => [WorkspaceMemberRole::Editor, true, false],
    'viewer' => [WorkspaceMemberRole::Viewer, false, false],
]);

it('allows viewer printing but refuses annotation and deletion endpoints', function (): void {
    extract(batchWorkspaceFixture(WorkspaceMemberRole::Viewer));
    $this->actingAs($member)->get(route('production-batches.show', $batch))->assertOk()->assertDontSee('Save notes');
    $this->get(route('production-batches.print', $batch))->assertOk();
    $this->patch(route('production-batches.update', $batch), ['production_notes' => 'Forbidden'])->assertForbidden();
    $this->delete(route('production-batches.destroy', $batch))->assertForbidden();
    expect($batch->fresh()->production_notes)->toBeNull();
});

it('preserves shared snapshots and usage after the author account is deleted', function (): void {
    extract(batchWorkspaceFixture());
    PlanLimit::factory()->create(['plan_id' => $plan->id, 'key' => 'production_batches', 'value' => 1]);
    $member->delete();
    $recipe->delete();
    expect($batch->fresh()->user_id)->toBeNull()
        ->and($batch->fresh()->workspace_id)->toBe($workspace->id)
        ->and(app(EntitlementService::class)->usageFor($owner)['production_batches']['used'])->toBe(1)
        ->and(app(EntitlementService::class)->canCreateProductionBatch($owner))->toBeFalse();
});

it('shares batch quota without counting member personal or other company history', function (): void {
    extract(batchWorkspaceFixture());
    ProductionBatch::factory()->create(['user_id' => $member->id, 'recipe_id' => null, 'recipe_version_id' => null]);
    PlanLimit::factory()->create(['plan_id' => $plan->id, 'key' => 'production_batches', 'value' => 1]);
    expect(app(EntitlementService::class)->usageFor($member)['production_batches']['used'])->toBe(1)
        ->and(app(EntitlementService::class)->canCreateProductionBatch($member))->toBeFalse();
});

it('previews provenance without writes and backfills only unambiguous surviving sources', function (): void {
    extract(batchWorkspaceFixture());
    $batch->update(['workspace_id' => null]);
    $orphan = ProductionBatch::factory()->create(['user_id' => $member->id, 'recipe_id' => null, 'recipe_version_id' => null]);
    $conflict = ProductionBatch::factory()->create(['user_id' => $member->id, 'recipe_id' => $recipe->id]);
    $before = $batch->fresh()->getAttributes();
    $this->artisan('production-batches:backfill-workspaces')->expectsOutputToContain('Would assign: 1; unresolved (unchanged): 2.')->assertSuccessful();
    expect($batch->fresh()->getAttributes())->toBe($before);
    $this->artisan('production-batches:backfill-workspaces --apply')->assertSuccessful();
    expect($batch->fresh()->workspace_id)->toBe($workspace->id)
        ->and($batch->fresh()->getAttributes())->toBe([...$before, 'workspace_id' => $workspace->id])
        ->and($orphan->fresh()->workspace_id)->toBeNull()
        ->and($conflict->fresh()->workspace_id)->toBeNull();
    $this->artisan('production-batches:backfill-workspaces --apply')->expectsOutputToContain('Assigned: 0; unresolved (unchanged): 2.')->assertSuccessful();
});

it('rejects an old shared batch URL after a company switch even for its author', function (): void {
    extract(batchWorkspaceFixture());
    $other = Workspace::factory()->create(['owner_user_id' => $member->id]);
    $member->forceFill(['active_workspace_id' => $other->id])->save();
    $this->actingAs($member)->get(route('production-batches.print', $batch))->assertForbidden();
    $this->patch(route('production-batches.update', $batch), ['production_notes' => 'Wrong company'])->assertForbidden();
});

it('preserves populated batch headers and children through a PostgreSQL migration round trip', function (): void {
    extract(batchWorkspaceFixture());
    $batch->ingredients()->create(['ingredient_name' => 'Frozen oil', 'phase_key' => 'oil', 'phase_name' => 'Oil', 'position' => 0, 'percentage' => 100, 'quantity' => 1000, 'unit' => 'g', 'price_per_kg' => 5, 'line_cost' => 5]);
    $before = $batch->fresh()->getAttributes();
    unset($before['workspace_id']);
    $children = $batch->ingredients()->get()->toArray();
    $migration = require database_path('migrations/2026_09_27_012635_add_workspace_provenance_to_production_batches.php');
    $migration->down();
    expect($batch->fresh()->getAttributes())->toBe($before)
        ->and($batch->ingredients()->get()->toArray())->toBe($children);
    $migration->up();
    expect($batch->fresh()->getAttributes())->toBe([...$before, 'workspace_id' => null])
        ->and($batch->ingredients()->get()->toArray())->toBe($children);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Schema round trip runs on disposable PostgreSQL; SQLite rebuilds cannot safely run inside RefreshDatabase transactions.');
