<?php

use App\Enums\MassDisplaySystem;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds canonical mass and workspace display preference columns without changing basic production history', function (): void {
    expect(Schema::hasColumn('recipe_versions', 'batch_mass_grams'))->toBeTrue()
        ->and(Schema::hasColumn('recipe_version_costings', 'oil_mass_grams_for_costing'))->toBeTrue()
        ->and(Schema::hasColumn('workspaces', 'mass_display_system'))->toBeTrue()
        ->and(Schema::hasColumn('production_batches', 'batch_mass_grams'))->toBeFalse()
        ->and(Schema::hasColumn('production_batch_ingredients', 'mass_grams'))->toBeFalse();
});

it('persists canonical formula and costing mass to nine decimal places', function (): void {
    $user = User::factory()->create();
    $version = RecipeVersion::factory()->create([
        'owner_id' => $user->id,
        'batch_mass_grams' => '1000.123456789',
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => '2.205',
        'oil_unit_for_costing' => 'lb',
        'oil_mass_grams_for_costing' => '1000.123456789',
        'currency' => 'EUR',
    ]);

    expect($version->fresh()?->batch_mass_grams)->toBe('1000.123456789')
        ->and($costing->fresh()?->oil_mass_grams_for_costing)->toBe('1000.123456789');
});

it('casts the workspace mass display system and defaults it to metric', function (): void {
    $workspace = Workspace::factory()->create();

    expect($workspace->fresh()?->mass_display_system)->toBe(MassDisplaySystem::Metric);

    $workspace->update(['mass_display_system' => MassDisplaySystem::UsCustomary]);

    expect($workspace->fresh()?->mass_display_system)->toBe(MassDisplaySystem::UsCustomary);
});

it('defines legacy backfill factors for every supported unit', function (): void {
    $migration = collect(glob(database_path('migrations/*_add_mass_foundation_columns.php')))
        ->map(fn (string $path): string => file_get_contents($path))
        ->first();

    expect($migration)->toBeString()
        ->toContain("'g' => '1'")
        ->toContain("'kg' => '1000'")
        ->toContain("'oz' => '28.349523125'")
        ->toContain("'lb' => '453.59237'");
});
