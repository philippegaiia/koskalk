<?php

use App\Enums\MaterialPriceSource;
use App\Enums\PackagingCategory;
use App\Models\CurrentMaterialPrice;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** @param array<string, mixed> $attributes */
function createPackagingItemForWorkspace(array $attributes): PackagingItem
{
    $userId = $attributes['created_by_user_id'] ?? $attributes['user_id'] ?? null;
    $user = is_numeric($userId) ? User::query()->findOrFail((int) $userId) : null;
    $workspaceId = $attributes['workspace_id'] ?? ($user instanceof User
        ? Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->value('id')
        : null);
    $workspace = is_numeric($workspaceId)
        ? Workspace::withoutGlobalScopes()->findOrFail((int) $workspaceId)
        : null;

    if (! $workspace instanceof Workspace && $user instanceof User) {
        $workspace = Workspace::factory()->for($user, 'owner')->create();
    }

    if (! $workspace instanceof Workspace) {
        $workspace = Workspace::factory()->create();
    }

    $actor = $user ?? $workspace->owner;
    $price = $attributes['unit_cost'] ?? null;
    $currency = (string) ($attributes['currency'] ?? $workspace->default_currency ?? 'EUR');
    unset($attributes['user_id'], $attributes['unit_cost'], $attributes['currency']);

    $packagingItem = PackagingItem::query()->create([
        'workspace_id' => $workspace->id,
        'created_by_user_id' => $actor?->id,
        'category' => PackagingCategory::Other,
        'is_active' => true,
        ...$attributes,
    ]);

    if ($price !== null && $actor instanceof User) {
        app(CurrentMaterialPriceService::class)->rememberPackaging(
            workspace: $workspace,
            packagingItem: $packagingItem,
            pricePerItem: (string) $price,
            currency: $currency,
            source: MaterialPriceSource::ManualCosting,
            sourceId: null,
            actor: $actor,
        );
    }

    return $packagingItem->load('currentPrice');
}

function rememberIngredientPriceForWorkspace(
    User $user,
    Ingredient $ingredient,
    string $pricePerKilogram,
    ?string $currency = null,
): CurrentMaterialPrice {
    $workspace = Workspace::withoutGlobalScopes()->where('owner_user_id', $user->id)->first()
        ?? Workspace::factory()->for($user, 'owner')->create();

    return app(CurrentMaterialPriceService::class)->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: $pricePerKilogram,
        massUnit: 'kg',
        currency: $currency ?? $workspace->default_currency ?? 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $user,
    );
}
