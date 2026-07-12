<?php

use App\Livewire\Dashboard\PackagingItemEditor;
use App\Livewire\Dashboard\PackagingItemsIndex;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\UserPackagingItemAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('lets a signed-in user open the packaging items page and see saved items', function () {
    $user = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Tube 50 g',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => 'Reusable catalog item',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Packaging Items')
        ->assertSee('Manage packaging used in recipe costing.')
        ->assertSee('Packaging catalog')
        ->assertSeeHtml('aria-label="Packaging catalog filters"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary justify-center"')
        ->assertDontSeeHtml('fi-ta')
        ->assertSee('Unit price (EUR)')
        ->assertSee('0.12')
        ->assertDontSee('0.1200')
        ->assertSee('Tube 50 g');
});

it('shows packaging prices in the users current default currency', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Tube 50 g',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Unit price (GBP)')
        ->assertDontSee('EUR');

});

it('renders inline packaging prices with the users saved number format', function (string $numberLocale, string $formattedPrice) {
    $user = User::factory()->create(['number_locale' => $numberLocale]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Format test box',
        'unit_cost' => 0.1000,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSeeHtml('type="text"')
        ->assertSeeHtml('inputmode="decimal"')
        ->assertSeeHtml('value="'.$formattedPrice.'"');
})->with([
    'British English' => ['en_GB', '0.10'],
    'US English' => ['en_US', '0.10'],
    'French' => ['fr_FR', '0,10'],
]);

it('uses one bench-colored focus treatment for catalog search fields', function () {
    $css = file_get_contents(resource_path('css/shared/soapkraft.css'))
        .file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('input:not(.sk-field-control):not(.fi-input):focus-visible')
        ->toContain('border-color: var(--color-accent);')
        ->toContain('box-shadow: 0 0 0 1px var(--color-accent);')
        ->toContain('outline: none;')
        ->toContain('.sk-field:focus-within')
        ->toContain('background: var(--color-field);')
        ->not->toContain("button:focus-visible,\ninput:not(.sk-field-control):not(.fi-input):focus-visible")
        ->not->toContain('outline: 1px solid var(--color-field-outline);');
});

it('renders the packaging item create page for signed in users', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.create'))
        ->assertSuccessful()
        ->assertSee('Packaging image')
        ->assertSee('Effective unit price (GBP)');
});

it('does not allow editing another users packaging item', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other user box',
        'unit_cost' => 0.22,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.edit', $packagingItem->id))
        ->assertNotFound();
});

it('creates a packaging item from the dedicated editor', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    actingAs($user);

    Livewire::test(PackagingItemEditor::class)
        ->set('data.name', 'Kraft soap box')
        ->set('data.unit_cost', '0,4200')
        ->set('data.notes', '100g rectangle')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('packaging-items.edit', 1));

    expect(UserPackagingItem::query()->where('user_id', $user->id)->first())
        ->not->toBeNull()
        ->name->toBe('Kraft soap box')
        ->currency->toBe('GBP');
});

it('stores the packaging image path through the packaging authoring service', function () {
    $user = User::factory()->create();

    $packagingItem = app(UserPackagingItemAuthoringService::class)->create([
        'name' => 'Picture Box',
        'unit_cost' => 0.36,
        'notes' => 'With square image',
        'featured_image_path' => 'packaging/featured-images/picture-box.webp',
    ], $user);

    expect($packagingItem->featured_image_path)->toBe('packaging/featured-images/picture-box.webp');
});

it('only shows the signed-in users packaging items in the packaging table and supports searching', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.1000,
        'currency' => 'EUR',
        'notes' => 'First alpha entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.2000,
        'currency' => 'EUR',
        'notes' => 'Second alpha entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Beta Box',
        'unit_cost' => 0.3000,
        'currency' => 'EUR',
        'notes' => 'Beta entry',
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Alpha Box',
        'unit_cost' => 0.4000,
        'currency' => 'EUR',
        'notes' => 'Other user entry',
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->assertSee('Alpha Box')
        ->assertSee('Beta Box')
        ->assertDontSee('Other user entry')
        ->set('search', 'Beta')
        ->assertSee('Beta Box')
        ->assertDontSee('First alpha entry')
        ->assertDontSee('Second alpha entry');
});

it('updates a packaging item unit price from the catalog table', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '0.35');

    expect((float) $packagingItem->refresh()->unit_cost)->toBe(0.35);
});

it('updates the packaging item currency to the current default currency when editing inline', function () {
    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'default_currency' => 'GBP',
    ]);

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '0.35');

    expect($packagingItem->refresh()->currency)->toBe('GBP');
});

it('keeps the packaging catalog unit price required when editing inline', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Bottle label',
        'unit_cost' => 0.1200,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('updateUnitCost', $packagingItem->id, '')
        ->assertHasErrors(['unit_cost_'.$packagingItem->id]);

    expect((float) $packagingItem->refresh()->unit_cost)->toBe(0.12);
});

it('redirects signed-out visitors from the packaging items page', function () {
    $this->get(route('packaging-items.index'))
        ->assertRedirect(route('login'));
});

it('shows only the signed-in user packaging items on the page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Front sticker',
        'unit_cost' => 0.03,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    UserPackagingItem::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Hidden competitor box',
        'unit_cost' => 0.99,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $this->actingAs($user)
        ->get(route('packaging-items.index'))
        ->assertSuccessful()
        ->assertSee('Front sticker')
        ->assertDontSee('Hidden competitor box');
});

it('allows deleting an unused packaging item from the catalog table', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Delete me',
        'unit_cost' => 0.12,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->call('deletePackagingItem', $packagingItem->id);

    expect(UserPackagingItem::query()->find($packagingItem->id))->toBeNull();
});

it('disables deleting a packaging item that is already used in costing', function () {
    $user = User::factory()->create();

    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Locked Box',
        'unit_cost' => 0.55,
        'currency' => 'EUR',
        'notes' => null,
    ]);

    $recipe = Recipe::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $recipeVersion = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_type' => OwnerType::User,
        'owner_id' => $user->id,
        'visibility' => Visibility::Private,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $recipeVersion->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => $packagingItem->name,
        'unit_cost' => $packagingItem->unit_cost,
        'quantity' => 1,
    ]);

    actingAs($user);

    Livewire::test(PackagingItemsIndex::class)
        ->assertSeeHtml('data-cannot-delete="'.$packagingItem->id.'"')
        ->call('deletePackagingItem', $packagingItem->id);

    expect(UserPackagingItem::query()->find($packagingItem->id))->not->toBeNull();
});
