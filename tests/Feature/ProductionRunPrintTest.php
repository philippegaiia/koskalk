<?php

use App\Actions\Production\AbortProduction;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CompleteProduction;
use App\Enums\ProductionFormulaComponent;
use App\Enums\ProductionRunStatus;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\ProductionConsumption;
use App\Models\ProductionFormulaLine;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\WorkspaceProductionEntitlement;
use App\Services\Production\ProductionRunPrintDataBuilder;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ProductionEditingFixture;

uses(RefreshDatabase::class);

it('groups the batch sheet like a formula with reservations beside each material and separate packaging', function (string $locale, string $mass, string $percentage): void {
    $fixture = ProductionEditingFixture::create();
    $fixture->owner->update(['number_locale' => $locale]);
    $run = ProductionRun::factory()->for($fixture->workspace)->create([
        'recipe_name_snapshot' => 'Grouped soap',
        'source_formula_version_number' => 15,
        'formula_context_snapshot' => ['calculation_basis' => 'initial_oils'],
    ]);
    $oil = ProductionRequirement::factory()->for($run, 'productionRun')->create([
        'subject_name_snapshot' => 'Frozen oil',
        'phase_key_snapshot' => 'saponified_oils',
        'phase_name_snapshot' => 'Oils',
    ]);
    foreach ([
        ['fragrance', 'Fragrances', 'Fragrance', ProductionFormulaComponent::Ingredient, 1],
        ['additives', 'Additives', 'Colourant', ProductionFormulaComponent::Ingredient, 2],
        ['lye_water', 'Lye and liquids', 'Water', ProductionFormulaComponent::Water, 3],
        ['saponified_oils', 'Oils', 'Frozen oil', ProductionFormulaComponent::Ingredient, 4],
    ] as [$key, $label, $name, $component, $order]) {
        ProductionFormulaLine::factory()->for($run, 'productionRun')->create([
            'ingredient_id' => $name === 'Frozen oil' ? $oil->ingredient_id : null,
            'component' => $component,
            'subject_name_snapshot' => $name,
            'phase_key_snapshot' => $key,
            'phase_name_snapshot' => $label,
            'planned_mass_grams' => '123.456789123',
            'basis_percentage_snapshot' => '10.126789123',
            'sort_order' => $order,
        ]);
    }
    $box = PackagingItem::factory()->for($fixture->workspace)->create();
    $packaging = ProductionRequirement::factory()->for($run, 'productionRun')->forPackaging($box)->create([
        'subject_name_snapshot' => 'Frozen box', 'required_units' => 12,
    ]);
    foreach ([$oil, $oil, $packaging] as $index => $requirement) {
        $lotFactory = StockLot::factory()->for($fixture->workspace);
        $lot = ($index === 2 ? $lotFactory->forPackaging() : $lotFactory)->create([
            'internal_lot_code' => 'RESERVED-'.$index,
            ...($index === 2 ? ['packaging_item_id' => $box->id] : ['ingredient_id' => $oil->ingredient_id]),
        ]);
        StockReservation::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create([
            'workspace_id' => $fixture->workspace->id,
            'quantity' => $index === 2 ? '12' : '61.728394561',
        ]);
    }
    StockReservation::factory()->released()->for($run, 'productionRun')->for($packaging, 'productionRequirement')->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture->workspace->id, 'quantity' => '999',
    ]);

    $response = $this->actingAs($fixture->owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $fixture->workspace->public_id]));
    $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    $formula = $document->querySelector('[data-batch-material-section="formula"]');
    $packagingTable = $document->querySelector('[data-batch-material-section="packaging"]');

    $response->assertDontSee('Source formula version')->assertDontSee('123.456789123')->assertDontSee('10.126789123');
    expect(collect($formula->querySelectorAll('[data-batch-material-group]'))->map(fn ($row): string => $row->getAttribute('data-batch-material-group'))->all())
        ->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance']);
    expect($formula->querySelector('[data-batch-material-row] [data-batch-lots]')->textContent)->toContain('RESERVED-0', 'RESERVED-1');
    $internalLot = $formula->querySelector('[data-internal-lot]');
    $supplierBatch = $formula->querySelector('[data-supplier-batch]');
    expect($internalLot->textContent)->toBe('Internal: RESERVED-0');
    expect($internalLot->classList->contains('font-semibold'))->toBeTrue();
    expect($supplierBatch->textContent)->toContain('Supplier:');
    expect($supplierBatch->classList->contains('font-semibold'))->toBeFalse();
    expect($supplierBatch->parentNode)->toBe($internalLot->parentNode);
    expect($internalLot->parentNode->classList->contains('flex'))->toBeTrue();
    expect($internalLot->tagName)->toBe('SPAN');
    expect($supplierBatch->tagName)->toBe('SPAN');
    expect($formula->textContent)->not->toContain('Frozen box', 'RESERVED-2');
    expect($formula->querySelector('[data-batch-planned]')->textContent)->toBe($mass);
    expect($formula->querySelector('[data-batch-percentage]')->textContent)->toBe($percentage);
    expect($formula->querySelector('[data-actual]')->textContent)->toBe('');
    expect($packagingTable->textContent)->toContain('Frozen box', 'RESERVED-2');
    expect($packagingTable->querySelector('[data-batch-lots]')->textContent)->not->toContain('999');
    expect($packagingTable->querySelector('[data-batch-planned]')->textContent)->toBe('12');
    expect($packagingTable->querySelector('[data-batch-percentage]'))->toBeNull();
    expect($packagingTable->querySelector('[data-actual]'))->toBeNull();
    expect($packagingTable->querySelectorAll('thead th'))->toHaveCount(4);
    expect($packagingTable->textContent)->toContain('Planned (units)');
    expect($packagingTable->textContent)->not->toContain('Recorded actual', 'production_bench.');
    expect($document->querySelectorAll('[data-formula-document-table]'))->toHaveCount(2);
})->with([
    'decimal point' => ['en_US', '123.4568', '10.13%'],
    'decimal comma' => ['fr_FR', '123,4568', '10,13%'],
]);

