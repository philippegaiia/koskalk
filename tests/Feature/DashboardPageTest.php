<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the selected workspace role and offers product creation only to members who can create', function (WorkspaceMemberRole $role, bool $canCreate): void {
    config(['workspaces.collaboration_enabled' => true]);
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->for($workspace->owner)->for($plan)->create();
    $member = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($member)->create(['role' => $role]);

    $response = $this->actingAs($member)->get(route('dashboard'))->assertOk();
    $page = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $workspaceLink = $page->querySelector('a[href="'.route('workspace-selection.index').'"]');
    $creationLink = $page->querySelector('main a[href="'.route('recipes.start').'"]');

    expect($creationLink !== null)->toBe($canCreate);
    expect($workspaceLink?->textContent)->toContain(__('workspaces.roles.'.$role->value));
})->with([
    'viewer' => [WorkspaceMemberRole::Viewer, false],
    'editor' => [WorkspaceMemberRole::Editor, true],
]);

it('renders the simplified dashboard with creation buttons, stat cards, and user identity', function () {
    $user = User::factory()->create([
        'name' => 'Marie Maker',
        'email' => 'marie@example.com',
    ]);
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Dashboard Test Formula',
        'slug' => 'dashboard-test-formula',
        'locked_at' => now(),
        'locked_by' => $user->id,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'Dashboard Published Formula',
        'is_current' => false,
        'version_number' => 2,
        'saved_at' => now(),
    ]);
    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $recipe->name,
        'is_current' => true,
        'version_number' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Create a product')
        ->assertSee('New product')
        ->assertSee(route('recipes.start'))
        ->assertDontSee('New soap product')
        ->assertDontSee('New cosmetic product')
        ->assertSee('Your products')
        ->assertSee('Products')
        ->assertSee('Ingredients')
        ->assertSee('Marie Maker')
        ->assertDontSee('marie@example.com')
        ->assertSee('Free account')
        ->assertDontSee('mt-3 flex min-w-0 items-start gap-3', false)
        ->assertDontSee('max-w-44 truncate text-xs text-[var(--color-ink-soft)]', false)
        ->assertSee('Locked products')
        ->assertDontSee('Welcome to your formulation workspace.')
        ->assertDontSee('More modules coming soon.');
});

it('shows recipe and lock counts for the current user only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $userRecipe = Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => 'User Recipe',
        'slug' => 'user-recipe',
        'locked_at' => now(),
        'locked_by' => $user->id,
    ]);

    RecipeVersion::factory()->create([
        'recipe_id' => $userRecipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'name' => $userRecipe->name,
        'is_current' => true,
        'version_number' => 1,
    ]);

    Recipe::factory()->create([
        'product_family_id' => $soapFamily->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'name' => 'Other User Recipe',
        'slug' => 'other-user-recipe',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertSuccessful();

    $recipeCount = $response->viewData('recipeCount');
    $lockedFormulaCount = $response->viewData('lockedFormulaCount');

    expect($recipeCount)->toBe(1)
        ->and($lockedFormulaCount)->toBe(1);
});

it('shows the current users personal ingredient count on the dashboard', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Ingredient::factory()->create([
        'category' => IngredientCategory::MineralsSaltsPowders,
        'display_name' => 'French Green Clay',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
        'catalog_key' => 'USR-CLAY',
    ]);

    Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Propylene Glycol',
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'visibility' => Visibility::Private,
        'catalog_key' => 'USR-GLY',
    ]);

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    $response->assertSuccessful();

    $ingredientCount = $response->viewData('ingredientCount');
    expect($ingredientCount)->toBe(1);
});
