<?php

use App\Enums\IngredientCategory;
use App\Enums\MassDisplaySystem;
use App\Enums\MaterialPriceSource;
use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\Dashboard\IngredientsIndex;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\Plan;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentMaterialPriceService;
use App\Services\IngredientFormulaUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('uses category fallback icons for missing images while preserving real ingredient images', function (): void {
    $user = User::factory()->create();
    $platformIngredient = Ingredient::factory()->create([
        'display_name' => 'Platform Lipid',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $ownedIngredient = Ingredient::factory()->create([
        'display_name' => 'Owned Ingredient',
        'category' => IngredientCategory::Other,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);
    $iconPath = 'ingredients/'.$ownedIngredient->public_id.'/icons/owned.webp';
    $ownedIngredient->update(['icon_image_path' => $iconPath]);

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee(IngredientCategory::Lipids->fallbackIconUrl(), false)
        ->assertSee(route('ingredients.media', ['ingredient' => $ownedIngredient, 'path' => $iconPath]), false)
        ->assertDontSee(IngredientCategory::Other->fallbackIconUrl(), false);
});

/**
 * @return array{destination_workspace_id: int, destination_workspace_signature: string}
 */
function signedPriceDestinationPayload(User $user, Workspace $workspace): array
{
    return [
        'destination_workspace_id' => $workspace->id,
        'destination_workspace_signature' => hash_hmac(
            'sha256',
            (string) $user->id.'|'.$workspace->id,
            (string) config('app.key'),
        ),
    ];
}

it('shows platform ingredients whether or not the user has priced them', function () {
    $user = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $coconut = Ingredient::factory()->create([
        'display_name' => 'Coconut Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($user, $olive, '5.25', 'EUR');

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Manage ingredients for formulas and costing.')
        ->assertSee('Ingredient catalog')
        ->assertSee('All')
        ->assertSeeHtml('aria-label="Ingredient catalog filters"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary justify-center"')
        ->assertDontSeeHtml('fi-ta')
        ->assertSeeHtml('role="radiogroup"')
        ->assertSeeHtml('aria-checked="true"')
        ->assertSee('Your price / kg (EUR)')
        ->assertSee('5.25')
        ->assertDontSee('5.2500')
        ->assertSee('Olive Oil')
        ->assertSee('Coconut Oil')
        ->assertSee('Soapkraft');
});

it('shows the ingredient price column in the users current default currency', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Your price / kg (GBP)');
});

it('displays and edits ingredient prices per pound for a US customary workspace', function (): void {
    $user = User::factory()->create(['number_locale' => 'en_US']);
    Workspace::factory()->for($user, 'owner')->create([
        'default_currency' => 'USD',
        'mass_display_system' => MassDisplaySystem::UsCustomary,
    ]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($user, $ingredient, '11.0231', 'USD');

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSet('currentPriceUnit', 'lb')
        ->assertSee('Your price / lb (USD)')
        ->assertSeeHtml('value="5.00"')
        ->call('updateIngredientPrice', $ingredient->id, '6.00');

    expect(bcmul(CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('price_per_canonical_unit'), '1000', 4))->toBe('13.2277');
});

it('lets a workspace unset its price for a platform ingredient without affecting another workspace', function (): void {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstWorkspace = Workspace::factory()->for($firstOwner, 'owner')->create();
    $secondWorkspace = Workspace::factory()->for($secondOwner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($firstOwner, $ingredient, '5.25', 'EUR');
    rememberIngredientPriceForWorkspace($secondOwner, $ingredient, '7.50', 'EUR');

    actingAs($firstOwner);

    Livewire::test(IngredientsIndex::class)
        ->call('updateIngredientPrice', $ingredient->id, '')
        ->assertHasNoErrors('price_'.$ingredient->id);

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $firstWorkspace->id)
        ->where('ingredient_id', $ingredient->id)
        ->exists())->toBeFalse()
        ->and(CurrentMaterialPrice::query()
            ->where('workspace_id', $secondWorkspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_canonical_unit'))->toBe('0.007500000000');
});

it('marks live costing rows as unpriced when the workspace price is unset', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $recipeVersion = RecipeVersion::factory()->create(['workspace_id' => $workspace->id]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'user_id' => $owner->id,
        'currency' => 'EUR',
    ]);
    $costingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
        'price_per_kg' => '5.2500',
    ]);

    rememberIngredientPriceForWorkspace($owner, $ingredient, '5.25', 'EUR');

    actingAs($owner);

    Livewire::test(IngredientsIndex::class)
        ->call('updateIngredientPrice', $ingredient->id, '')
        ->assertHasNoErrors('price_'.$ingredient->id);

    expect($costingItem->fresh()->price_per_kg)->toBeNull();
});

it('does not change live costing rows when the workspace had no current price to unset', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);
    $recipeVersion = RecipeVersion::factory()->create(['workspace_id' => $workspace->id]);
    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'user_id' => $owner->id,
        'currency' => 'EUR',
    ]);
    $costingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
        'price_per_kg' => '5.2500',
    ]);

    actingAs($owner);

    Livewire::test(IngredientsIndex::class)
        ->call('updateIngredientPrice', $ingredient->id, '')
        ->assertHasNoErrors('price_'.$ingredient->id);

    expect($costingItem->fresh()->price_per_kg)->toBe('5.2500');
});