it('preserves authored cosmetic phases and legacy ingredient requirements in the printed formula', function (): void {
    $run = ProductionRun::factory()->create(['formula_context_snapshot' => ['calculation_basis' => 'total_formula']]);
    foreach (['phase_b', 'phase_a'] as $index => $phase) {
        ProductionFormulaLine::factory()->for($run, 'productionRun')->create([
            'phase_key_snapshot' => $phase, 'phase_name_snapshot' => 'Captured '.$phase, 'sort_order' => $index + 1,
        ]);
    }
    ProductionRequirement::factory()->for($run, 'productionRun')->create([
        'subject_name_snapshot' => 'Legacy ingredient', 'phase_key_snapshot' => 'legacy', 'phase_name_snapshot' => 'Historical phase',
    ]);

    $data = app(ProductionRunPrintDataBuilder::class)->build($run->fresh());

    expect(array_column($data['sections'], 'key'))->toBe(['phase_b', 'phase_a', 'legacy']);
    expect($data['sections'][2]['rows'][0]['name'])->toBe('Legacy ingredient');
    expect($data['sections'][2]['label'])->toBe('Historical phase');
});

it('prints frozen planned formula and reservations without inventing actual quantities', function (string $basis): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'recipe_name_snapshot' => 'Frozen formula',
        'formula_context_snapshot' => ['calculation_basis' => $basis, 'manufacturing_procedure_html' => '<p>Mix <strong>slowly</strong>.</p>'],
    ]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create(['subject_name_snapshot' => 'Frozen oil', 'material_code_snapshot' => 'OIL-01']);
    ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['ingredient_id' => $requirement->ingredient_id, 'subject_name_snapshot' => 'Frozen oil']);
    if ($basis === 'initial_oils') {
        ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['component' => ProductionFormulaComponent::Water, 'subject_name_snapshot' => 'Water', 'planned_mass_grams' => '38', 'sort_order' => 2]);
    }
    $lot = StockLot::factory()->for($workspace)->create(['internal_lot_code' => 'RESERVED-LOT']);
    StockReservation::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))
        ->assertOk()->assertSee('Frozen formula')->assertSee('OIL-01')->assertSee('RESERVED-LOT')->assertSee('<strong>slowly</strong>', false);
    $data = app(ProductionRunPrintDataBuilder::class)->build($run->fresh());
    expect(collect($data['materials'])->pluck('actual')->filter())->toBeEmpty();
})->with(['initial_oils', 'total_formula']);

