<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientSubcategory;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lets admins review EU and US colour declarations from the ingredient edit action', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77491',
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionVisible('marketColourLabels')
        ->callAction('marketColourLabels', data: [
            'market_labels' => [
                [
                    'market_code' => IngredientLabelMarket::Eu->value,
                    'declaration_name' => 'CI 77491',
                    'source_name' => 'EU CosIng',
                    'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database_en',
                    'effective_from' => '2026-01-01',
                    'effective_until' => null,
                ],
                [
                    'market_code' => IngredientLabelMarket::Us->value,
                    'declaration_name' => 'Iron Oxides',
                    'source_name' => 'US FDA',
                    'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73',
                    'effective_from' => null,
                    'effective_until' => null,
                ],
            ],
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Market colour labels saved');

    expect($ingredient->refresh()->marketLabels)->toHaveCount(2);
    expect($ingredient->marketLabels->mapWithKeys(
        fn (IngredientMarketLabel $label): array => [$label->market_code->value => $label->declaration_name],
    )->all())
        ->toMatchArray([
            IngredientLabelMarket::Eu->value => 'CI 77491',
            IngredientLabelMarket::Us->value => 'Iron Oxides',
        ]);
    expect($ingredient->inci_name)->toBe('CI 77491');
});

it('hides the market colour declaration action for non-colourant ingredients', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'owner_type' => null,
        'owner_id' => null,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionHidden('marketColourLabels');
});

it('shows and saves EU and US declarations in the main ingredient form for ordinary ingredients', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'subcategory' => IngredientSubcategory::VegetableOils,
        'owner_type' => null,
        'owner_id' => null,
    ]);
    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $ingredient->id,
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'Cocos nucifera oil',
        'source_name' => 'EU glossary',
        'source_url' => 'https://example.com/eu',
        'source_tier' => IngredientSourceTier::Official,
        'confidence' => IngredientEvidenceConfidence::Verified,
        'source_version' => '2026-08',
    ]);
    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $ingredient->id,
        'market_code' => IngredientLabelMarket::Us,
        'declaration_name' => 'Coconut Oil (Cocos Nucifera Oil)',
        'source_name' => 'FDA naming guidance',
        'source_url' => 'https://example.com/us',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertSeeText('Market declarations')
        ->assertSeeText('European Union')
        ->assertSeeText('United States')
        ->assertSet('data.market_labels.eu.declaration_name', 'Cocos nucifera oil')
        ->assertSet('data.market_labels.us.declaration_name', 'Coconut Oil (Cocos Nucifera Oil)')
        ->set('data.market_labels.us.declaration_name', 'Coconut Oil')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($ingredient->fresh()->marketLabels->mapWithKeys(
        fn (IngredientMarketLabel $label): array => [$label->market_code->value => $label->declaration_name],
    )->all())
        ->toMatchArray([
            IngredientLabelMarket::Eu->value => 'Cocos nucifera oil',
            IngredientLabelMarket::Us->value => 'Coconut Oil',
        ])
        ->and($ingredient->fresh()->marketLabels()
            ->where('market_code', IngredientLabelMarket::Eu->value)
            ->firstOrFail()
            ->only(['source_tier', 'confidence', 'source_version']))
        ->toMatchArray([
            'source_tier' => IngredientSourceTier::Official,
            'confidence' => IngredientEvidenceConfidence::Verified,
            'source_version' => '2026-08',
        ]);
});

it('does not expose private ingredients through the market colour label editor', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'owner_type' => 'user',
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($admin);

    $this->get(IngredientResource::getUrl('edit', ['record' => $ingredient], panel: 'admin'))
        ->assertNotFound();
});

it('does not change the canonical inci name when market declarations are reviewed', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77891',
        'owner_type' => null,
        'owner_id' => null,
    ]);

    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $ingredient->id,
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'CI 77891',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->callAction('marketColourLabels', data: [
            'market_labels' => [[
                'market_code' => IngredientLabelMarket::Eu->value,
                'declaration_name' => 'CI 77891',
                'source_name' => 'EU CosIng',
                'source_url' => 'https://example.com/eu-cosing',
                'effective_from' => null,
                'effective_until' => null,
            ]],
        ])
        ->assertNotified('Market colour labels saved');

    expect($ingredient->refresh()->inci_name)->toBe('CI 77891')
        ->and($ingredient->marketLabels)->toHaveCount(1);
});
