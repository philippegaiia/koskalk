<?php

use App\Enums\OwnerType;
use App\Enums\ProductionRunStatus;
use App\Enums\Visibility;
use App\Livewire\ProductionBench\Production\ProductionCreate;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Models\ProductFamily;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps production location controls out of operational pages when disabled', function (): void {
    $fixture = productionLocationUiFixture();
    $fixture['workspace']->update(['uses_production_locations' => false]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCreate::class)
        ->assertDontSee($fixture['location']->name);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->assertDontSee($fixture['location']->name);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertDontSee($fixture['location']->name);
});

it('prefills an active product default and allows the operator to clear it', function (): void {
    $fixture = productionLocationUiFixture(withRecipe: true);
    $fixture['recipe']->update(['default_production_location_id' => $fixture['location']->id]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->assertSet('productionLocationId', (string) $fixture['location']->id)
        ->set('productionLocationId', '')
        ->assertSet('productionLocationId', '');
});

it('saves and clears the selected product default from the create page', function (): void {
    $fixture = productionLocationUiFixture(withRecipe: true);

    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('productionLocationId', (string) $fixture['location']->id)
        ->call('saveProductProductionLocation')
        ->assertHasNoErrors();

    expect($fixture['recipe']->fresh()->default_production_location_id)->toBe($fixture['location']->id);

    $page->set('productionLocationId', '')
        ->call('saveProductProductionLocation')
        ->assertHasNoErrors();

    expect($fixture['recipe']->fresh()->default_production_location_id)->toBeNull();
});

it('shows assigned locations and filters the production index within the workspace', function (): void {
    $fixture = productionLocationUiFixture();
    $otherLocation = ProductionLocation::factory()->for($fixture['workspace'])->create([
        'name' => 'Secondary lab',
    ]);
    $otherProduction = ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-22',
        'production_location_id' => $otherLocation->id,
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertSee($fixture['location']->name);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->set('locationFilter', $otherLocation->public_id)
        ->assertSee($otherProduction->displayIdentifier())
        ->assertDontSee($fixture['production']->displayIdentifier());
});

it('clears a stale location filter when production locations are disabled', function (): void {
    $fixture = productionLocationUiFixture();
    $fixture['workspace']->update(['uses_production_locations' => false]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionIndex::class)
        ->set('locationFilter', $fixture['location']->public_id)
        ->assertSet('locationFilter', '');
});

it('rejects assigning a production location from another workspace', function (): void {
    $fixture = productionLocationUiFixture();
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create([
        'uses_production_locations' => true,
    ]);
    $foreignLocation = ProductionLocation::factory()->for($foreignWorkspace)->create();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->set('productionLocationId', (string) $foreignLocation->id)
        ->call('assignProductionLocation')
        ->assertHasErrors('productionLocationId');

    expect($fixture['production']->fresh()->production_location_id)->toBe($fixture['location']->id);
});

it('shows a non-blocking daily capacity warning for manual planning', function (): void {
    $fixture = productionLocationUiFixture(withRecipe: true, dailyLimit: 1);
    ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
    ]);
    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('plannedFor', '2026-09-21')
        ->assertSet('plannedFor', '2026-09-21')
        ->assertSee('3 / 1');
});

it('warns when the selected production location is full even if the overall limit remains', function (): void {
    $fixture = productionLocationUiFixture(withRecipe: true, dailyLimit: 3);
    $fixture['location']->update(['daily_production_limit' => 1]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('productionLocationId', (string) $fixture['location']->id)
        ->set('plannedFor', '2026-09-21')
        ->assertSee('Main mixing room')
        ->assertSee('2 / 1');
});

it('allows a manual reassignment that exceeds a location limit', function (): void {
    $fixture = productionLocationUiFixture(dailyLimit: 1);
    $otherLocation = ProductionLocation::factory()->for($fixture['workspace'])->create([
        'name' => 'Secondary lab',
        'daily_production_limit' => 1,
    ]);
    ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
        'production_location_id' => $otherLocation->id,
    ]);

    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->set('productionLocationId', (string) $otherLocation->id)
        ->assertSee('Secondary lab')
        ->assertSee('2 / 1')
        ->call('assignProductionLocation')
        ->assertHasNoErrors();

    expect($fixture['production']->fresh()->production_location_id)->toBe($otherLocation->id)
        ->and($page->get('productionLocationId'))->toBe((string) $otherLocation->id);
});

it('allows a manual reschedule that exceeds the overall limit', function (bool $locationsEnabled): void {
    $fixture = productionLocationUiFixture(dailyLimit: 1);
    $fixture['workspace']->update(['uses_production_locations' => $locationsEnabled]);
    $fixture['production']->update(['planned_for' => '2026-09-20']);
    ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
    ]);

    $page = Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $fixture['production']->id])
        ->assertSee('wire:click="rescheduleProduction"', escape: false)
        ->set('scheduleDate', null)->assertSet('scheduleDate', '')
        ->set('scheduleDate', '2026-09-21 00:00:00')->assertSet('scheduleDate', '2026-09-21')
        ->assertSee('2 / 1')
        ->call('rescheduleProduction')
        ->assertHasNoErrors();

    expect($fixture['production']->fresh()->planned_for->format('Y-m-d'))->toBe('2026-09-21')
        ->and($page->get('scheduleDate'))->toBe('2026-09-21');
})->with([true, false]);

/**
 * @return array{owner: User, workspace: Workspace, location: ProductionLocation, production: ProductionRun, recipe?: Recipe}
 */
function productionLocationUiFixture(bool $withRecipe = false, int $dailyLimit = 2): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'uses_production_locations' => true,
        'production_daily_limit' => $dailyLimit,
    ]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $location = ProductionLocation::factory()->for($workspace)->create([
        'name' => 'Main mixing room',
        'daily_production_limit' => $dailyLimit,
    ]);
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
        'production_location_id' => $location->id,
    ]);

    $fixture = compact('owner', 'workspace', 'location', 'production');

    if (! $withRecipe) {
        return $fixture;
    }

    $family = ProductFamily::factory()->create([
        'slug' => 'ui-family-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'total_formula',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'UI product',
    ]);
    RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => false,
    ]);

    return [...$fixture, 'recipe' => $recipe];
}