it('keeps legacy terminal records independent of current lot identity and missing source formula', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Completed, 'recipe_name_snapshot' => 'Historic formula']);
    $lot = StockLot::factory()->for($workspace)->create(['internal_lot_code' => 'DO-NOT-PRINT']);
    ProductionConsumption::factory()->for($run, 'productionRun')->for($lot, 'stockLot')->create(['subject_name_snapshot' => 'Historic oil']);
    $run->recipe->delete();
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))
        ->assertOk()->assertSee('Historic formula')->assertSee('Historic oil')->assertDontSee('DO-NOT-PRINT')->assertDontSee('Lot ID');
    $before = app(ProductionRunPrintDataBuilder::class)->build($run->fresh());
    $lot->update(['internal_lot_code' => 'CHANGED', 'supplier_batch_number' => 'CHANGED']);
    expect(app(ProductionRunPrintDataBuilder::class)->build($run->fresh()))->toBe($before);
});

it('rejects cancelled runs and another workspace run', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Cancelled]);
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))->assertStatus(409);
    $other = ProductionRun::factory()->create();
    $this->get(route('production-bench.production.print', ['productionRun' => $other, 'workspace' => $workspace->public_id]))->assertNotFound();
});

it('freezes manufacturing lot evidence on completion or abort and never rewrites it during printing', function (bool $aborted): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'recipe_name_snapshot' => 'Original formula',
        'formula_context_snapshot' => ['manufacturing_procedure_html' => '<p>Original procedure</p>'],
        'notes' => 'Manufacturing note',
    ]);
    app(AssignProductionBatchNumbers::class)->handle($owner, $workspace, [$run->id], editing: ProductionEditingFixture::command($owner, [$run->id]));
    $run->update(['status' => ProductionRunStatus::InProduction]);
    $requirement = ProductionRequirement::factory()->for($run, 'productionRun')->create(['subject_name_snapshot' => 'Original material']);
    ProductionFormulaLine::factory()->for($run, 'productionRun')->create(['ingredient_id' => $requirement->ingredient_id, 'subject_name_snapshot' => 'Original material']);
    $lot = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => $requirement->ingredient_id,
        'internal_lot_code' => 'ORIGINAL-LOT',
        'supplier_batch_number' => 'SUPPLIER-001',
        'expires_at' => '2028-01-01',
    ]);
    ProductionConsumption::factory()->for($run, 'productionRun')->for($requirement, 'productionRequirement')->for($lot, 'stockLot')->create(['subject_name_snapshot' => 'Original material', 'quantity' => '99', 'note' => 'Weighed twice']);
    if ($aborted) {
        app(AbortProduction::class)->handle($owner, $run, 'Mixing stopped', editing: ProductionEditingFixture::command($owner, [$run->id]));
    } else {
        app(CompleteProduction::class)->handle($owner, $run, '9', '2026-09-28', editing: ProductionEditingFixture::command($owner, [$run->id]));
    }
    $builder = app(ProductionRunPrintDataBuilder::class);
    $before = $builder->build($run->fresh());
    expect($before['consumption'][0])->toMatchArray(['lot' => 'ORIGINAL-LOT', 'supplier_batch' => 'SUPPLIER-001', 'expiry' => '2028-01-01', 'quantity' => '99.000000000']);
    $lot->update(['internal_lot_code' => 'EDITED-LOT', 'supplier_batch_number' => 'EDITED-BATCH', 'expires_at' => '2030-01-01', 'historical_unit_cost' => '999']);
    $run->recipe->update(['name' => 'EDITED-FORMULA']);
    $run->recipeVersion->update(['manufacturing_instructions' => '<p>EDITED-PROCEDURE</p>']);
    $requirement->ingredient->update(['display_name' => 'EDITED-MATERIAL']);
    expect($builder->build($run->fresh()))->toBe($before);
    $attributes = $run->fresh()->getRawOriginal();
    $response = $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]));
    $response->assertOk()->assertSee('Original formula')->assertSee('ORIGINAL-LOT')->assertSee('Weighed twice')->assertDontSee('EDITED-')->assertSee('Batch record');
    $printed = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    expect($printed->querySelector('[data-batch-lots]')->textContent)->toContain('ORIGINAL-LOT', 'SUPPLIER-001', 'Weighed twice');
    $response->assertDontSee('Expiry')->assertDontSee('2028-01-01');
    expect($printed->querySelector('[data-actual]')->textContent)->toBe('99.00');
    expect($printed->querySelectorAll('[data-formula-document-table]'))->toHaveCount(1);
    if ($aborted) {
        $response->assertSee('Mixing stopped')->assertSee('Aborted');
    }
    expect($run->fresh()->getRawOriginal())->toBe($attributes);
})->with([false, true]);

