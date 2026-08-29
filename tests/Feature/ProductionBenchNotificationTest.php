<?php

use App\Livewire\ProductionBench\Production\SettingsIndex;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dispatches setup saves through the shared app notification', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();

    Livewire::actingAs($owner)
        ->test(SettingsIndex::class, ['section' => 'employees'])
        ->set('employeeFirstName', 'Ana')
        ->set('employeeLastName', 'Maker')
        ->call('saveEmployee')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', __('production_bench.settings.saved'))
        ->assertDispatched('app-notification', function (string $event, array $payload): bool {
            return $event === 'app-notification'
                && $payload['message'] === __('production_bench.settings.saved')
                && $payload['type'] === 'success';
        });
});

it('does not render production setup redirect statuses as permanent page banners', function (): void {
    $batchSizeView = file_get_contents(resource_path('views/livewire/production-bench/production/batch-size-index.blade.php'));
    $taskSetView = file_get_contents(resource_path('views/livewire/production-bench/production/task-set-index.blade.php'));

    expect($batchSizeView)->not->toContain("@if (session('status'))")
        ->and($taskSetView)->not->toContain("@if (session('status'))");
});
