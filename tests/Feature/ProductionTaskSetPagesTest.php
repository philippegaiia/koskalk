<?php

use App\Actions\Production\SaveProductionTaskType;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\TaskSetForm;
use App\Livewire\ProductionBench\Production\TaskSetIndex;
use App\Models\ProductFamily;
use App\Models\ProductionTaskSet;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes dedicated task set pages and links', function (): void {
    $fixture = taskSetPagesFixture();
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create([
        'name' => 'Soap workflow',
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.task-sets'))
        ->assertOk()
        ->assertSee(__('production_bench.settings.task_sets'))
        ->assertSee(route('production-bench.production.settings.task-sets.create'), false);

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.task-sets.create'))
        ->assertOk()
        ->assertSee(__('production_bench.settings.new_task_set'))
        ->assertSee(__('production_bench.settings.task_sets'))
        ->assertSee(__('production_bench.settings.applicable_products'));

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.task-sets.edit', $taskSet))
        ->assertOk()
        ->assertSee(__('production_bench.settings.edit_task_set'));
});

it('saves ordered tasks and reusable product applicability from the dedicated form', function (): void {
    $fixture = taskSetPagesFixture();
    $prepare = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Prepare moulds',
    );
    $make = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
        defaultDurationMinutes: 60,
    );
    $otherRecipe = taskSetPagesRecipe($fixture['workspace'], 'Second soap');

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetForm::class)
        ->set('name', 'Soap workflow')
        ->set('isActive', true)
        ->set('taskSetItems', [
            ['task_type_id' => (string) $prepare->id, 'days_after_production' => '-1', 'duration_minutes' => ''],
            ['task_type_id' => (string) $make->id, 'days_after_production' => '0', 'duration_minutes' => '60'],
        ])
        ->set('selectedRecipeIds', [(string) $fixture['recipe']->id, (string) $otherRecipe->id])
        ->set('defaultRecipeIds', [(string) $fixture['recipe']->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.production.settings.task-sets'));

    $taskSet = ProductionTaskSet::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($taskSet->name)->toBe('Soap workflow')
        ->and($taskSet->items->pluck('days_after_production')->all())->toBe([-1, 0])
        ->and($taskSet->recipes)->toHaveCount(2)
        ->and($taskSet->defaultRecipes()->pluck('id')->all())->toBe([$fixture['recipe']->id]);
});

it('keeps task set defaults synchronized with applicability', function (): void {
    $fixture = taskSetPagesFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetForm::class)
        ->assertSee(__('production_bench.settings.task_sets'))
        ->set('defaultRecipeIds', [(string) $fixture['recipe']->id])
        ->assertSet('selectedRecipeIds', [(string) $fixture['recipe']->id])
        ->set('selectedRecipeIds', [])
        ->assertSet('defaultRecipeIds', [])
        ->assertSeeHtml('wire:model.live="selectedRecipeIds"')
        ->assertSeeHtml('wire:model.live="defaultRecipeIds"');
});

it('requires a production-day task and accepts preparation offsets', function (): void {
    $fixture = taskSetPagesFixture();
    $prepare = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Prepare moulds',
    );

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetForm::class)
        ->set('name', 'Preparation only')
        ->set('taskSetItems', [[
            'task_type_id' => (string) $prepare->id,
            'days_after_production' => '-1',
            'duration_minutes' => '',
        ]])
        ->call('save')
        ->assertHasErrors(['taskSetItems'])
        ->assertSee(__('production_bench.settings.task_set_production_day_required'));

    expect(ProductionTaskSet::query()->where('workspace_id', $fixture['workspace']->id)->exists())->toBeFalse();
});

it('loads task set values and inactive status in the edit form', function (): void {
    $fixture = taskSetPagesFixture();
    $taskType = app(SaveProductionTaskType::class)->handle(
        actor: $fixture['owner'],
        workspace: $fixture['workspace'],
        name: 'Make batch',
    );
    $taskSet = ProductionTaskSet::factory()->for($fixture['workspace'])->create([
        'name' => 'Soap workflow',
        'is_active' => false,
    ]);
    $taskSet->items()->create([
        'production_task_type_id' => $taskType->id,
        'position' => 1,
        'days_after_production' => 0,
        'duration_minutes' => null,
    ]);
    $taskSet->recipes()->attach($fixture['recipe']->id, ['is_default' => false]);

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetForm::class, ['taskSet' => $taskSet->public_id])
        ->assertSet('name', 'Soap workflow')
        ->assertSet('isActive', false)
        ->assertSet('taskSetItems.0.days_after_production', '0')
        ->assertSet('selectedRecipeIds', [(string) $fixture['recipe']->id])
        ->assertSee(__('production_bench.common.inactive'));
});

it('filters task sets on the dedicated index and can delete them', function (): void {
    $fixture = taskSetPagesFixture();
    $visible = ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Soap workflow']);
    ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Cream workflow']);

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetIndex::class)
        ->set('search', 'Soap')
        ->assertSee('Soap workflow')
        ->assertDontSee('Cream workflow')
        ->call('delete', $visible->id)
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['message'] === __('production_bench.settings.task_set_deleted')
                && $payload['type'] === 'success';
        });

    expect(ProductionTaskSet::query()->whereKey($visible->id)->exists())->toBeFalse();
});

it('keeps task set search scoped to the active workspace', function (): void {
    $fixture = taskSetPagesFixture();
    $other = taskSetPagesFixture();
    ProductionTaskSet::factory()->for($fixture['workspace'])->create(['name' => 'Local workflow']);
    ProductionTaskSet::factory()->for($other['workspace'])->create(['name' => 'Foreign workflow']);

    Livewire::actingAs($fixture['owner'])
        ->test(TaskSetIndex::class)
        ->set('status', 'all')
        ->set('search', 'workflow')
        ->assertSee('Local workflow')
        ->assertDontSee('Foreign workflow');
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}
 */
function taskSetPagesFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $recipe = taskSetPagesRecipe($workspace, 'Workshop soap');
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

function taskSetPagesRecipe(Workspace $workspace, string $name): Recipe
{
    $family = ProductFamily::factory()->create([
        'slug' => 'task-set-pages-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);

    return Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $name,
    ]);
}
