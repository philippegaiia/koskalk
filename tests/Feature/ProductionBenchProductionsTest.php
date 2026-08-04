<?php

use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionRequirementKind;
use App\ProductionRunStatus;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists productions with workspace filters and links to their details', function (): void {
    $fixture = productionListFixture();
    $otherRecipe = Recipe::factory()->for($fixture['recipe']->productFamily, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'name' => 'Lavender soap',
    ]);
    $otherVersion = RecipeVersion::factory()->for($otherRecipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $fixture['workspace']->id,
        'workspace_id' => $fixture['workspace']->id,
        'visibility' => Visibility::Private,
        'is_current' => true,
    ]);
    $otherProduction = productionListRun([
        ...$fixture,
        'recipe' => $otherRecipe,
        'version' => $otherVersion,
    ], 'Lavender soap', '2026-08-12', ProductionRunStatus::Draft);

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->assertSee('Olive soap')
        ->assertSee('Lavender soap')
        ->assertSee($fixture['production']->public_id)
        ->set('search', 'Lavender')
        ->assertSee('Lavender soap')
        ->assertDontSee('Olive soap')
        ->set('status', ProductionRunStatus::Scheduled->value)
        ->assertDontSee('Lavender soap')
        ->set('search', '')
        ->set('status', '')
        ->set('dateFrom', '2026-08-12')
        ->assertSee('Lavender soap')
        ->assertDontSee('Olive soap');

    expect($otherProduction->workspace->is($fixture['workspace']))->toBeTrue();
});

it('exposes the production list, create, and detail pages inside the bench', function (): void {
    $fixture = productionListFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.index'))
        ->assertOk()
        ->assertSee('Productions')
        ->assertSee('Olive soap');
    $this->get(route('production-bench.production.create'))
        ->assertOk()
        ->assertSee('Plan production');
    $this->get(route('production-bench.production.show', $fixture['production']))
        ->assertOk()
        ->assertSee('Production details')
        ->assertSee('Olive soap');
});

it('shows immutable planning snapshots, requirements, tasks, and employee assignments', function (): void {
    $fixture = productionListFixture();
    $employee = Employee::factory()->for($fixture['workspace'])->create([
        'first_name' => 'Ana',
        'last_name' => 'Maker',
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);

    ProductionRequirement::factory()
        ->for($fixture['production'], 'productionRun')
        ->for($ingredient)
        ->create([
            'kind' => ProductionRequirementKind::Ingredient,
            'subject_name_snapshot' => 'Olive oil',
            'required_mass_grams' => '250.000000000',
        ]);
    ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($fixture['production'], 'productionRun')
        ->for($employee)
        ->create([
            'name_snapshot' => 'Cut and cure',
            'scheduled_for' => '2026-08-11',
        ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ])
        ->assertSee('Olive soap')
        ->assertSee('Olive oil')
        ->assertSee('250.000000000 g')
        ->assertSee('Cut and cure')
        ->assertSee('Ana Maker')
        ->assertSee('2026-08-11')
        ->assertSee($fixture['version']->version_number);
});

it('assigns employees and lets the operator complete or reopen a task', function (): void {
    $fixture = productionListFixture();
    $employee = Employee::factory()->for($fixture['workspace'])->create([
        'first_name' => 'Ana',
        'last_name' => 'Maker',
    ]);
    $task = ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($fixture['production'], 'productionRun')
        ->create(['name_snapshot' => 'Mix oils', 'scheduled_for' => '2026-08-10']);

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ]);

    $page->call('assignTask', $task->id, (string) $employee->id)
        ->assertHasNoErrors();
    expect($task->fresh()->employee_id)->toBe($employee->id);

    $page->call('toggleTask', $task->id)->assertHasNoErrors();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $page->call('toggleTask', $task->id)->assertHasNoErrors();
    $page->call('assignTask', $task->id, '')->assertHasNoErrors();
    expect($task->fresh()->completed_at)->toBeNull()
        ->and($task->fresh()->employee_id)->toBeNull();
});

it('cancels draft and scheduled productions with a required reason', function (): void {
    $fixture = productionListFixture();

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ])
        ->set('cancellationReason', 'Customer postponed the batch.')
        ->call('cancel')
        ->assertHasNoErrors();

    $cancelled = $fixture['production']->fresh();

    expect($cancelled->status)->toBe(ProductionRunStatus::Cancelled)
        ->and($cancelled->cancellation_reason)->toBe('Customer postponed the batch.')
        ->and($cancelled->cancelled_by_user_id)->toBe($fixture['owner']->id)
        ->and($cancelled->cancelled_at)->not->toBeNull();

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $cancelled->id,
    ])->assertDontSee(__('production_bench.production.cancel_help'));
});

it('rejects cancellation without a reason and while the bench is read-only', function (): void {
    $fixture = productionListFixture();

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ])
        ->call('cancel')
        ->assertHasErrors('cancellationReason');

    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ])
        ->set('cancellationReason', 'No longer needed.')
        ->call('cancel')
        ->assertHasErrors('cancellationReason');

    expect($fixture['production']->fresh()->status)->toBe(ProductionRunStatus::Scheduled);
});

/**
 * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, production: ProductionRun}
 */
function productionListFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'production-list-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => true,
        'version_number' => 3,
    ]);
    $production = productionListRun([
        'owner' => $owner,
        'workspace' => $workspace,
        'recipe' => $recipe,
        'version' => $version,
    ], 'Olive soap', '2026-08-10', ProductionRunStatus::Scheduled);

    return compact('owner', 'workspace', 'recipe', 'version', 'production');
}

/**
 * @param  array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function productionListRun(array $fixture, string $name, string $plannedFor, ProductionRunStatus $status): ProductionRun
{
    return ProductionRun::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'recipe_id' => $fixture['recipe']->id,
        'recipe_version_id' => $fixture['version']->id,
        'status' => $status,
        'source' => 'direct',
        'planned_for' => $plannedFor,
        'basis_kind' => 'oil_mass',
        'basis_quantity_grams' => '12000.000000000',
        'basis_input_value' => '12.000000000',
        'basis_input_unit' => 'kg',
        'expected_units' => 100,
        'notes' => $name,
        'idempotency_key' => fake()->uuid(),
        'created_by_user_id' => $fixture['owner']->id,
    ]);
}
