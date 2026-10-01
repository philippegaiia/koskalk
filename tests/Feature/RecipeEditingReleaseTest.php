<?php

use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\Recipe;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\RecipeEditingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('releases the departing tab immediately so a waiting editor can resume without reloading', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id, 'edit_revision' => 4]);
    $editing = app(RecipeEditingService::class);
    $ownerToken = (string) Str::uuid();
    $editorToken = (string) Str::uuid();
    $state = $editing->acquire($recipe, $workspace->owner, $ownerToken);

    expect($editing->acquire($recipe, $editor, $editorToken)['status'])->toBe('blocked');

    $this->actingAs($workspace->owner)->postJson('/dashboard/recipes/'.$recipe->public_id.'/editing/release', ['token' => $ownerToken])->assertNoContent();

    expect($state['release_url'])->toBe(route('recipes.editing.release', $recipe->public_id))
        ->and($editing->status($recipe, $editor, $editorToken)['status'])->toBe('available')
        ->and($editing->acquire($recipe, $editor, $editorToken)['status'])->toBe('acquired')
        ->and($recipe->fresh()->edit_revision)->toBe(4);
});

it('ignores mismatched and delayed releases without removing another tab reservation', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $oldToken = (string) Str::uuid();
    $newToken = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $oldToken);
    $url = '/dashboard/recipes/'.$recipe->public_id.'/editing/release';
    $this->actingAs($workspace->owner)->postJson($url, ['token' => $newToken])->assertNoContent();
    expect($editing->status($recipe, $workspace->owner, $oldToken)['status'])->toBe('acquired');

    $this->postJson($url, ['token' => $oldToken])->assertNoContent();
    $this->postJson($url, ['token' => $oldToken])->assertNoContent();
    $editing->acquire($recipe, $workspace->owner, $newToken);
    $this->postJson($url, ['token' => $oldToken])->assertNoContent();
    expect($editing->status($recipe, $workspace->owner, $newToken)['status'])->toBe('acquired');
});

it('does not let another editor release the holder reservation even with its token', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $editor = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);

    $this->actingAs($editor)->postJson('/dashboard/recipes/'.$recipe->public_id.'/editing/release', ['token' => $token])->assertNoContent();

    expect($editing->status($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
});

it('rejects invalid release tokens and preserves the reservation', function (?string $submittedToken): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);

    $this->actingAs($workspace->owner)->postJson('/dashboard/recipes/'.$recipe->public_id.'/editing/release', ['token' => $submittedToken])->assertUnprocessable()->assertJsonValidationErrors('token');

    expect($editing->status($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
})->with([null, 'invalid']);

it('requires an authenticated user with access to the recipe workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);
    $url = '/dashboard/recipes/'.$recipe->public_id.'/editing/release';

    $this->postJson($url, ['token' => $token])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson($url, ['token' => $token])->assertNotFound();

    expect($editing->status($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
});

it('refuses a read-only workspace member releasing a reservation', function (): void {
    $workspace = Workspace::factory()->create();
    UserEntitlement::factory()->for($workspace->owner, 'user')->for(Plan::factory()->create(['allows_collaboration' => true]))->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);
    $recipe = Recipe::factory()->create(['workspace_id' => $workspace->id]);
    $editing = app(RecipeEditingService::class);
    $token = (string) Str::uuid();
    $editing->acquire($recipe, $workspace->owner, $token);

    $this->actingAs($viewer)->postJson('/dashboard/recipes/'.$recipe->public_id.'/editing/release', ['token' => $token])->assertForbidden();

    expect($editing->status($recipe, $workspace->owner, $token)['status'])->toBe('acquired');
});
