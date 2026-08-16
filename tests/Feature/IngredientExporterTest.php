<?php

use App\Filament\Exports\IngredientExporter;
use App\Models\Ingredient;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports separate CAS and EC values and preserves alias kinds', function (): void {
    $ingredient = Ingredient::factory()->create();
    $ingredient->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => 'SECONDARY-CAS', 'normalized_value' => 'secondary-cas', 'is_primary' => false],
        ['scheme' => 'cas', 'value' => 'PRIMARY-CAS', 'normalized_value' => 'primary-cas', 'is_primary' => true],
        ['scheme' => 'ec', 'value' => 'PRIMARY-EC', 'normalized_value' => 'primary-ec', 'is_primary' => true],
    ]);
    $ingredient->aliases()->create([
        'locale' => 'fr',
        'name' => 'Nigelle',
        'normalized_name' => 'nigelle',
        'kind' => 'common',
    ]);

    $columnMap = [
        'cas_numbers' => 'cas_numbers',
        'ec_numbers' => 'ec_numbers',
        'identifiers' => 'identifiers',
        'aliases' => 'aliases',
    ];
    $exporter = new IngredientExporter(new Export, $columnMap, []);

    expect($exporter($ingredient->load(['identifiers', 'aliases'])))->toBe([
        'PRIMARY-CAS; SECONDARY-CAS',
        'PRIMARY-EC',
        'CAS: PRIMARY-CAS; EC / EINECS: PRIMARY-EC; CAS: SECONDARY-CAS',
        'fr [Common name]: Nigelle',
    ]);
});
