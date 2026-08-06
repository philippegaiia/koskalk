<?php

use App\Actions\Production\BackfillProductionFormulaSnapshot;
use App\Actions\Production\CreateProductionDraft;
use App\MassUnit;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\Visibility;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills a complete formula snapshot for a legacy soap production and is idempotent', function (): void {
    $fixture = productionBackfillSoapFixture();
    $production = legacySoapProduction($fixture, 'legacy-backfill-1');

    expect($production->formula_snapshot_completed_at)->toBeNull()
        ->and($production->formulaLines()->count())->toBe(0);

    $action = app(BackfillProductionFormulaSnapshot::class);

    expect($action->handle($production))->toBeTrue();

    $production->refresh();

    expect($production->formula_snapshot_completed_at)->not->toBeNull()
        ->and($production->recipe_name_snapshot)->toBe($fixture['recipe']->name)
        ->and($production->source_formula_version_number)->toBe($fixture['version']->version_number)
        ->and($production->formula_context_snapshot['lye_type'])->toBe('naoh')
        ->and($production->formulaLines()->where('component', 'ingredient')->pluck('planned_mass_grams')->all())
        ->toBe(['10500.000000000', '3500.000000000'])
        ->and($production->formulaLines()->where('component', 'naoh')->count())->toBe(1)
        ->and($production->formulaLines()->where('component', 'water')->count())->toBe(1)
        ->and($production->formulaLines()->count())->toBe(4);

    $lineCount = $production->formulaLines()->count();

    expect($action->handle($production))->toBeTrue()
        ->and($production->formulaLines()->count())->toBe($lineCount);
});

it('leaves the run incomplete and writes nothing when the source version is missing', function (): void {
    $fixture = productionBackfillSoapFixture();
    $production = legacySoapProduction($fixture, 'legacy-missing-1');

    $production->update(['recipe_version_id' => null]);
    RecipeVersion::withoutGlobalScopes()->whereKey($fixture['version']->id)->delete();

    expect(app(BackfillProductionFormulaSnapshot::class)->handle($production))->toBeFalse();

    $production->refresh();

    expect($production->formula_snapshot_completed_at)->toBeNull()
        ->and($production->formulaLines()->count())->toBe(0)
        ->and(ProductionFormulaLine::query()->count())->toBe(0);
});

it('completes the remaining run after its source version is restored', function (): void {
    $fixture = productionBackfillSoapFixture();
    $first = legacySoapProduction($fixture, 'legacy-first-1');
    $second = legacySoapProduction($fixture, 'legacy-second-1');

    $second->update(['recipe_version_id' => null]);

    $this->artisan('production:backfill-formula-snapshots')
        ->expectsOutputToContain('failed: 1')
        ->assertExitCode(Command::FAILURE);

    expect($first->refresh()->formula_snapshot_completed_at)->not->toBeNull()
        ->and($second->refresh()->formula_snapshot_completed_at)->toBeNull();

    $second->update(['recipe_version_id' => $fixture['version']->id]);

    $this->artisan('production:backfill-formula-snapshots')
        ->assertExitCode(Command::SUCCESS);

    expect($second->refresh()->formula_snapshot_completed_at)->not->toBeNull()
        ->and($second->refresh()->formulaLines()->count())->toBe(4);
});

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function legacySoapProduction(array $fixture, string $idempotencyKey): ProductionRun
{
    $production = app(CreateProductionDraft::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        recipe: $fixture['recipe'],
        basisInputValue: '14',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
        idempotencyKey: $idempotencyKey,
    );

    // Strip the independent snapshot to simulate a legacy pre-snapshot run.
    $production->formulaLines()->delete();
    $production->update([
        'recipe_name_snapshot' => null,
        'source_formula_version_number' => null,
        'formula_context_snapshot' => null,
        'formula_snapshot_completed_at' => null,
    ]);

    return $production;
}

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function productionBackfillSoapFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'backfill-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Backfill soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'manufacturing_mode' => 'saponify_in_formula',
        'batch_mass_grams' => '1000.000000000',
        'batch_unit' => 'g',
        'calculation_context' => [
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'superfat' => 5,
            'oil_weight' => 1000,
            'oil_unit' => 'g',
            'mass_grams' => 1000,
            'totals' => [],
        ],
        'water_settings' => ['mode' => 'percent_of_oils', 'value' => 38],
    ]);

    $oleic = FattyAcid::factory()->create(['key' => 'oleic-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Oleic']);
    $lauric = FattyAcid::factory()->create(['key' => 'lauric-'.fake()->unique()->numberBetween(1, 999999), 'name' => 'Lauric']);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $olive->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 100,
    ]);
    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    IngredientSapProfile::factory()->create(['ingredient_id' => $coconut->id, 'koh_sap_value' => 0.19]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $coconut->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 100,
    ]);

    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Saponified Oils',
        'slug' => 'saponified_oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($olive)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 1,
        'percentage' => '75.0000',
        'weight' => null,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($coconut)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'position' => 2,
        'percentage' => '25.0000',
        'weight' => null,
    ]);

    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $version->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}