it('allows a viewer to print after bench cancellation and rejects revoked access', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    WorkspaceProductionEntitlement::factory()->for($workspace)->create(['status' => 'cancelled']);
    $run = ProductionRun::factory()->for($workspace)->create();
    $url = route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]);
    $this->actingAs($viewer)->get($url)->assertOk();
    Livewire::actingAs($viewer)->test(ProductionDetail::class, ['productionId' => $run->public_id])->assertSee('Print batch sheet');
    $membership->delete();
    $this->get($url)->assertForbidden();
});

it('rejects a print link after switching the selected workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $other = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create();
    $owner->forceFill(['active_workspace_id' => $other->id])->save();
    $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]))->assertStatus(409);
});

it('retains captured lot identity when a historical lot reference has been deleted', function (): void {
    $run = ProductionRun::factory()->create(['status' => ProductionRunStatus::Completed]);
    $lot = StockLot::factory()->for($run->workspace)->create();
    $row = ProductionConsumption::factory()->for($run, 'productionRun')->for($lot, 'stockLot')->create();
    $run->update(['formula_context_snapshot' => ['execution_lots' => [$row->id => [
        'stock_lot_id' => $lot->id,
        'internal_lot_code' => $lot->internal_lot_code,
        'supplier_batch_number' => $lot->supplier_batch_number,
        'expires_at' => $lot->expires_at?->toDateString(),
    ]]]]);
    $builder = app(ProductionRunPrintDataBuilder::class);
    $before = $builder->build($run->fresh());
    $lot->delete();
    expect($row->fresh()->stock_lot_id)->toBeNull()
        ->and($builder->build($run->fresh()))->toBe($before);
});

it('distinguishes an explicitly empty captured procedure from unavailable legacy history', function (bool $captured): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $run = ProductionRun::factory()->for($workspace)->create([
        'formula_context_snapshot' => $captured ? ['manufacturing_procedure_html' => ''] : [],
    ]);
    $response = $this->actingAs($owner)->get(route('production-bench.production.print', ['productionRun' => $run, 'workspace' => $workspace->public_id]));
    $response->assertOk();
    if ($captured) {
        $response->assertSee('No procedure was supplied.');
    } else {
        $response->assertDontSee('No procedure was supplied.');
    }
    expect($run->fresh()->formula_context_snapshot)->toBe($captured ? ['manufacturing_procedure_html' => ''] : []);
})->with([false, true]);
