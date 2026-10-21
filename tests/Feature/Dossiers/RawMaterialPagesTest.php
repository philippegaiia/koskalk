<?php

use App\Livewire\Dossiers\QuickSupplierCreate;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function rawMaterialWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['default_currency' => 'EUR']);
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

it('creates a supplier from the DIP pages and announces it', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)
        ->fillForm(['code' => 'akemia', 'name' => 'Akemia', 'default_currency' => 'EUR', 'is_active' => true])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('dossier-supplier-created');

    expect(Supplier::query()->where('workspace_id', $workspace->id)->sole()->code)->toBe('AKEMIA');
});

it('refuses the quick supplier form without the DIP module', function (): void {
    $owner = User::factory()->create();
    Workspace::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(QuickSupplierCreate::class)->assertForbidden();
});
