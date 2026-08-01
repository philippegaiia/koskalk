<?php

use App\Livewire\ProductionBench\Purchasing\SupplierCreate;
use App\Livewire\ProductionBench\Purchasing\SupplierEdit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('protects dedicated supplier form routes', function (): void {
    $supplier = Supplier::factory()->create();

    $this->get(route('production-bench.purchasing.suppliers.create'))
        ->assertRedirect(route('login'));
    $this->get(route('production-bench.purchasing.suppliers.edit', $supplier))
        ->assertRedirect(route('login'));
});

it('shows the focused new supplier form to active workspaces', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.suppliers.create'))
        ->assertOk()
        ->assertSee('New supplier')
        ->assertSee('Supplier')
        ->assertSee('Main contact')
        ->assertSee('Address')
        ->assertSee('Notes')
        ->assertSee('Save supplier')
        ->assertSee('Cancel')
        ->assertSeeHtml('class="fi-section')
        ->assertSeeHtml('wire:model="data.code"')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.suppliers').'"')
        ->assertSeeHtml('maxlength="16"');

    expect($workspace->exists)->toBeTrue()
        ->and(Supplier::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

it('creates a supplier with structured fields and opens its detail page', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $this->actingAs($owner);

    Livewire::test(SupplierCreate::class)
        ->fillForm([
            'code' => ' oil_fr_01 ',
            'name' => 'French Oils',
            'is_active' => true,
            'default_currency' => 'EUR',
            'contact_name' => 'Marie Dupont',
            'email' => 'marie@french-oils.example',
            'phone' => '+33 1 22 33 44 55',
            'website' => 'https://french-oils.example',
            'address_line_1' => '12 rue des Huiles',
            'address_line_2' => 'Bâtiment B',
            'city' => 'Marseille',
            'region' => 'Provence',
            'postal_code' => '13001',
            'country_code' => 'fr',
            'notes' => 'Main oil supplier.',
        ])
        ->call('save')
        ->assertHasNoErrors();

    $supplier = Supplier::query()
        ->where('workspace_id', $workspace->id)
        ->where('code', 'OIL_FR_01')
        ->firstOrFail();

    expect($supplier)->toMatchArray([
        'name' => 'French Oils',
        'contact_name' => 'Marie Dupont',
        'email' => 'marie@french-oils.example',
        'phone' => '+33 1 22 33 44 55',
        'website' => 'https://french-oils.example',
        'address_line_1' => '12 rue des Huiles',
        'address_line_2' => 'Bâtiment B',
        'city' => 'Marseille',
        'region' => 'Provence',
        'postal_code' => '13001',
        'country_code' => 'FR',
        'default_currency' => 'EUR',
        'notes' => 'Main oil supplier.',
        'is_active' => true,
    ]);

    Livewire::test(SupplierCreate::class)
        ->fillForm(['code' => 'SECOND', 'name' => 'Second supplier'])
        ->call('save')
        ->assertRedirect(route('production-bench.purchasing.supplier', Supplier::query()->where('code', 'SECOND')->firstOrFail()));
});

it('edits a workspace supplier without changing its public id', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create([
        'code' => 'OLD_CODE',
        'name' => 'Old name',
        'city' => 'Lyon',
    ]);
    $publicId = $supplier->public_id;
    $this->actingAs($owner);

    $this->get(route('production-bench.purchasing.suppliers.edit', $supplier))
        ->assertOk()
        ->assertSee('Edit supplier')
        ->assertSee('OLD_CODE')
        ->assertSee('Old name')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.supplier', $supplier).'"');

    expect($supplier->fresh())
        ->code->toBe('OLD_CODE')
        ->name->toBe('Old name')
        ->city->toBe('Lyon');

    Livewire::test(SupplierEdit::class, ['supplier' => $supplier->public_id])
        ->fillForm(['code' => 'new-code', 'name' => 'New name', 'city' => 'Paris'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.supplier', $supplier));

    expect($supplier->fresh())
        ->public_id->toBe($publicId)
        ->code->toBe('NEW-CODE')
        ->name->toBe('New name')
        ->city->toBe('Paris');
});

it('keeps supplier form routes inside the current workspace', function (): void {
    [$owner] = supplierFormWorkspace();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create();

    $this->actingAs($owner)
        ->get(route('production-bench.purchasing.suppliers.edit', $foreignSupplier))
        ->assertNotFound();
});

it('rejects invalid supplier form values on their fields', function (): void {
    [$owner] = supplierFormWorkspace();
    $this->actingAs($owner);

    Livewire::test(SupplierCreate::class)
        ->fillForm([
            'code' => 'invalid code that is too long',
            'name' => '',
            'email' => 'not-an-email',
            'website' => 'not-a-url',
            'country_code' => 'France',
        ])
        ->call('save')
        ->assertHasFormErrors(['code', 'name', 'email', 'website', 'country_code']);
});

it('rejects unsupported currencies and non-http supplier websites', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(SupplierCreate::class)
        ->fillForm(['code' => 'UNSAFE', 'name' => 'Unsafe supplier', 'default_currency' => 'ZZZ', 'website' => 'data:text/html,unsafe'])
        ->call('save')
        ->assertHasFormErrors(['default_currency', 'website']);

    Livewire::test(SupplierEdit::class, ['supplier' => $supplier->public_id])
        ->fillForm(['default_currency' => 'ZZZ', 'website' => 'smb://files.example/supplier'])
        ->call('save')
        ->assertHasFormErrors(['default_currency', 'website']);

    expect(Supplier::query()->where('code', 'UNSAFE')->exists())->toBeFalse()
        ->and($supplier->fresh()?->default_currency)->not->toBe('ZZZ')
        ->and($supplier->fresh()?->website)->not->toBe('smb://files.example/supplier');
});

it('preserves an unchanged historical currency while editing other supplier fields', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create([
        'default_currency' => 'HRK',
        'city' => 'Zagreb',
    ]);
    $this->actingAs($owner);

    Livewire::test(SupplierEdit::class, ['supplier' => $supplier->public_id])
        ->assertSchemaStateSet(['default_currency' => 'HRK'])
        ->fillForm(['city' => 'Split'])
        ->call('save')
        ->assertHasNoErrors();

    expect($supplier->fresh()?->default_currency)->toBe('HRK')
        ->and($supplier->fresh()?->city)->toBe('Split');
});

