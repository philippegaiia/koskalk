<?php

use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\PlanningPreferences;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the planning preferences route with the exact labels and active settings leaf', function (): void {
    $fixture = planningPreferencesPageFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.planning'))
        ->assertOk()
        ->assertSee(__('locations.title'))
        ->assertSee(__('locations.daily_production_limit'))
        ->assertSee(__('locations.uses_production_locations'))
        ->assertSee(__('locations.uses_storage_locations'))
        ->assertSee(__('locations.planning_and_storage'))
        ->assertSeeHtml('aria-current="page"');
});

it('saves and reloads independent switches without persisting unsaved form changes', function (): void {
    $fixture = planningPreferencesPageFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->set('data.uses_production_locations', true)
        ->set('data.uses_storage_locations', false)
        ->set('data.production_daily_limit', '24')
        ->assertSeeHtml('aria-current="page"')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['message'] === __('locations.saved')
                && $payload['type'] === 'success';
        });

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeTrue()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->production_daily_limit)->toBe(24);

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->assertSet('data.uses_production_locations', true)
        ->assertSet('data.uses_storage_locations', false)
        ->assertSet('data.production_daily_limit', '24');
});

it('does not persist a switch until the preferences form is saved', function (): void {
    $fixture = planningPreferencesPageFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->set('data.uses_production_locations', true)
        ->set('data.uses_storage_locations', true)
        ->set('data.production_daily_limit', '9');

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->uses_storage_locations)->toBeFalse()
        ->and($fixture['workspace']->fresh()->production_daily_limit)->toBe(1);
});

it('keeps cancelled preferences read-only and refuses a save', function (): void {
    $fixture = planningPreferencesPageFixture(cancelled: true);

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.settings.planning'))
        ->assertOk()
        ->assertSee(__('locations.cancelled_read_only'))
        ->assertSeeHtml('disabled');

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->set('data.uses_production_locations', true)
        ->call('save')
        ->assertHasErrors('data.production_bench');

    expect($fixture['workspace']->fresh()->uses_production_locations)->toBeFalse();
});

it('keeps editors and viewers read-only while refusing their direct save', function (WorkspaceMemberRole $role): void {
    $fixture = planningPreferencesPageFixture();
    $member = User::factory()->create();
    WorkspaceMember::factory()->for($fixture['workspace'])->for($member)->create(['role' => $role]);

    Livewire::actingAs($member)
        ->test(PlanningPreferences::class)
        ->assertSee(__('locations.'.($role === WorkspaceMemberRole::Editor ? 'editor' : 'viewer').'_read_only'))
        ->assertSeeHtml('disabled')
        ->call('save')
        ->assertForbidden();
})->with([
    'editor' => WorkspaceMemberRole::Editor,
    'viewer' => WorkspaceMemberRole::Viewer,
]);

it('shows the validation message for a fractional daily limit', function (): void {
    $fixture = planningPreferencesPageFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(PlanningPreferences::class)
        ->set('data.production_daily_limit', '1.5')
        ->call('save')
        ->assertHasErrors('data.production_daily_limit')
        ->assertSee(__('locations.validation.production_daily_limit'));

    expect($fixture['workspace']->fresh()->production_daily_limit)->toBe(1);
});

/** @return array{owner: User, workspace: Workspace} */
function planningPreferencesPageFixture(bool $cancelled = false): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    if ($cancelled) {
        WorkspaceProductionEntitlement::factory()->for($workspace)->cancelled()->create();
    } else {
        WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    }

    return compact('owner', 'workspace');
}
