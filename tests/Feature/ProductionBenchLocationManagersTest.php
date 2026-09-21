<?php

use App\Actions\Inventory\SaveStorageLocation;
use App\Actions\Production\SaveProductionLocation;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\PlanningPreferences;
use App\Livewire\ProductionBench\Production\ProductionLocationManager;
use App\Livewire\ProductionBench\Production\StorageLocationManager;
use App\Models\ProductionLocation;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('nests each location manager only after its persisted setting is enabled', function (): void {
    $fixture = locationManagerFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->assertDontSee(__('locations.production_locations.title'))
        ->assertDontSee(__('locations.storage_locations.title'))
        ->set('data.uses_production_locations', true)
        ->set('data.uses_storage_locations', false)
        ->assertDontSee(__('locations.production_locations.title'))
        ->assertDontSee(__('locations.storage_locations.title'))
        ->call('save')
        ->assertSee(__('locations.production_locations.title'))
        ->assertDontSee(__('locations.storage_locations.title'))
        ->set('data.uses_production_locations', false)
        ->set('data.uses_storage_locations', true)
        ->call('save')
        ->assertDontSee(__('locations.production_locations.title'))
        ->assertSee(__('locations.storage_locations.title'));

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeTrue();
});

it('creates, edits, archives, and restores a production location through public ids', function (): void {
    $fixture = locationManagerFixture(usesProductionLocations: true);

    $component = Livewire::actingAs($fixture['owner'])
        ->test(ProductionLocationManager::class)
        ->fillForm([
            'name' => '  Mixing   room  ',
            'daily_production_limit' => '4',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $location = ProductionLocation::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    expect($location->name)->toBe('Mixing room')
        ->and($location->daily_production_limit)->toBe(4)
        ->and($location->is_active)->toBeTrue();

    $component
        ->call('edit', $location->public_id)
        ->assertSet('editingLocationPublicId', $location->public_id)
        ->assertSet('data.name', 'Mixing room')
        ->fillForm([
            'name' => 'Finished goods room',
            'daily_production_limit' => '7',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->call('archive', $location->public_id)
        ->assertHasNoErrors();

    expect($location->fresh()->name)->toBe('Finished goods room')
        ->and($location->fresh()->daily_production_limit)->toBe(7)
        ->and($location->fresh()->is_active)->toBeFalse();

    $component
        ->call('restore', $location->public_id)
        ->assertHasNoErrors();

    expect($location->fresh()->is_active)->toBeTrue();
});

it('scopes production location lists and edits to the current workspace', function (): void {
    $fixture = locationManagerFixture(usesProductionLocations: true);
    $foreignWorkspace = Workspace::factory()->create();
    $foreign = ProductionLocation::factory()->for($foreignWorkspace)->create(['name' => 'Foreign room']);
    $local = ProductionLocation::factory()->for($fixture['workspace'])->create(['name' => 'Local room']);

    $component = Livewire::actingAs($fixture['owner'])->test(ProductionLocationManager::class);

    $component
        ->assertSee('Local room')
        ->assertDontSee('Foreign room')
        ->assertSeeHtml('wire:click="edit(\''.$local->public_id.'\')"')
        ->assertDontSeeHtml('wire:click="edit('.$local->id.')"');

    expect(fn () => $component->call('edit', $foreign->public_id))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects direct production manager writes while the feature is disabled', function (): void {
    $fixture = locationManagerFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionLocationManager::class)
        ->fillForm([
            'name' => 'Hidden room',
            'daily_production_limit' => '2',
        ])
        ->call('save')
        ->assertHasErrors('data.location');

    expect(ProductionLocation::query()->where('workspace_id', $fixture['workspace']->id)->count())->toBe(0);
});

it('creates, edits, archives, and restores a storage location through public ids', function (): void {
    $fixture = locationManagerFixture(usesStorageLocations: true);

    $component = Livewire::actingAs($fixture['owner'])
        ->test(StorageLocationManager::class)
        ->fillForm([
            'name' => 'Oil shelf',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $location = StorageLocation::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();

    $component
        ->call('edit', $location->public_id)
        ->fillForm([
            'name' => 'Cold cupboard',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->call('archive', $location->public_id)
        ->assertHasNoErrors();

    expect($location->fresh()->name)->toBe('Cold cupboard')
        ->and($location->fresh()->is_active)->toBeFalse();

    $component
        ->call('restore', $location->public_id)
        ->assertHasNoErrors();

    expect($location->fresh()->is_active)->toBeTrue();
});

it('scopes storage location lists and escapes stored names', function (): void {
    $fixture = locationManagerFixture(usesStorageLocations: true);
    $foreignWorkspace = Workspace::factory()->create();
    StorageLocation::factory()->for($foreignWorkspace)->create(['name' => 'Foreign shelf']);
    StorageLocation::factory()->for($fixture['workspace'])->create(['name' => '<b>Oil shelf</b>']);

    Livewire::actingAs($fixture['owner'])
        ->test(StorageLocationManager::class)
        ->assertSee('Oil shelf')
        ->assertDontSee('Foreign shelf')
        ->assertSeeHtml('&lt;b&gt;Oil shelf&lt;/b&gt;')
        ->assertDontSeeHtml('<b>Oil shelf</b>')
        ->assertDontSeeHtml('wire:click="delete');
});

it('uses the location policies for editor and viewer mutation access', function (): void {
    $fixture = locationManagerFixture(usesStorageLocations: true);
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);

    Livewire::actingAs($editor)
        ->test(StorageLocationManager::class)
        ->fillForm(['name' => 'Editor shelf'])
        ->call('save')
        ->assertHasNoFormErrors();

    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);

    Livewire::actingAs($viewer)
        ->test(StorageLocationManager::class)
        ->assertSeeHtml('<fieldset disabled>')
        ->fillForm(['name' => 'Viewer shelf'])
        ->call('save')
        ->assertForbidden();

    expect(StorageLocation::query()->where('workspace_id', $fixture['workspace']->id)->where('name', 'Viewer shelf')->exists())->toBeFalse();
});

/** @return array{owner: User, workspace: Workspace} */
function locationManagerFixture(
    bool $usesProductionLocations = false,
    bool $usesStorageLocations = false,
): array {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'uses_production_locations' => $usesProductionLocations,
        'uses_storage_locations' => $usesStorageLocations,
    ]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    return compact('owner', 'workspace');
}

it('limits location names to fifty characters in forms and save actions', function (string $manager, string $action, string $model): void {
    $fixture = locationManagerFixture(usesProductionLocations: true, usesStorageLocations: true);
    $this->actingAs($fixture['owner']);

    Livewire::test($manager)
        ->fillForm(['name' => str_repeat('é', 51)])
        ->call('save')
        ->assertHasFormErrors(['name' => 'max']);

    $arguments = [
        'actor' => $fixture['owner'],
        'workspace' => $fixture['workspace'],
        'name' => str_repeat('é', 51),
    ];
    if ($manager === ProductionLocationManager::class) {
        $arguments['dailyProductionLimit'] = 1;
    }

    expect(fn () => app($action)->handle(...$arguments))->toThrow(ValidationException::class);

    expect($model::query()->count())->toBe(0);

    Livewire::test($manager)
        ->fillForm(['name' => str_repeat('é', 50)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($model::query()->sole()->name)->toBe(str_repeat('é', 50));
})->with([
    'storage' => [StorageLocationManager::class, SaveStorageLocation::class, StorageLocation::class],
    'production' => [ProductionLocationManager::class, SaveProductionLocation::class, ProductionLocation::class],
]);