it('renders inline ingredient prices with the users saved English number format', function () {
    $user = User::factory()->create(['number_locale' => 'en_GB']);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Priced oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($user, $ingredient, '0.10', 'EUR');

    $this->actingAs($user)
        ->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSeeHtml('type="text"')
        ->assertSeeHtml('inputmode="decimal"')
        ->assertSeeHtml('value="0.10"');
});

it('does not show inactive platform ingredients in the unified table', function () {
    $user = User::factory()->create();

    $active = Ingredient::factory()->create([
        'display_name' => 'Active Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $inactive = Ingredient::factory()->create([
        'display_name' => 'Inactive Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => false,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee($active->display_name)
        ->assertDontSee($inactive->display_name);
});

it('can filter the unified ingredient table by platform catalog records', function () {
    $user = User::factory()->create();

    $platform = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $mine = Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('setOwnershipFilter', 'platform')
        ->assertSee($platform->display_name)
        ->assertDontSee($mine->display_name);
});

it('shows user-owned ingredients in the unified table', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Other User Oil',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('My Lavender')
        ->assertSeeHtml('aria-label="Ingredient created or modified by you"')
        ->assertSee('This ingredient has not been verified by Soapkraft.')
        ->assertDontSee('Other User Oil');
});

it('shows private ingredient usage in the mine filter and plan allowance', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 20)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()->create([
        'display_name' => 'My Limited Ingredient',
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('Your ingredients (1)')
        ->assertSee('1 of 20 private ingredients');
});

it('singularizes the finite private ingredient allowance from the plan limit', function () {
    $user = User::factory()->create();
    $plan = Plan::factory()
        ->hasLimit('private_ingredients', 1)
        ->create();

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->assertSee('1 of 1 private ingredient')
        ->assertDontSee('1 of 1 private ingredients');
});

it('looks up formula usage for only private ingredients on the current page', function () {
    $user = User::factory()->create();
    $ownedIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);
    Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    mock(IngredientFormulaUsageService::class, function ($mock) use ($user, $ownedIngredient): void {
        $mock->shouldReceive('forIngredients')
            ->once()
            ->withArgs(fn (User $resolvedUser, Collection $ingredients): bool => $resolvedUser->is($user)
                && $ingredients->modelKeys() === [$ownedIngredient->id])
            ->andReturn([]);
    });

    actingAs($user);

    Livewire::test(IngredientsIndex::class);
});

it('updates a user ingredient price via the price endpoint', function () {
    $user = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($user, $olive, '5.25', 'EUR');
    $workspace = Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->firstOrFail();

    actingAs($user);

    $response = $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
        ...signedPriceDestinationPayload($user, $workspace),
    ]);

    $response->assertSuccessful();

    $price = CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $olive->id)
        ->first();

    expect((float) bcmul($price->price_per_canonical_unit, '1000', 12))->toBe(6.5);
    expect($price->recorded_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('does not allow pricing another users private ingredient through the endpoint', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Other User Lavender',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $otherUser->id,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => '6.5000',
        ...signedPriceDestinationPayload($user, $workspace),
    ])->assertNotFound();

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $ingredient->id)
        ->exists())->toBeFalse();
});

