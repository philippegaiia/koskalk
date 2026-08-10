<?php

use App\Enums\OwnerType;
use App\Enums\ProductionRunStatus;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Models\ProductFamily;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionRunNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows a planning reference and assigns one permanent number from the production detail', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $fixture['production']->update(['status' => ProductionRunStatus::Reserved]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertSee($fixture['production']->planning_batch_number)
        ->assertSee('12 kg')
        ->assertDontSee('12.000000000')
        ->assertSee(__('production_bench.production.assign_batch_number'))
        ->assertSee('consumed permanently')
        ->call('assignBatchNumber')
        ->assertHasNoErrors()
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['message'] === __('production_bench.production.batch_number_assigned')
                && $payload['type'] === 'success';
        });

    $assigned = $fixture['production']->fresh();

    expect($assigned->batch_number)->toBe('B-00001')
        ->and($assigned->batch_number_serial)->toBe(1)
        ->and($assigned->batch_number_assigned_by_user_id)->toBe($fixture['owner']->id);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $assigned->id])
        ->assertSee($assigned->batch_number)
        ->assertDontSee(__('production_bench.production.assign_batch_number'))
        ->call('assignBatchNumber')
        ->assertHasNoErrors();

    expect($assigned->fresh()->batch_number)->toBe('B-00001');
});

it('hides individual assignment from viewers and read-only production benches', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    Livewire::actingAs($viewer)
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertDontSee(__('production_bench.production.assign_batch_number'))
        ->call('assignBatchNumber')
        ->assertForbidden();

    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertDontSee(__('production_bench.production.assign_batch_number'))
        ->call('assignBatchNumber')
        ->assertHasErrors('production_bench');
});

it('assigns selected productions chronologically, skips issued numbers, and clears the selection', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $later = productionBatchAssignmentRun($fixture, '2026-08-14');
    $alreadyAssigned = productionBatchAssignmentRun($fixture, '2026-08-15');
    $alreadyAssigned->update([
        'batch_number' => 'B-00077',
        'batch_number_serial' => 77,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $fixture['owner']->id,
    ]);

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->set('selectedProductionIds', [$later->id, $fixture['production']->id, $alreadyAssigned->id])
        ->call('assignSelectedBatchNumbers')
        ->assertHasNoErrors()
        ->assertSet('selectedProductionIds', [])
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && str_contains($payload['message'], '1')
                && $payload['type'] === 'success';
        });

    expect($fixture['production']->fresh()->batch_number)->toBe('B-00001')
        ->and($later->fresh()->batch_number)->toBe('B-00002')
        ->and($alreadyAssigned->fresh()->batch_number)->toBe('B-00077');
});

it('rejects an empty selection and rolls back when a stale selection is no longer eligible', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $draft = productionBatchAssignmentRun($fixture, '2026-08-14', ProductionRunStatus::Draft);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->call('assignSelectedBatchNumbers')
        ->assertHasErrors('selectedProductionIds');

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->set('selectedProductionIds', [$fixture['production']->id, $draft->id])
        ->call('assignSelectedBatchNumbers')
        ->assertHasErrors('selectedProductionIds');

    expect($fixture['production']->fresh()->batch_number)->toBeNull()
        ->and($draft->fresh()->batch_number)->toBeNull()
        ->and($fixture['workspace']->productionRunNumberSetting()->sole()->next_permanent_serial)->toBe(1);
});

it('surfaces permanent counter exhaustion on the assignment page', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $fixture['workspace']->productionRunNumberSetting()->update([
        'next_permanent_serial' => PHP_INT_MAX,
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->set('selectedProductionIds', [$fixture['production']->id])
        ->call('assignSelectedBatchNumbers')
        ->assertHasErrors('selectedProductionIds');

    expect($fixture['production']->fresh()->batch_number)->toBeNull();
});

it('searches by both planning and permanent batch identifiers and keeps selection usable for stock preparation', function (): void {
    $fixture = productionBatchAssignmentFixture();
    $numbered = productionBatchAssignmentRun($fixture, '2026-08-14');
    $numbered->update([
        'batch_number' => 'SOAP-00042-FR',
        'batch_number_serial' => 42,
        'batch_number_assigned_at' => now(),
        'batch_number_assigned_by_user_id' => $fixture['owner']->id,
    ]);
    $other = productionBatchAssignmentRun($fixture, '2026-08-15');

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->assertSee('T00001 · Olive soap')
        ->assertSee('12 kg')
        ->assertDontSee('12.000000000')
        ->set('search', $numbered->planning_batch_number)
        ->assertSee($numbered->planning_batch_number)
        ->assertDontSee($other->planning_batch_number)
        ->set('search', 'SOAP-00042-FR')
        ->assertSee($numbered->batch_number)
        ->set('search', '')
        ->set('selectedProductionIds', [$other->id])
        ->call('prepareSelected')
        ->assertRedirect(route('production-bench.production.prepare', ['ids' => $other->id]));
});

/** @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, production: ProductionRun} */
function productionBatchAssignmentFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'batch-assignment-'.fake()->unique()->numberBetween(1, 999999),
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
    ]);
    $production = productionBatchAssignmentRun(compact('owner', 'workspace', 'recipe', 'version'), '2026-08-10');

    return compact('owner', 'workspace', 'recipe', 'version', 'production');
}

/** @param array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion} $fixture */
function productionBatchAssignmentRun(array $fixture, string $plannedFor, ProductionRunStatus $status = ProductionRunStatus::Scheduled): ProductionRun
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
        'idempotency_key' => fake()->uuid(),
        'created_by_user_id' => $fixture['owner']->id,
        'planning_batch_number' => app(ProductionRunNumberService::class)->allocatePlanningReference($fixture['workspace']),
    ]);
}
