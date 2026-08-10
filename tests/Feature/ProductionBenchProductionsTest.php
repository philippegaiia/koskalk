<?php

use App\Enums\OwnerType;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRequirementKind;
use App\Enums\ProductionRunStatus;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Models\Employee;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\ProductionTask;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionRunNumberService;
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
        ->assertSee($fixture['production']->planning_batch_number)
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
        ->assertSee('250 g')
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

it('rejects invalid employee assignments and displays task operation errors', function (): void {
    $fixture = productionListFixture();
    $task = ProductionTask::factory()
        ->for($fixture['workspace'])
        ->for($fixture['production'], 'productionRun')
        ->create(['name_snapshot' => 'Mix oils', 'scheduled_for' => '2026-08-10']);

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, [
        'productionId' => $fixture['production']->id,
    ]);

    $page->call('assignTask', $task->id, '999999')
        ->assertHasErrors('task_employee');
    expect($task->fresh()->employee_id)->toBeNull();

    $fixture['production']->update(['status' => ProductionRunStatus::Completed]);

    $page->call('toggleTask', $task->id)
        ->assertHasNoErrors();
    expect($task->fresh()->completed_at)->not->toBeNull();

    $page->call('toggleTask', $task->id)
        ->assertHasErrors('task_task');
    expect($task->fresh()->completed_at)->not->toBeNull();
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

it('renders list and detail from snapshots after the product is renamed and its version deleted', function (): void {
    $fixture = productionListFixture();
    $fixture['recipe']->update(['name' => 'Renamed live product']);
    RecipeVersion::withoutGlobalScopes()->whereKey($fixture['version']->id)->delete();

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->assertSee('Olive soap')
        ->assertDontSee('Renamed live product');

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, ['productionId' => (string) $fixture['production']->id])
        ->assertSee('Olive soap')
        ->assertDontSee('Renamed live product');
});

it('groups the formula snapshot by phase on the detail page', function (): void {
    $fixture = productionListFixture();
    ProductionFormulaLine::factory()->for($fixture['production'], 'productionRun')->create([
        'component' => ProductionFormulaComponent::Ingredient,
        'subject_name_snapshot' => 'Olive oil',
        'phase_key_snapshot' => 'saponified_oils',
        'phase_name_snapshot' => 'Saponified Oils',
        'basis_percentage_snapshot' => '75.000000000',
        'planned_mass_grams' => '9000.000000000',
        'sort_order' => 1,
    ]);
    ProductionFormulaLine::factory()->for($fixture['production'], 'productionRun')->create([
        'component' => ProductionFormulaComponent::Naoh,
        'subject_name_snapshot' => 'Sodium hydroxide',
        'phase_key_snapshot' => 'lye_water',
        'phase_name_snapshot' => 'Lye Water',
        'basis_percentage_snapshot' => '7.500000000',
        'planned_mass_grams' => '900.000000000',
        'sort_order' => 2,
    ]);
    ProductionFormulaLine::factory()->for($fixture['production'], 'productionRun')->create([
        'component' => ProductionFormulaComponent::Water,
        'subject_name_snapshot' => 'Water',
        'phase_key_snapshot' => 'lye_water',
        'phase_name_snapshot' => 'Lye Water',
        'basis_percentage_snapshot' => '30.000000000',
        'planned_mass_grams' => '3600.000000000',
        'sort_order' => 3,
    ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, ['productionId' => (string) $fixture['production']->id])
        ->assertSee('Formula')
        ->assertSee('Saponified Oils')
        ->assertSee('Lye Water')
        ->assertSee('Olive oil')
        ->assertSee('Sodium hydroxide')
        ->assertSee('9000')
        ->assertDontSee('Formula version');
});

it('still renders production history after the product is permanently deleted', function (): void {
    $fixture = productionListFixture();
    Recipe::withoutGlobalScopes()->whereKey($fixture['recipe']->id)->delete();

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, ['productionId' => (string) $fixture['production']->id]);

    $page->assertSee('Olive soap')
        ->assertDontSee('Unknown product');

    expect(ProductionRun::query()->findOrFail($fixture['production']->id)->recipe_id)->toBeNull()
        ->and(ProductionRun::query()->findOrFail($fixture['production']->id)->displayRecipeName())->toBe('Olive soap');
});

