<?php

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('limits recipe queries to the current tenant scope', function () {
    $productFamily = ProductFamily::factory()->create();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    WorkspaceMember::factory()->for($workspace)->for($otherUser)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $ownedRecipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => $productFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'workspace_id' => null,
        'visibility' => Visibility::Private,
        'name' => 'Owner Recipe',
        'slug' => 'owner-recipe',
    ]);

    $workspaceRecipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => $productFamily->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Workspace Recipe',
        'slug' => 'workspace-recipe',
    ]);

    $hiddenRecipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => $productFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'workspace_id' => null,
        'visibility' => Visibility::Private,
        'name' => 'Hidden Recipe',
        'slug' => 'hidden-recipe',
    ]);

    $this->actingAs($owner);

    expect(Recipe::query()->pluck('id')->all())
        ->toContain($ownedRecipe->id)
        ->toContain($workspaceRecipe->id)
        ->not->toContain($hiddenRecipe->id);
});

it('enforces workspace and recipe policies from ownership and membership', function () {
    $productFamily = ProductFamily::factory()->create();
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $recipe = Recipe::withoutGlobalScopes()->create([
        'product_family_id' => $productFamily->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'name' => 'Shared Formula',
        'slug' => 'shared-formula',
    ]);

    expect($owner->can('update', $workspace))->toBeTrue()
        ->and($editor->can('view', $workspace))->toBeTrue()
        ->and($viewer->can('update', $workspace))->toBeFalse()
        ->and($editor->can('update', $recipe))->toBeTrue()
        ->and($viewer->can('view', $recipe))->toBeTrue()
        ->and($viewer->can('update', $recipe))->toBeFalse()
        ->and($outsider->can('view', $recipe))->toBeFalse();
});