it('does not let a historical supplier currency be replaced by another unavailable code', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'HRK']);
    $this->actingAs($owner);

    Livewire::test(SupplierEdit::class, ['supplier' => $supplier->public_id])
        ->fillForm(['default_currency' => 'ZWL'])
        ->call('save')
        ->assertHasFormErrors(['default_currency']);

    expect($supplier->fresh()?->default_currency)->toBe('HRK');
});

it('does not open supplier mutation pages for inactive or read-only workspaces', function (): void {
    $inactiveOwner = User::factory()->create();
    $inactiveWorkspace = Workspace::factory()->for($inactiveOwner, 'owner')->create();
    $inactiveSupplier = Supplier::factory()->for($inactiveWorkspace)->create();

    $this->actingAs($inactiveOwner)
        ->get(route('production-bench.purchasing.suppliers.create'))
        ->assertForbidden();
    $this->get(route('production-bench.purchasing.suppliers.edit', $inactiveSupplier))
        ->assertForbidden();

    [$readOnlyOwner, $readOnlyWorkspace] = supplierFormWorkspace();
    $readOnlySupplier = Supplier::factory()->for($readOnlyWorkspace)->create();
    app(ProductionBenchAccess::class)->cancel($readOnlyOwner, $readOnlyWorkspace);

    $this->actingAs($readOnlyOwner)
        ->get(route('production-bench.purchasing.suppliers.create'))
        ->assertForbidden();
    $this->get(route('production-bench.purchasing.suppliers.edit', $readOnlySupplier))
        ->assertForbidden();
});

it('blocks a supplier save when the workspace becomes read only', function (): void {
    [$owner, $workspace] = supplierFormWorkspace();
    $this->actingAs($owner);
    $component = Livewire::test(SupplierCreate::class)
        ->fillForm(['code' => 'BLOCKED', 'name' => 'Blocked supplier']);

    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    $component->call('save')->assertHasErrors('data.production_bench');

    expect(Supplier::query()->where('workspace_id', $workspace->id)->where('code', 'BLOCKED')->exists())->toBeFalse();
});

/** @return array{0: User, 1: Workspace} */
function supplierFormWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}
