<?php

use App\Enums\ProductionRunStatus;
use App\Livewire\ProductionBench\Production\ProductionCalendar;
use App\Models\ProductionLocation;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides location filters and labels when production locations are disabled', function (): void {
    $fixture = productionLocationCalendarUiFixture();
    $fixture['workspace']->update(['uses_production_locations' => false]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->set('locationFilter', $fixture['location']->public_id)
        ->assertSet('locationFilter', '')
        ->assertDontSee($fixture['location']->name)
        ->assertDontSee(__('locations.production_location'));
});

it('filters calendar production events and labels their assigned location when enabled', function (): void {
    $fixture = productionLocationCalendarUiFixture();
    $other = ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
    ]);

    $component = Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-09-01', '2026-10-01')
        ->set('locationFilter', $fixture['location']->public_id);
    $events = collect($component->instance()->events());

    expect($events->pluck('id')->all())->toContain('production-'.$fixture['production']->id)
        ->not->toContain('production-'.$other->id)
        ->and($events->firstWhere('id', 'production-'.$fixture['production']->id)['title'])
        ->toContain($fixture['location']->name);
});

it('ignores a foreign calendar location filter', function (): void {
    $fixture = productionLocationCalendarUiFixture();
    $foreignOwner = User::factory()->create();
    $foreignWorkspace = Workspace::factory()->for($foreignOwner, 'owner')->create([
        'uses_production_locations' => true,
    ]);
    $foreignLocation = ProductionLocation::factory()->for($foreignWorkspace)->create();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-09-01', '2026-10-01')
        ->set('locationFilter', $foreignLocation->public_id)
        ->assertSet('locationFilter', '')
        ->assertSee($fixture['production']->displayRecipeName());
});

/**
 * @return array{owner: User, workspace: Workspace, location: ProductionLocation, production: ProductionRun}
 */
function productionLocationCalendarUiFixture(int $dailyLimit = 2): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'uses_production_locations' => true,
        'production_daily_limit' => $dailyLimit,
    ]);
    $workspace->productionEntitlement()->create([
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $location = ProductionLocation::factory()->for($workspace)->create([
        'name' => 'Calendar mixing room',
        'daily_production_limit' => $dailyLimit,
    ]);
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-20',
        'production_location_id' => $location->id,
    ]);

    return compact('owner', 'workspace', 'location', 'production');
}