it('filters the production list by recipe public id from a product link', function (): void {
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
    productionListRun([
        ...$fixture,
        'recipe' => $otherRecipe,
        'version' => $otherVersion,
    ], 'Lavender soap', '2026-08-12', ProductionRunStatus::Draft);

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->set('recipeFilter', $fixture['recipe']->public_id)
        ->assertSee('Olive soap')
        ->assertDontSee('Lavender soap');
});

it('deletes a deletable run from the list and keeps reserved runs', function (): void {
    $fixture = productionListFixture();
    $other = productionListRun([...$fixture, 'recipe' => $fixture['recipe'], 'version' => $fixture['version']], 'Olive soap', '2026-08-15', ProductionRunStatus::Scheduled);
    $requirement = ProductionRequirement::factory()->for($other, 'productionRun')->create();
    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $other->id,
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => StockLot::factory()->released()->for($fixture['workspace'])->create()->id,
        'created_by_user_id' => $fixture['owner']->id,
    ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->assertSeeHtml('wire:click.stop="deleteProduction('.$fixture['production']->id.')')
        ->call('deleteProduction', $fixture['production']->id)
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_starts_with($payload['message'], __('production_bench.production.deleted'))
                && $payload['type'] === 'success';
        });

    expect(ProductionRun::query()->find($fixture['production']->id))->toBeNull()
        ->and(ProductionRun::query()->find($other->id))->not->toBeNull();

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->call('deleteProduction', $other->id)
        ->assertHasErrors('selectedProductionIds');

    expect(ProductionRun::query()->find($other->id))->not->toBeNull();
});

it('keeps the public id only inside production URLs', function (): void {
    $fixture = productionListFixture();
    $publicId = $fixture['production']->public_id;

    $listHtml = Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)->html();
    $detailHtml = Livewire::actingAs($fixture['owner'])->test(ProductionDetail::class, ['productionId' => (string) $fixture['production']->id])->html();

    foreach ([$listHtml, $detailHtml] as $html) {
        expect(preg_replace('/href="[^"]*"/', '', $html))->not->toContain($publicId)
            ->and($html)->toContain($publicId);
    }
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
        'recipe_name_snapshot' => $name,
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
        'planning_batch_number' => app(ProductionRunNumberService::class)->allocatePlanningReference($fixture['workspace']),
    ]);
}

it('does not show a partial-reservation badge for a scheduled run with no reservations', function (): void {
    $fixture = productionListFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->assertSee(__('production_bench.production.status.scheduled'))
        ->assertDontSee(__('production_bench.production.partially_reserved_short', ['short' => '']));
});

it('shows a partial-reservation badge only when reservations are short of requirements', function (): void {
    $fixture = productionListFixture();
    $requirement = ProductionRequirement::factory()->for($fixture['production'], 'productionRun')->create([
        'required_mass_grams' => '1000.000000000',
        'kind' => 'ingredient',
        'ingredient_id' => Ingredient::factory()->create()->id,
    ]);
    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $fixture['production']->id,
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => StockLot::factory()->released()->for($fixture['workspace'])->create()->id,
        'quantity' => '400.000000000',
        'created_by_user_id' => $fixture['owner']->id,
    ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->assertSee(__('production_bench.production.partially_reserved_short', ['short' => '600']));
});

it('renders a clickable row that navigates to the production detail page', function (): void {
    $fixture = productionListFixture();

    $html = Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)->html();

    expect($html)->toContain('data-row-link')
        ->and($html)->toContain(route('production-bench.production.show', $fixture['production']))
        ->and($html)->toContain('window.Livewire?.navigate');
});
