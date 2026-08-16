<?php

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Services\IngredientIdentitySynchronizer;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
});

it('normalizes identity rows, selects primaries, and returns simple form state', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
    ]);
    $service = app(IngredientIdentitySynchronizer::class);

    $service->sync($ingredient, [
        'cas_number' => ' 19856-23-6 ',
        'ec_number' => null,
        'additional_identifiers' => [[
            'scheme' => 'cas',
            'value' => '8001-25-00',
            'is_primary' => false,
        ]],
        'aliases' => [[
            'locale' => 'und',
            'name' => '  Sodium   Levulinate ',
            'kind' => 'common',
        ]],
    ]);

    $state = $service->formState($ingredient->fresh());

    expect($state['cas_number'])->toBe('19856-23-6')
        ->and($state['additional_identifiers'])->toHaveCount(1)
        ->and($state['additional_identifiers'][0]['value'])->toBe('8001-25-00')
        ->and($state['aliases'][0]['name'])->toBe('Sodium Levulinate')
        ->and($ingredient->fresh()->identifiers->first()->is_primary)->toBeTrue()
        ->and($ingredient->fresh()->aliases->first()->kind)->toBe(IngredientAliasKind::Common);
});

it('enforces workspace and platform identity limits before replacing rows', function (): void {
    $workspaceIngredient = Ingredient::factory()->create([
        'owner_type' => 'workspace',
    ]);
    $service = app(IngredientIdentitySynchronizer::class);

    expect(fn () => $service->sync($workspaceIngredient, [
        'cas_number' => null,
        'ec_number' => null,
        'additional_identifiers' => collect(range(1, 11))->map(fn (int $number): array => [
            'scheme' => 'unii',
            'value' => "UNII{$number}",
        ])->all(),
        'aliases' => [],
    ]))->toThrow(ValidationException::class);

    $platformIngredient = Ingredient::factory()->create(['owner_type' => null]);
    expect(fn () => $service->sync($platformIngredient, [
        'cas_number' => null,
        'ec_number' => null,
        'additional_identifiers' => [],
        'aliases' => collect(range(1, 6))->map(fn (int $number): array => [
            'locale' => 'fr',
            'name' => "Alias {$number}",
            'kind' => 'common',
        ])->all(),
    ]))->toThrow(ValidationException::class);
});

it('rejects duplicate aliases and conflicting primaries', function (): void {
    $ingredient = Ingredient::factory()->create(['owner_type' => 'workspace']);
    $service = app(IngredientIdentitySynchronizer::class);

    expect(fn () => $service->sync($ingredient, [
        'cas_number' => '19856-23-6',
        'ec_number' => null,
        'additional_identifiers' => [[
            'scheme' => 'cas',
            'value' => '19856-23-6',
            'is_primary' => true,
        ]],
        'aliases' => [
            ['locale' => 'und', 'name' => 'Black cumin', 'kind' => 'common'],
            ['locale' => 'und', 'name' => ' black   cumin ', 'kind' => 'spelling'],
        ],
    ]))->toThrow(ValidationException::class);
});

it('allows an explicitly selected additional identifier to replace the simple primary', function (): void {
    $ingredient = Ingredient::factory()->create();
    $service = app(IngredientIdentitySynchronizer::class);

    $service->sync($ingredient, [
        'cas_number' => '19856-23-6',
        'ec_number' => null,
        'additional_identifiers' => [[
            'scheme' => 'cas',
            'value' => '8001-25-00',
            'is_primary' => true,
        ]],
        'aliases' => [],
    ]);

    $identifiers = $ingredient->fresh()->identifiers;

    expect($identifiers->where('is_primary', true)->value('value'))->toBe('8001-25-00')
        ->and($identifiers->where('is_primary', false)->value('value'))->toBe('19856-23-6');
});

it('supports open structure and public database identifiers as additional references', function (): void {
    $ingredient = Ingredient::factory()->create();
    $service = app(IngredientIdentitySynchronizer::class);

    $service->sync($ingredient, [
        'cas_number' => '56-81-5',
        'ec_number' => '200-289-5',
        'additional_identifiers' => [
            [
                'scheme' => 'inchikey',
                'value' => 'PEDCQBHIVMGVHV-UHFFFAOYSA-N',
                'is_primary' => true,
            ],
            [
                'scheme' => 'pubchem_cid',
                'value' => '753',
                'is_primary' => true,
            ],
        ],
        'aliases' => [],
    ]);

    expect($service->formState($ingredient->fresh())['additional_identifiers'])
        ->toMatchArray([
            [
                'scheme' => 'inchikey',
                'value' => 'PEDCQBHIVMGVHV-UHFFFAOYSA-N',
                'is_primary' => true,
            ],
            [
                'scheme' => 'pubchem_cid',
                'value' => '753',
                'is_primary' => true,
            ],
        ]);
});

it('preserves source evidence when an unchanged identifier is saved without evidence form state', function (): void {
    $ingredient = Ingredient::factory()->create();
    $identifier = $ingredient->identifiers()->create([
        'scheme' => 'cas',
        'value' => '8001-31-8',
        'normalized_value' => '8001-31-8',
        'is_primary' => true,
    ]);
    $identifier->evidence()->create([
        'source_name' => 'CosIng Checker',
        'source_url' => 'https://cosingchecker.com/ingredients/example',
        'source_tier' => 'structured_mirror',
        'confidence' => 'supported',
        'retrieved_at' => now(),
    ]);

    $service = app(IngredientIdentitySynchronizer::class);
    $service->sync($ingredient, $service->formState($ingredient));

    $savedIdentifier = $ingredient->fresh()->identifiers()->where('scheme', 'cas')->firstOrFail();

    expect($savedIdentifier->evidence)->toHaveCount(1)
        ->and($savedIdentifier->evidence->first()->source_url)
        ->toBe('https://cosingchecker.com/ingredients/example');
});

it('preserves reviewed evidence when enrichment supplies only a new evidence row', function (): void {
    $ingredient = Ingredient::factory()->create();
    $identifier = $ingredient->identifiers()->create([
        'scheme' => 'cas',
        'value' => '8001-31-8',
        'normalized_value' => '8001-31-8',
        'is_primary' => true,
    ]);
    $identifier->evidence()->create([
        'source_name' => 'Reviewed source',
        'source_url' => 'https://reviewed.example.test/ingredient',
        'source_tier' => 'reviewer_supplied',
        'confidence' => 'supported',
        'retrieved_at' => now(),
    ]);

    app(IngredientIdentitySynchronizer::class)->sync($ingredient, [
        'cas_number' => '8001-31-8',
        'ec_number' => null,
        'additional_identifiers' => [],
        'aliases' => [],
    ], [
        [
            'scheme' => 'cas',
            'value' => '8001-31-8',
            'evidence' => [[
                'source_name' => 'New structured source',
                'source_url' => 'https://structured.example.test/ingredient',
                'source_tier' => 'structured_mirror',
                'confidence' => 'supported',
                'retrieved_at' => now()->toIso8601String(),
            ]],
        ],
    ]);

    expect($identifier->fresh()->evidence()->orderBy('source_url')->pluck('source_url')->all())
        ->toBe([
            'https://reviewed.example.test/ingredient',
            'https://structured.example.test/ingredient',
        ]);
});
