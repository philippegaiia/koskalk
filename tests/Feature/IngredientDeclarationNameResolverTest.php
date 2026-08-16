<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientLabelMarket;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use App\Models\RegulatoryRegime;
use App\Services\InciGenerationService;
use App\Services\IngredientDeclarationNameResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('resolves the effective market declaration without changing the canonical ingredient identity', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77491',
    ]);

    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $ingredient->id,
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'CI 77491 Updated',
        'effective_from' => '2026-08-13',
        'effective_until' => null,
    ]);

    $ingredient->load('marketLabels');

    expect(app(IngredientDeclarationNameResolver::class)->resolve(
        $ingredient,
        IngredientLabelMarket::Eu->value,
        CarbonImmutable::parse('2026-08-13'),
    ))->toBe('CI 77491 UPDATED')
        ->and($ingredient->inci_name)->toBe('CI 77491');
});

it('falls back to the canonical CI value in the EU and requires a source-backed US declaration', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'inci_name' => 'CI 77891',
    ]);
    $ingredient->load('marketLabels');

    $resolver = app(IngredientDeclarationNameResolver::class);

    expect($resolver->resolve($ingredient, IngredientLabelMarket::Eu->value))->toBe('CI 77891');

    $exception = null;

    try {
        $resolver->resolve($ingredient, IngredientLabelMarket::Us->value);
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toHaveKey('market_labels.us');
});

it('resolves explicit EU and US declarations for an ordinary ingredient', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
    ]);
    IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'Argania Spinosa Kernel Oil',
    ]);
    IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Us,
        'declaration_name' => 'Argan Oil (Argania Spinosa Kernel Oil)',
    ]);
    $ingredient->load('marketLabels');

    $resolver = app(IngredientDeclarationNameResolver::class);

    expect($resolver->resolve($ingredient, IngredientLabelMarket::Eu->value))
        ->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and($resolver->resolve($ingredient, IngredientLabelMarket::Us->value))
        ->toBe('ARGAN OIL (ARGANIA SPINOSA KERNEL OIL)');
});

it('blocks a missing ordinary US declaration and permits EU canonical fallback only when explicit', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
    ]);
    $ingredient->load('marketLabels');
    $resolver = app(IngredientDeclarationNameResolver::class);

    expect(fn () => $resolver->resolve($ingredient, IngredientLabelMarket::Us->value))
        ->toThrow(ValidationException::class)
        ->and(fn () => $resolver->resolve($ingredient, IngredientLabelMarket::Eu->value))
        ->toThrow(ValidationException::class)
        ->and($resolver->resolve(
            $ingredient,
            IngredientLabelMarket::Eu->value,
            allowLegacyEuFallback: true,
        ))->toBe('ARGANIA SPINOSA KERNEL OIL');
});

it('uses the selected market declaration in generated ingredient lists', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Colourants,
        'display_name' => 'Red iron oxide',
        'inci_name' => 'CI 77491',
    ]);
    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $ingredient->id,
        'market_code' => IngredientLabelMarket::Us,
        'declaration_name' => 'Iron Oxides',
        'source_name' => 'US FDA',
        'source_url' => 'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73',
    ]);
    $regime = RegulatoryRegime::factory()->create([
        'code' => 'us_test',
        'market_code' => IngredientLabelMarket::Us->value,
        'name' => 'US test regime',
        'status' => 'active',
    ]);

    $result = app(InciGenerationService::class)->generate([
        'oil_weight' => 100,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => $regime->code,
        'phase_items' => [
            'saponified_oils' => [],
            'additives' => [[
                'ingredient_id' => $ingredient->id,
                'weight' => 10,
                'percentage' => 10,
            ]],
            'fragrance' => [],
        ],
    ]);

    expect($result['final_labels'])->toContain('IRON OXIDES')
        ->and($result['final_labels'])->not->toContain('CI 77491');
});
