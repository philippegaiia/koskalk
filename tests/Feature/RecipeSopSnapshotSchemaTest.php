<?php

use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('recipe versions store manufacturing instruction snapshots', function () {
    $instructions = '<p>Heat phase A to 70 °C.</p>';

    expect(Schema::hasColumn('recipe_versions', 'manufacturing_instructions'))->toBeTrue();

    $recipeVersion = RecipeVersion::factory()->create([
        'manufacturing_instructions' => $instructions,
    ]);

    expect($recipeVersion->fresh()->manufacturing_instructions)->toBe($instructions);
});
