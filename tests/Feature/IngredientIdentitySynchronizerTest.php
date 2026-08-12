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
