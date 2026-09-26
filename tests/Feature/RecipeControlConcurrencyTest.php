<?php

use App\Enums\OwnerType;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
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