it('uses the users default currency when creating a price via the price endpoint', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'USD',
    ]);

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
        ...signedPriceDestinationPayload($user, $workspace),
    ])->assertSuccessful();

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $olive->id)
        ->value('currency'))->toBe('USD');
});

it('uses the workspace currency when updating via the price endpoint', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'USD',
    ]);

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    rememberIngredientPriceForWorkspace($user, $olive, '5.25', 'CHF');

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
        ...signedPriceDestinationPayload($user, $workspace),
    ])->assertSuccessful();

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $olive->id)
        ->value('currency'))->toBe('USD');
});

it('rejects a stale captured price destination without changing either workspace', function (): void {
    $user = User::factory()->create();
    $workspaceA = Workspace::factory()->for($user, 'owner')->create();
    $workspaceB = Workspace::factory()->create();
    WorkspaceMember::factory()->for($workspaceB)->for($user)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Stale price ingredient',
        'category' => IngredientCategory::Lipids,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);

    app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspaceA,
        ingredient: $ingredient,
        pricePerMassUnit: '5.25',
        massUnit: 'kg',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $user,
    );
    app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspaceB,
        ingredient: $ingredient,
        pricePerMassUnit: '7.50',
        massUnit: 'kg',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $user,
    );

    $recipeVersionA = RecipeVersion::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspaceA->id,
        'workspace_id' => $workspaceA->id,
    ]);
    $costingA = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersionA->id,
        'user_id' => $user->id,
        'currency' => 'EUR',
    ]);
    $costingItemA = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costingA->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
        'price_per_kg' => '5.2500',
    ]);
    $recipeVersionB = RecipeVersion::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspaceB->id,
        'workspace_id' => $workspaceB->id,
    ]);
    $costingB = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersionB->id,
        'user_id' => $user->id,
        'currency' => 'EUR',
    ]);
    $costingItemB = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costingB->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'main',
        'position' => 1,
        'price_per_kg' => '7.5000',
    ]);

    $user->forceFill(['active_workspace_id' => $workspaceA->id])->save();
    $user->forgetAccessibleWorkspaceIds();
    actingAs($user);
    $signature = Livewire::test(IngredientsIndex::class)
        ->instance()
        ->duplicateDestinationSignature();

    $user->forceFill(['active_workspace_id' => $workspaceB->id])->save();
    $user->forgetAccessibleWorkspaceIds();

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => '99.0000',
        'destination_workspace_id' => $workspaceA->id,
        'destination_workspace_signature' => $signature,
    ])->assertNotFound();

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $workspaceA->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('price_per_canonical_unit'))->toBe('0.005250000000')
        ->and(CurrentMaterialPrice::query()
            ->where('workspace_id', $workspaceB->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('price_per_canonical_unit'))->toBe('0.007500000000')
        ->and($costingItemA->fresh()->price_per_kg)->toBe('5.2500')
        ->and($costingItemB->fresh()->price_per_kg)->toBe('7.5000');
});

it('rejects a price update without a captured destination', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'owner_type' => null,
        'owner_id' => null,
        'workspace_id' => null,
        'is_active' => true,
    ]);
    $initialPrice = app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: '5.25',
        massUnit: 'kg',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $user,
    );

    actingAs($user);

    $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $ingredient->id,
        'price_per_kg' => '99.0000',
    ])->assertNotFound();

    expect(CurrentMaterialPrice::query()->whereKey($initialPrice->id)->value('price_per_canonical_unit'))
        ->toBe('0.005250000000');
});

it('uses the users default currency when creating a price from the ingredient table', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'My Lavender',
        'category' => IngredientCategory::AromaticMaterials,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'is_active' => true,
    ]);

    actingAs($user);

    Livewire::test(IngredientsIndex::class)
        ->call('updateIngredientPrice', $ingredient->id, '7.2500');

    expect(CurrentMaterialPrice::query()
        ->where('workspace_id', $user->company()?->id)
        ->where('ingredient_id', $ingredient->id)
        ->value('currency'))->toBe('GBP');
});
