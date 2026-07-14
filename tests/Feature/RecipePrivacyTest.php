<?php

use App\Livewire\Dashboard\RecipesIndex;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\RecipeWorkbenchService;
use App\WorkspaceMemberRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('workspace formulas are visible only to the workspace owner during the MVP', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $editor = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('view', $recipe))->toBeTrue();
    expect($editor->can('view', $recipe))->toBeFalse();
});

test('workspace formulas can only be updated by the workspace owner during the MVP', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $editor = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('update', $recipe))->toBeTrue();
    expect($editor->can('update', $recipe))->toBeFalse();
});

test('workspace formulas can only be deleted by the workspace owner during the MVP', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $editor = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('delete', $recipe))->toBeTrue();
    expect($editor->can('delete', $recipe))->toBeFalse();
});

test('the obsolete privacy flag does not grant workspace editors access', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $editor = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('update', $recipe))->toBeTrue();
    expect($editor->can('update', $recipe))->toBeFalse();
});

test('created_by records authorship and does not grant authorization', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $editor = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $editor->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $editor->id,
    ]);

    expect($editor->can('update', $recipe))->toBeFalse();
    expect($owner->can('update', $recipe))->toBeTrue();
});

test('a crafted Livewire request cannot save or publish another workspace formula', function () {
    $owner = User::factory()->create();
    $company = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $owner->id,
        'role' => WorkspaceMemberRole::Owner->value,
    ]);

    $member = User::factory()->create();
    WorkspaceMember::factory()->create([
        'workspace_id' => $company->id,
        'user_id' => $member->id,
        'role' => WorkspaceMemberRole::Editor->value,
    ]);

    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($member);

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('save', [])
        ->assertNotFound();

    Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->call('publish', [])
        ->assertNotFound();
});

test('workspace members cannot discover owner formula names in listings', function (WorkspaceMemberRole $role) {
    $owner = User::factory()->create();
    $company = Workspace::factory()->for($owner, 'owner')->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($company)->for($member)->create([
        'role' => $role,
    ]);
    Recipe::factory()->create([
        'workspace_id' => $company->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $company->id,
        'name' => 'Owner Confidential Formula',
    ]);

    $this->actingAs($member);

    expect(Recipe::query()->exists())->toBeFalse();

    Livewire::test(RecipesIndex::class)
        ->assertDontSee('Owner Confidential Formula');
})->with([
    WorkspaceMemberRole::Viewer,
    WorkspaceMemberRole::Editor,
    WorkspaceMemberRole::Admin,
]);

test('workspace members cannot delete an owner formula version by public id', function (WorkspaceMemberRole $role) {
    $owner = User::factory()->create();
    $company = Workspace::factory()->for($owner, 'owner')->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($company)->for($member)->create([
        'role' => $role,
    ]);
    $recipe = Recipe::factory()->create([
        'workspace_id' => $company->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $company->id,
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'workspace_id' => $company->id,
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $company->id,
        'is_current' => false,
        'name' => 'Confidential Version',
    ]);

    $this->actingAs($member)
        ->delete(route('recipes.versions.destroy', [$recipe, $version]), [
            'confirm_name' => $version->name,
        ])
        ->assertForbidden();

    expect($version->fresh())->not->toBeNull();
})->with([
    WorkspaceMemberRole::Viewer,
    WorkspaceMemberRole::Editor,
    WorkspaceMemberRole::Admin,
]);

test('formula mutation services reject non-owners without relying on the caller', function (string $operation) {
    $owner = User::factory()->create();
    $company = Workspace::factory()->for($owner, 'owner')->create();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($company)->for($member)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $productFamily = ProductFamily::factory()->create();
    $recipe = Recipe::factory()->for($productFamily)->create([
        'workspace_id' => $company->id,
        'created_by' => $owner->id,
    ]);
    $service = app(RecipeWorkbenchService::class);

    expect(fn () => match ($operation) {
        'save' => $service->save($member, $productFamily, [], $recipe),
        'publish' => $service->publish($member, $productFamily, [], $recipe),
        'duplicate' => $service->duplicateRecipe($member, $recipe),
        'restore' => $service->restorePublishedFormula($member, $recipe, 1),
        'costing' => $service->saveCosting($member, $recipe, []),
    })->toThrow(AuthorizationException::class);
})->with(['save', 'publish', 'duplicate', 'restore', 'costing']);
