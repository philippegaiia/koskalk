<?php

use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('private recipe is visible to all company members', function () {
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
        'is_private' => true,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('view', $recipe))->toBeTrue();
    expect($editor->can('view', $recipe))->toBeTrue();
});

test('private recipe can only be updated by its author', function () {
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
        'is_private' => true,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('update', $recipe))->toBeTrue();
    expect($editor->can('update', $recipe))->toBeFalse();
});

test('private recipe can only be deleted by its author', function () {
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
        'is_private' => true,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('delete', $recipe))->toBeTrue();
    expect($editor->can('delete', $recipe))->toBeFalse();
});

test('non-private recipe can be updated by editors in the company', function () {
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
        'is_private' => false,
        'created_by' => $owner->id,
    ]);

    expect($owner->can('update', $recipe))->toBeTrue();
    expect($editor->can('update', $recipe))->toBeTrue();
});

test('private recipe author who is not the company owner can still edit their own recipe', function () {
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
        'is_private' => true,
        'created_by' => $editor->id,
    ]);

    expect($editor->can('update', $recipe))->toBeTrue();
    expect($owner->can('update', $recipe))->toBeFalse();
});
