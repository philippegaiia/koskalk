<?php

use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores one audited guidance override per workspace and platform ingredient', function (): void {
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($creator, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
    ]);

    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_markdown' => '## Overview',
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    expect(Schema::hasColumns('workspace_ingredient_guidances', [
        'workspace_id',
        'ingredient_id',
        'guidance_markdown',
        'created_by_user_id',
        'updated_by_user_id',
    ]))->toBeTrue()
        ->and($override->workspace->is($workspace))->toBeTrue()
        ->and($override->ingredient->is($ingredient))->toBeTrue()
        ->and($override->creator->is($creator))->toBeTrue()
        ->and($override->updater->is($updater))->toBeTrue()
        ->and($workspace->ingredientGuidances->contains($override))->toBeTrue()
        ->and($ingredient->workspaceGuidances->contains($override))->toBeTrue();
});

it('rejects duplicate workspace and ingredient guidance overrides', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
    ]);

    expect(fn (): WorkspaceIngredientGuidance => WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
    ]))->toThrow(QueryException::class);
});

it('cascades guidance overrides when their workspace is deleted', function (): void {
    $workspace = Workspace::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    $workspace->delete();

    $this->assertModelMissing($override);
});

it('cascades guidance overrides when their ingredient is deleted', function (): void {
    $ingredient = Ingredient::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'ingredient_id' => $ingredient->id,
    ]);

    $ingredient->delete();

    $this->assertModelMissing($override);
});

it('nulls only the matching audit attribution when an audit user is deleted', function (): void {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $updater = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'created_by_user_id' => $creator->id,
        'updated_by_user_id' => $updater->id,
    ]);

    $creator->delete();

    expect($override->fresh()->created_by_user_id)->toBeNull()
        ->and($override->fresh()->updated_by_user_id)->toBe($updater->id);

    $updater->delete();

    expect($override->fresh()->created_by_user_id)->toBeNull()
        ->and($override->fresh()->updated_by_user_id)->toBeNull();
});
