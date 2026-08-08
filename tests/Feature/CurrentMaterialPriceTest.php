<?php

use App\Enums\MaterialPriceSource;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentMaterialPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores ingredient prices per gram and packaging prices per item', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $service = app(CurrentMaterialPriceService::class);

    $ingredientPrice = $service->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerMassUnit: '4.20',
        massUnit: 'kg',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
    );
    $packagingPrice = $service->rememberPackaging(
        workspace: $workspace,
        packagingItem: $packaging,
        pricePerItem: '0.42',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
    );

    expect($ingredientPrice->price_per_canonical_unit)->toBe('0.004200000000')
        ->and($packagingPrice->price_per_canonical_unit)->toBe('0.420000000000');
});

it('keeps the same ingredient price independent between workspaces', function (): void {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstWorkspace = Workspace::factory()->for($firstOwner, 'owner')->create();
    $secondWorkspace = Workspace::factory()->for($secondOwner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $service = app(CurrentMaterialPriceService::class);

    $service->rememberIngredient($firstWorkspace, $ingredient, '4.20', 'kg', 'EUR', MaterialPriceSource::ManualCosting, null, $firstOwner);
    $service->rememberIngredient($secondWorkspace, $ingredient, '7.50', 'kg', 'USD', MaterialPriceSource::ManualCosting, null, $secondOwner);

    expect($firstWorkspace->currentMaterialPrices()->sole()->price_per_canonical_unit)->toBe('0.004200000000')
        ->and($secondWorkspace->currentMaterialPrices()->sole()->price_per_canonical_unit)->toBe('0.007500000000');
});

it('rejects packaging from another workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $otherWorkspace = Workspace::factory()->create();
    $packaging = PackagingItem::factory()->for($otherWorkspace)->create();

    app(CurrentMaterialPriceService::class)->rememberPackaging(
        workspace: $workspace,
        packagingItem: $packaging,
        pricePerItem: '0.42',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
    );
})->throws(ValidationException::class);
