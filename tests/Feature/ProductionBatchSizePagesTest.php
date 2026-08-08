<?php

use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\BatchSizeForm;
use App\Models\ProductFamily;
use App\Models\ProductionBatchPreset;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders a compact batch size index with a dedicated editor link', function (): void {
    $fixture = batchSizePagesFixture();
    $preset = ProductionBatchPreset::factory()->for($fixture['workspace'])->create([
        'name' => 'Soap 12 kg',
        'basis_input_value' => '12.000000000',
        'basis_quantity_grams' => '12000.000000000',
        'expected_units' => 100,
    ]);
    $preset->recipes()->attach($fixture['recipe']->id, ['is_default' => true]);

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.presets'))
        ->assertOk()
        ->assertSee('Soap 12 kg')
        ->assertSee(__('production_bench.settings.view_products'))
        ->assertSee(route('production-bench.production.settings.presets.edit', $preset), false)
        ->assertDontSee('wire:submit="savePreset"', false);

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.presets.edit', $preset))
        ->assertOk()
        ->assertSee(__('production_bench.settings.edit_batch_size'))
        ->assertSee('inputmode="numeric"', false)
        ->assertSee('type="checkbox"', false);
});

it('uses friendly batch quantity errors and requires whole expected units', function (): void {
    $fixture = batchSizePagesFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(BatchSizeForm::class)
        ->set('name', 'Soap 12 kg')
        ->set('basisInputUnit', 'kg')
        ->set('expectedUnits', '')
        ->call('save')
        ->assertHasErrors(['basisInputValue', 'expectedUnits'])
        ->assertSee(__('production_bench.settings.batch_size_required'))
        ->assertSee(__('production_bench.settings.expected_units_required'))
        ->set('basisInputValue', '12')
        ->set('expectedUnits', '100.5')
        ->call('save')
        ->assertHasErrors(['expectedUnits'])
        ->assertSee(__('production_bench.settings.expected_units_whole'));

    expect(ProductionBatchPreset::query()->where('workspace_id', $fixture['workspace']->id)->exists())->toBeFalse();
});

it('saves applicability, defaults, and the active toggle from the dedicated editor', function (): void {
    $fixture = batchSizePagesFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(BatchSizeForm::class)
        ->set('name', 'Soap 12 kg')
        ->set('basisInputValue', '12')
        ->set('basisInputUnit', 'kg')
        ->set('expectedUnits', '100')
        ->set('selectedRecipeIds', [(string) $fixture['recipe']->id])
        ->set('defaultRecipeIds', [(string) $fixture['recipe']->id])
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.production.settings.presets'));

    $preset = ProductionBatchPreset::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($preset->is_active)->toBeFalse()
        ->and($preset->recipes()->whereKey($fixture['recipe']->id)->exists())->toBeTrue()
        ->and($preset->fresh()->defaultRecipes()->whereKey($fixture['recipe']->id)->exists())->toBeFalse();
});

it('loads an inactive toggle when editing a batch size', function (): void {
    $fixture = batchSizePagesFixture();
    $preset = ProductionBatchPreset::factory()->for($fixture['workspace'])->create([
        'is_active' => false,
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(BatchSizeForm::class, ['preset' => $preset->public_id])
        ->assertSet('isActive', false)
        ->assertSee(__('production_bench.common.inactive'));
});

it('formats saved batch quantities when editing a batch size', function (): void {
    $fixture = batchSizePagesFixture();
    $preset = ProductionBatchPreset::factory()->for($fixture['workspace'])->create([
        'basis_input_value' => '12.000000000',
        'basis_quantity_grams' => '12000.000000000',
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(BatchSizeForm::class, ['preset' => $preset->public_id])
        ->assertSet('basisInputValue', '12');
});

it('keeps default product selections synchronized with applicability', function (): void {
    $fixture = batchSizePagesFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(BatchSizeForm::class)
        ->set('defaultRecipeIds', [(string) $fixture['recipe']->id])
        ->assertSet('selectedRecipeIds', [(string) $fixture['recipe']->id])
        ->set('selectedRecipeIds', [])
        ->assertSet('defaultRecipeIds', [])
        ->assertSeeHtml('wire:model.live="selectedRecipeIds"')
        ->assertSeeHtml('wire:model.live="defaultRecipeIds"');
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} */
function batchSizePagesFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'batch-size-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Workshop soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
        'batch_mass_grams' => '1000.000000000',
    ]);

    return compact('owner', 'workspace', 'recipe', 'version');
}
