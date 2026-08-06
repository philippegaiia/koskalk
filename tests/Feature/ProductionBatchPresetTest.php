<?php

use App\Actions\Production\SaveProductionBatchPreset;
use App\Actions\Production\SyncProductionBatchPresetProducts;
use App\MassUnit;
use App\Models\ProductFamily;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('reuses one batch size across products and keeps one optional default per product', function (): void {
    $fixture = productionBatchPresetTask3Fixture();
    $otherRecipe = Recipe::factory()->for($fixture['recipe']->productFamily, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
    ]);

    $preset = app(SaveProductionBatchPreset::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'SOAP 100G',
        basisInputValue: '12',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
    );

    app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $preset,
        assignments: [
            ['recipe_id' => $fixture['recipe']->id, 'is_default' => true],
            ['recipe_id' => $otherRecipe->id, 'is_default' => true],
        ],
    );

    expect($preset->fresh()->recipes)->toHaveCount(2)
        ->and($fixture['recipe']->fresh()->defaultProductionBatchPresets()->first()?->is($preset))->toBeTrue()
        ->and($otherRecipe->fresh()->defaultProductionBatchPresets()->first()?->is($preset))->toBeTrue();
});

it('saves an optional preset with an exact canonical mass snapshot', function (): void {
    $fixture = productionBatchPresetTask3Fixture();

    $preset = app(SaveProductionBatchPreset::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Standard soap mould',
        basisInputValue: '12',
        basisInputUnit: MassUnit::Kilogram,
        expectedUnits: 100,
    );
    app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $preset,
        assignments: [['recipe_id' => $fixture['recipe']->id, 'is_default' => true]],
    );

    expect($preset)->toBeInstanceOf(ProductionBatchPreset::class)
        ->and($preset->workspace_id)->toBe($fixture['workspace']->id)
        ->and($preset->basis_quantity_grams)->toBe('12000.000000000')
        ->and($preset->basis_input_value)->toBe('12.000000000')
        ->and($preset->basis_input_unit)->toBe(MassUnit::Kilogram)
        ->and($preset->expected_units)->toBe(100)
        ->and($preset->fresh()->defaultRecipes()->first()?->is($fixture['recipe']))->toBeTrue()
        ->and($preset->is_active)->toBeTrue();
});

it('supports zero, one, and several active presets without constraining production', function (): void {
    $fixture = productionBatchPresetTask3Fixture();

    expect($fixture['recipe']->productionBatchPresets)->toHaveCount(0);

    $first = app(SaveProductionBatchPreset::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Small mould',
        basisInputValue: '6',
        basisInputUnit: 'kg',
        expectedUnits: 48,
    );
    $second = app(SaveProductionBatchPreset::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Large mould',
        basisInputValue: '12',
        basisInputUnit: 'kg',
        expectedUnits: 100,
    );
    $sync = app(SyncProductionBatchPresetProducts::class);
    $sync->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $first,
        assignments: [['recipe_id' => $fixture['recipe']->id]],
    );
    $sync->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $second,
        assignments: [['recipe_id' => $fixture['recipe']->id]],
    );

    expect($first->is_active)->toBeTrue()
        ->and($second->is_active)->toBeTrue()
        ->and($fixture['recipe']->productionBatchPresets()->count())->toBe(2)
        ->and($fixture['recipe']->defaultProductionBatchPresets()->count())->toBe(0);
});

it('keeps only one active default preset per recipe and permits inactive presets', function (): void {
    $fixture = productionBatchPresetTask3Fixture();
    $action = app(SaveProductionBatchPreset::class);

    $first = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Default one',
        basisInputValue: '12',
        basisInputUnit: 'kg',
        expectedUnits: 100,
    );
    app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $first,
        assignments: [['recipe_id' => $fixture['recipe']->id, 'is_default' => true]],
    );
    $second = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Default two',
        basisInputValue: '26',
        basisInputUnit: 'kg',
        expectedUnits: 288,
    );
    app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $second,
        assignments: [['recipe_id' => $fixture['recipe']->id, 'is_default' => true]],
    );
    $inactive = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Retired mould',
        basisInputValue: '26',
        basisInputUnit: 'kg',
        expectedUnits: 288,
        isActive: false,
    );
    app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $inactive,
        assignments: [['recipe_id' => $fixture['recipe']->id]],
    );

    expect($fixture['recipe']->fresh()->defaultProductionBatchPresets()->count())->toBe(1)
        ->and($fixture['recipe']->fresh()->defaultProductionBatchPresets()->first()?->is($second))->toBeTrue()
        ->and($inactive->is_active)->toBeFalse()
        ->and($fixture['recipe']->productionBatchPresets()->where('is_active', false)->count())->toBe(1);
});

it('converts non-kilogram inputs with the same exact mass converter used by production planning', function (): void {
    $fixture = productionBatchPresetTask3Fixture();

    $preset = app(SaveProductionBatchPreset::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'One ounce sample',
        basisInputValue: '1',
        basisInputUnit: MassUnit::Ounce,
        expectedUnits: 1,
    );

    expect($preset->basis_quantity_grams)->toBe('28.349523125');
});

it('rejects cross-workspace recipes and read-only writes', function (): void {
    $fixture = productionBatchPresetTask3Fixture();
    $other = productionBatchPresetTask3Fixture();
    $action = app(SaveProductionBatchPreset::class);

    $foreignPreset = $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Foreign preset',
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 1,
    );

    expect(fn (): ProductionBatchPreset => app(SyncProductionBatchPresetProducts::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        preset: $foreignPreset,
        assignments: [['recipe_id' => $other['recipe']->id]],
    ))->toThrow(ValidationException::class);

    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    expect(fn (): ProductionBatchPreset => $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Read only',
        basisInputValue: '1',
        basisInputUnit: 'kg',
        expectedUnits: 1,
    ))->toThrow(ValidationException::class);
});

it('rejects invalid preset quantities and names', function (): void {
    $fixture = productionBatchPresetTask3Fixture();
    $action = app(SaveProductionBatchPreset::class);

    expect(fn (): ProductionBatchPreset => $action->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: '',
        basisInputValue: '0',
        basisInputUnit: 'kg',
        expectedUnits: 0,
    ))->toThrow(ValidationException::class);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe}
 */
function productionBatchPresetTask3Fixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'preset-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);

    return compact('owner', 'workspace', 'recipe');
}
