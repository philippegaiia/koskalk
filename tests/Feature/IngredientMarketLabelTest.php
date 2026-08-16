<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientLabelMarket;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use App\Models\User;
use App\Services\IngredientMarketLabelService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('stores one source-backed printed declaration per colourant and market', function (): void {
    expect(Schema::hasColumns('ingredient_market_labels', [
        'ingredient_id',
        'market_code',
        'declaration_name',
        'source_name',
        'source_url',
        'effective_from',
        'effective_until',
        'reviewed_at',
        'reviewed_by_user_id',
    ]))->toBeTrue();

    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77491',
    ]);
    $label = IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Us,
        'declaration_name' => 'Iron Oxides',
        'source_name' => 'U.S. Food and Drug Administration',
        'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
    ]);

    expect($ingredient->fresh()->marketLabels)->toHaveCount(1)
        ->and($label->fresh()->market_code)->toBe(IngredientLabelMarket::Us)
        ->and($label->fresh()->ingredient->is($ingredient))->toBeTrue()
        ->and(Schema::hasTable('ingredient_market_labels'))->toBeTrue();
});

it('enforces a single current row per colourant and market', function (): void {
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Colourants]);

    IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Eu,
    ]);

    expect(fn () => IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Eu,
    ]))->toThrow(QueryException::class);
});

it('validates market labels as printed names and supports ordinary platform ingredients', function (): void {
    $service = app(IngredientMarketLabelService::class);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77491',
    ]);
    $actor = User::factory()->create(['is_admin' => true]);

    expect(fn () => $service->replaceReviewed($ingredient, [[
        'market_code' => 'us',
        'declaration_name' => 'CI 77491',
        'source_name' => 'FDA',
        'source_url' => 'https://www.fda.gov/cosmetics/cosmetic-ingredient-names',
    ]], $actor))->toThrow(ValidationException::class, 'US colour declarations must use the applicable US name');

    $ordinary = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
    ]);
    $service->replaceReviewed($ordinary, [[
        'market_code' => 'eu',
        'declaration_name' => 'Olive Oil',
        'source_name' => 'Source',
        'source_url' => 'https://example.test/source',
    ]], $actor);

    expect($ordinary->fresh()->marketLabels)->toHaveCount(1);
});

it('persists imported market-label provenance', function (): void {
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Lipids]);

    app(IngredientMarketLabelService::class)->mergeImported($ingredient, [[
        'market_code' => 'us',
        'declaration_name' => 'Argan Oil',
        'source_name' => 'FDA cosmetic ingredient naming guidance',
        'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
        'source_tier' => 'official',
        'confidence' => 'supported',
        'source_version' => '21 CFR 701.3',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-13T12:00:00+00:00',
    ]]);

    $label = $ingredient->fresh()->marketLabels->sole();
    expect($label->source_tier->value)->toBe('official')
        ->and($label->confidence->value)->toBe('supported')
        ->and($label->source_version)->toBe('21 CFR 701.3');
});

it('replaces reviewed EU and US rows while preserving the canonical CI identity', function (): void {
    $service = app(IngredientMarketLabelService::class);
    $actor = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77491',
    ]);

    $service->replaceReviewed($ingredient, [
        [
            'market_code' => 'eu',
            'declaration_name' => 'CI 77491',
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
        ],
        [
            'market_code' => 'us',
            'declaration_name' => 'Iron Oxides',
            'source_name' => 'U.S. Food and Drug Administration',
            'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250',
        ],
    ], $actor);

    expect($ingredient->fresh()->inci_name)->toBe('CI 77491')
        ->and($ingredient->fresh()->marketLabels)->toHaveCount(2)
        ->and($ingredient->fresh()->marketLabels->pluck('market_code')->all())
        ->toEqual([IngredientLabelMarket::Eu, IngredientLabelMarket::Us])
        ->and($ingredient->fresh()->marketLabels->first()->reviewed_by_user_id)->toBe($actor->id);
});

it('merges imported rows without deleting an omitted supported row', function (): void {
    $service = app(IngredientMarketLabelService::class);
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Colourants]);

    $service->replaceImported($ingredient, [
        [
            'market_code' => 'eu',
            'declaration_name' => 'CI 77491',
            'source_name' => 'European Commission',
            'source_url' => 'https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en',
        ],
        [
            'market_code' => 'us',
            'declaration_name' => 'Iron Oxides',
            'source_name' => 'U.S. Food and Drug Administration',
            'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250',
        ],
    ]);

    $service->mergeImported($ingredient, [[
        'market_code' => 'us',
        'declaration_name' => 'Iron Oxides (CI 77491)',
        'source_name' => 'U.S. Food and Drug Administration',
        'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250',
    ]]);

    expect($ingredient->fresh()->marketLabels)->toHaveCount(2)
        ->and($ingredient->fresh()->marketLabels->firstWhere('market_code', IngredientLabelMarket::Us)->declaration_name)
        ->toBe('Iron Oxides (CI 77491)');
});

it('preserves an administrator-reviewed label during a default imported merge', function (): void {
    $service = app(IngredientMarketLabelService::class);
    $actor = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Colourants]);

    $service->replaceReviewed($ingredient, [[
        'market_code' => 'us',
        'declaration_name' => 'Iron Oxides',
        'source_name' => 'US FDA',
        'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73',
    ]], $actor);

    $service->mergeImported($ingredient, [[
        'market_code' => 'us',
        'declaration_name' => 'Iron Oxides (CI 77491)',
        'source_name' => 'US FDA updated',
        'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C',
        'reviewed_at' => '2026-08-13',
    ]]);

    $label = $ingredient->fresh()->marketLabels->sole();

    expect($label->reviewed_by_user_id)->toBe($actor->id)
        ->and($label->declaration_name)->toBe('Iron Oxides')
        ->and($label->source_name)->toBe('US FDA');
});

it('cascades labels and nulls the reviewing administrator on deletion', function (): void {
    $actor = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['category' => IngredientCategory::Colourants]);
    $label = IngredientMarketLabel::factory()->for($ingredient)->create([
        'reviewed_by_user_id' => $actor->id,
    ]);

    $actor->delete();

    expect($label->fresh()->reviewed_by_user_id)->toBeNull();

    $ingredient->delete();

    expect(IngredientMarketLabel::query()->whereKey($label->id)->exists())->toBeFalse();
});
