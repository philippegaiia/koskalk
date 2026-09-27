<?php

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\RecipeEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires the displayed revision and invalidates an open editing session when locking', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::factory()->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id]);
    app(RecipeEditingService::class)->acquire($recipe, $owner, (string) Str::uuid());

    $this->actingAs($owner)->post(route('recipes.lock', $recipe))->assertSessionHasErrors('expected_revision');
    expect($recipe->fresh()->isLocked())->toBeFalse();

    $this->post(route('recipes.lock', $recipe), ['expected_revision' => 0])->assertSessionHasNoErrors();
    expect($recipe->fresh()->isLocked())->toBeTrue()
        ->and((int) $recipe->fresh()->edit_revision)->toBe(1)
        ->and(DB::table('recipe_edit_leases')->where('recipe_id', $recipe->id)->exists())->toBeFalse();

    $this->post(route('recipes.unlock', $recipe), ['expected_revision' => 0])->assertSessionHasErrors('expected_revision');
    expect($recipe->fresh()->isLocked())->toBeTrue();
    $this->post(route('recipes.unlock', $recipe), ['expected_revision' => 1])->assertSessionHasNoErrors();
    expect($recipe->fresh()->isLocked())->toBeFalse();
});

it('rejects non-integer and negative expected revisions before changing the lock', function (mixed $expectedRevision): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($owner)
        ->post(route('recipes.lock', $recipe), ['expected_revision' => $expectedRevision])
        ->assertSessionHasErrors('expected_revision');

    expect($recipe->fresh()->isLocked())->toBeFalse();
})->with([
    'non-integer' => 'not-a-revision',
    'negative' => -1,
]);

it('does not archive a formula while its editing reservation is active', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $recipe = Recipe::factory()->create(['owner_type' => OwnerType::Workspace, 'owner_id' => $workspace->id, 'workspace_id' => $workspace->id]);
    $token = (string) Str::uuid();
    app(RecipeEditingService::class)->acquire($recipe, $owner, $token);
    $this->actingAs($owner)->post(route('recipes.archive', $recipe), ['expected_revision' => 0])->assertSessionHasErrors('expected_revision');
    expect($recipe->fresh()->archived_at)->toBeNull();
    app(RecipeEditingService::class)->release($recipe, $owner, $token);
    $this->post(route('recipes.archive', $recipe), ['expected_revision' => 0])->assertSessionHasNoErrors();
    expect($recipe->fresh()->archived_at)->not->toBeNull();
});

it('checks recipe authorization and backup state before validating the expected revision', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $workspace->id])->save();

    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($editor)
        ->post(route('recipes.lock', $recipe))
        ->assertForbidden();

    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create();
    $foreignRecipe = Recipe::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
    ]);

    $this->post(route('recipes.lock', $foreignRecipe))->assertNotFound();

    $currentVersion = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'is_current' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('recipes.saved.restore', ['recipe' => $recipe, 'version' => $currentVersion]))
        ->assertNotFound();
});
