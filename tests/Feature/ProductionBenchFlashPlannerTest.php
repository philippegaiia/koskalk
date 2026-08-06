<?php

use App\Livewire\ProductionBench\Production\FlashPlanner;
use App\MassUnit;
use App\Models\ProductFamily;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the flash planner, simulates a line, and previews dates without creating productions', function (): void {
    $fixture = flashPlannerFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->assertSee(__('production_bench.flash.title'))
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSet('lines.0.basis_input_value', '12')
        ->assertSet('lines.0.expected_units_per_batch', '100')
        ->set('lines.0.desired_units', '25')
        ->set('lines.0.expected_units_per_batch', '100')
        ->set('lines.0.basis_input_value', '12')
        ->set('lines.0.basis_input_unit', 'kg')
        ->call('previewDates')
        ->assertHasNoErrors()
        ->assertSet('showDatePreview', true)
        ->assertSee('Flash product')
        ->assertSee('batch 1 of 1');

    expect($page->get('datePreview'))->toHaveCount(1)
        ->and($fixture['workspace']->productionRuns()->count())->toBe(0);

    $page->call('generate')
        ->assertHasNoErrors()
        ->assertSet('showDatePreview', false)
        ->assertDontSee(__('production_bench.flash.generated_success', ['count' => 1]))
        ->assertSee(__('production_bench.flash.celebration_title'));

    expect($fixture['workspace']->productionRuns()->count())->toBe(1);
});

it('allows adding and removing flash product lines', function (): void {
    $fixture = flashPlannerFixture();

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->call('addLine')
        ->assertCount('lines', 2)
        ->call('removeLine', 1)
        ->assertCount('lines', 1);
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe} */
function flashPlannerFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'calculation_basis' => 'initial_oils',
        'slug' => 'flash-planner-family-'.fake()->unique()->numberBetween(1, 999999),
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Flash product',
    ]);
    RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);
    $preset = ProductionBatchPreset::factory()->for($workspace)->create([
        'basis_quantity_grams' => '12000.000000000',
        'basis_input_value' => '12.000000000',
        'basis_input_unit' => MassUnit::Kilogram,
        'expected_units' => 100,
        'is_active' => true,
    ]);
    $preset->recipes()->attach($recipe->id, ['is_default' => true]);

    return compact('owner', 'workspace', 'recipe');
}
