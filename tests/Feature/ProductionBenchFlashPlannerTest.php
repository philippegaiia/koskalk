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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the flash planner, simulates a line, and previews dates without creating productions', function (): void {
    $fixture = flashPlannerFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->assertSee(__('production_bench.flash.title'))
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSet('lines.0.batch_mode', (string) $fixture['preset']->id)
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

it('keeps custom quantity fields available when a product has no saved batch size', function (): void {
    $fixture = flashPlannerFixture();
    $fixture['preset']->recipes()->detach($fixture['recipe']->id);

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSet('lines.0.batch_mode', 'custom')
        ->assertSee(__('production_bench.flash.no_batch_sizes'))
        ->assertSee(__('production_bench.flash.batch_quantity'));
});

it('uses a saved batch size as a fixed preset while keeping an explicit custom option', function (): void {
    $fixture = flashPlannerFixture();

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSee(__('production_bench.flash.batch_size'))
        ->assertSee(__('production_bench.flash.use_custom_quantities'))
        ->assertSee(__('production_bench.flash.batch_size_fixed_help'))
        ->assertDontSee(__('production_bench.flash.batch_quantity'))
        ->set('lines.0.batch_mode', 'custom')
        ->assertSet('lines.0.preset_id', '')
        ->assertSee(__('production_bench.flash.batch_quantity'));
});

it('asks for a batch size when a product has several saved sizes without a default', function (): void {
    $fixture = flashPlannerFixture();
    $fixture['preset']->recipes()->updateExistingPivot($fixture['recipe']->id, ['is_default' => false]);
    $secondPreset = ProductionBatchPreset::factory()->for($fixture['workspace'])->create([
        'basis_quantity_grams' => '6000.000000000',
        'basis_input_value' => '6.000000000',
        'basis_input_unit' => MassUnit::Kilogram,
        'expected_units' => 50,
        'is_active' => true,
    ]);
    $secondPreset->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSet('lines.0.batch_mode', '')
        ->assertSee(__('production_bench.flash.choose_batch_size'))
        ->set('lines.0.batch_mode', (string) $secondPreset->id)
        ->assertSet('lines.0.preset_id', (string) $secondPreset->id)
        ->assertSet('lines.0.expected_units_per_batch', '50');
});

it('keeps the preset product line focused on its three primary inputs', function (): void {
    $fixture = flashPlannerFixture();

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->set('lines.0.recipe_id', (string) $fixture['recipe']->id)
        ->assertSee('xl:grid-cols-12')
        ->assertSee('xl:max-w-36')
        ->assertSee('border-t border-[var(--color-line)] pt-4')
        ->assertDontSee('If none is available, enter custom batch quantities.');
});

it('allows adding and removing flash product lines', function (): void {
    $fixture = flashPlannerFixture();

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class)
        ->call('addLine')
        ->assertCount('lines', 2)
        ->call('removeLine', 1)
        ->assertCount('lines', 1);
});

it('keeps the flash planner lookup query count bounded on the initial render', function (): void {
    $fixture = flashPlannerFixture();
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::actingAs($fixture['owner'])->test(FlashPlanner::class);

    expect($queries)->toHaveCount(10);
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, preset: ProductionBatchPreset} */
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

    return compact('owner', 'workspace', 'recipe', 'preset');
}
