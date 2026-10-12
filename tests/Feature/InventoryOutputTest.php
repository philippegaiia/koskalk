<?php

use App\Enums\MassDisplaySystem;
use App\Enums\ProductionRunStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockUnitKind;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Recipe;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
});

it('keeps stock output actions in the top toolbar outside both filter forms', function (string $mode, string $filter, string $value, string $menu, int $printButtons): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $lot = StockLot::factory()->for($workspace)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '1000']);

    $component = Livewire::actingAs($actor)->test(InventoryIndex::class, ['mode' => $mode])->set($filter, $value);
    $document = new DOMDocument;
    @$document->loadHTML($component->html());
    $xpath = new DOMXPath($document);
    $toolbar = $xpath->query('//*[@data-list-output-toolbar]')->item(0);
    $filterPanel = $xpath->query('//*[@data-production-bench-filters]')->item(0);

    expect($xpath->query('.//button[@aria-controls="'.$menu.'"]', $toolbar)->length)->toBe(1);
    expect($xpath->query('.//button[@*[name()="@click"]="printList()"]', $toolbar)->length)->toBe($printButtons);
    expect($xpath->query('.//*[@aria-haspopup="menu"]', $filterPanel)->length)->toBe(0);
    expect($xpath->query('.//button[@*[name()="@click"]="printList()"]', $filterPanel)->length)->toBe(0);
    expect(strpos($component->html(), 'data-list-output-toolbar'))->toBeLessThan(strpos($component->html(), 'data-production-bench-filters'));
    expect($toolbar->getAttribute('class'))->toContain('relative z-40');
})->with([
    'materials without filters' => ['materials', 'materialType', 'all', 'materials-export-menu', 0],
    'materials with filters' => ['materials', 'materialType', 'ingredient', 'materials-export-menu', 0],
    'lots without filters' => ['stock', 'lotStatus', 'all', 'stock-export-menu', 1],
    'lots with filters' => ['stock', 'lotStatus', 'released', 'stock-export-menu', 1],
]);

it('formats mass and count decimals in both inventory exports', function (string $routeName, string $firstHeader, string $massCell, string $countCell): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'A Oil']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Z Jar']);
    $massLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create(['internal_lot_code' => 'A-LOT']);
    $countLot = StockLot::factory()->for($workspace)->released()->create([
        'ingredient_id' => null, 'packaging_item_id' => $packaging->id,
        'unit_kind' => StockUnitKind::Count, 'internal_lot_code' => 'Z-LOT',
    ]);
    StockMovement::factory()->for($massLot, 'stockLot')->create(['quantity_delta' => '1234.56']);
    StockMovement::factory()->for($countLot, 'stockLot')->create(['quantity_delta' => '25']);
    $parameters = ['workspace' => $workspace->public_id, 'format' => 'csv', 'sort' => 'name', 'lotSort' => 'code'];

    $rows = inventoryOutputRows($this->actingAs($actor)->postJson(route($routeName), $parameters), 'csv', __($firstHeader));
    expect($rows[0][__('production_bench.output.columns.physical')])->toBe('1.23');
    expect($rows[1][__('production_bench.output.columns.physical')])->toBe('25');

    $xlsx = queuedExportDownload($this->postJson(route($routeName), [...$parameters, 'format' => 'xlsx']));
    expect(xlsxCellNumberFormat($xlsx->streamedContent(), $massCell))->toBe('0.00');
    expect(xlsxCellNumberFormat($xlsx->streamedContent(), $countCell))->toBe('0');
})->with([
    'material balances' => ['production-bench.inventory.materials.export', 'production_bench.output.columns.type', 'D9', 'D10'],
    'stock lots' => ['production-bench.inventory.stock-lots.export', 'production_bench.output.columns.material', 'K10', 'K11'],
]);

it('exports every matching material in sorted order with display quantities in the workspace unit', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create([
        'mass_display_system' => MassDisplaySystem::UsCustomary,
    ]);
    app(ProductionBenchAccess::class)->activate($actor, $workspace);

    foreach (range(0, 30) as $number) {
        $ingredient = Ingredient::factory()->create([
            'display_name' => sprintf('Output Oil %02d', $number),
        ]);
        $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'quantity_delta' => $number === 0 ? '453.592370000' : '1000.000000000',
        ]);
    }

    $response = $this->actingAs($actor)->postJson(route('production-bench.inventory.materials.export', [
        'workspace' => $workspace->public_id,
        'format' => 'csv',
        'materialType' => 'ingredient',
        'sort' => 'name',
        'direction' => 'asc',
    ]));

    $response = queuedExportDownload($response);
    $response->assertSuccessful()->assertDownload();
    $rows = collect(explode("\n", trim($response->streamedContent())))
        ->map(fn (string $line): array => str_getcsv($line));
    $headers = $rows->first();
    $materialColumn = array_search(__('production_bench.output.columns.material'), $headers, true);
    $physicalColumn = array_search(__('production_bench.output.columns.physical'), $headers, true);
    $unitColumn = array_search(__('production_bench.output.columns.unit'), $headers, true);

    expect($rows)->toHaveCount(32)
        ->and($rows->skip(1)->pluck($materialColumn)->all())
        ->toBe(collect(range(0, 30))->map(fn (int $number): string => sprintf('Output Oil %02d', $number))->all())
        ->and($rows->get(1)[$physicalColumn])->toBe('1.00')
        ->and($rows->get(1)[$unitColumn])->toBe('lb');
});

/** @return list<array<string, mixed>> */
function inventoryOutputRows(TestResponse $response, string $format, string $firstHeader): array
{
    $response = queuedExportDownload($response);
    $response->assertSuccessful()->assertDownload();
    if ($format === 'csv') {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);
        $headers = fgetcsv($stream);
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = array_combine($headers, $row);
        }
        fclose($stream);

        return $rows;
    }

    $path = tempnam(sys_get_temp_dir(), 'inventory-output-');
    try {
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $headers = null;
        $rows = [];
        foreach ($reader->getSheetIterator()->current()->getRowIterator() as $row) {
            $values = $row->toArray();
            if ($headers === null && ($values[0] ?? null) === $firstHeader) {
                $headers = $values;
            } elseif ($headers !== null) {
                $rows[] = array_combine($headers, array_pad($values, count($headers), null));
            }
        }
        $reader->close();

        return $rows;
    } finally {
        @unlink($path);
    }
}

it('exports independent material balances including negative forecast and packaging counts', function (string $format): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Balance Oil']);
    $released = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $quarantined = StockLot::factory()->for($workspace)->for($ingredient)->create();
    StockMovement::factory()->for($released, 'stockLot')->create(['quantity_delta' => '1000']);
    StockMovement::factory()->for($quarantined, 'stockLot')->create(['quantity_delta' => '500']);
    $production = ProductionRun::factory()->for($workspace)->create(['status' => ProductionRunStatus::Scheduled]);
    $requirement = ProductionRequirement::factory()->for($production)->for($ingredient)->create(['required_mass_grams' => '2000']);
    StockReservation::factory()->for($workspace)->for($production)->for($requirement)->for($released, 'stockLot')->create(['quantity' => '200']);
    $order = PurchaseOrder::factory()->for($workspace)->create(['status' => PurchaseOrderStatus::Ordered]);
    PurchaseOrderLine::factory()->for($order)->for($ingredient)->create(['ordered_packs' => 1, 'canonical_quantity_per_pack' => '300']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Z Bottles']);
    $packagingLot = StockLot::factory()->for($workspace)->released()->create(['ingredient_id' => null, 'packaging_item_id' => $packaging->id, 'unit_kind' => StockUnitKind::Count]);
    StockMovement::factory()->for($packagingLot, 'stockLot')->create(['quantity_delta' => '100']);

    $response = $this->actingAs($actor)->postJson(route('production-bench.inventory.materials.export', ['workspace' => $workspace->public_id, 'format' => $format, 'sort' => 'name']));
    $rows = inventoryOutputRows($response, $format, __('production_bench.output.columns.type'));

    expect($rows)->toHaveCount(2);
    foreach (['physical' => 1.5, 'quarantined' => .5, 'reserved' => .2, 'available' => .8, 'incoming' => .3, 'required' => 1.8, 'forecast' => -.7] as $key => $expected) {
        expect((float) $rows[0][__('production_bench.output.columns.'.$key)])->toBe($expected);
    }
    expect((float) $rows[1][__('production_bench.output.columns.physical')])->toBe(100.0)
        ->and($rows[1][__('production_bench.output.columns.unit')])->toBe('count');
    $this->assertDatabaseCount('stock_movements', 3);
    $this->assertDatabaseCount('stock_reservations', 1);
})->with(['csv', 'xlsx']);

it('exports all stock lots in chosen order and prints the same filtered count sheet', function (string $format): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create(['uses_storage_locations' => true]);
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $location = StorageLocation::factory()->for($workspace)->create(['name' => 'Inactive shelf', 'is_active' => false]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Count Oil']);
    $ingredient->workspaceCodes()->create(['workspace_id' => Workspace::factory()->create()->id, 'material_code' => 'OTHER-CODE']);
    $ingredient->workspaceCodes()->create(['workspace_id' => $workspace->id, 'material_code' => 'OUR-CODE']);
    foreach (range(0, 30) as $number) {
        $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create(['internal_lot_code' => sprintf('LOT-%02d', $number), 'supplier_batch_number' => 'BATCH', 'storage_location_id' => $location->id]);
        StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '1000']);
    }
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create(['internal_lot_code' => 'EMPTY']);
    config(['workspaces.list_output_limits.chunk_size' => 7]);
    $filters = ['workspace' => $workspace->public_id, 'format' => $format, 'lotSort' => 'code', 'lotStorageLocation' => $location->id, 'lotStatus' => 'released'];

    $rows = inventoryOutputRows($this->actingAs($actor)->postJson(route('production-bench.inventory.stock-lots.export', $filters)), $format, __('production_bench.output.columns.material'));
    expect($rows)->toHaveCount(31)
        ->and(array_column($rows, __('production_bench.output.columns.lot')))->toBe(collect(range(0, 30))->map(fn (int $number): string => sprintf('LOT-%02d', $number))->all())
        ->and($rows[0][__('production_bench.output.columns.code')])->toBe('OUR-CODE')
        ->and($rows[0][__('production_bench.output.columns.location')])->toBe('Inactive shelf')
        ->and((float) $rows[0][__('production_bench.output.columns.physical')])->toBe(1.0);
    $this->get(route('production-bench.inventory.count.print', $filters))->assertSeeInOrder(['LOT-00', 'LOT-30'])
        ->assertSee('OUR-CODE')->assertSee('data-counted', false)->assertSee('data-count-notes', false)->assertDontSee('OTHER-CODE')->assertDontSee('EMPTY')
        ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 31 && ! array_key_exists('counted', $rows[0]));
    $this->assertDatabaseCount('stock_movements', 31);
})->with(['csv', 'xlsx']);

it('omits disabled locations and exports finished product names and signed physical counts', function (): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create(['uses_storage_locations' => false]);
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $recipe = Recipe::factory()->create(['name' => 'Finished bars']);
    $lot = StockLot::factory()->for($workspace)->released()->create(['ingredient_id' => null, 'recipe_id' => $recipe->id, 'unit_kind' => StockUnitKind::Count]);
    StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '-100']);
    $filters = ['workspace' => $workspace->public_id, 'format' => 'csv', 'lotStorageLocation' => ['stale' => 'value']];

    $rows = inventoryOutputRows($this->actingAs($actor)->postJson(route('production-bench.inventory.stock-lots.export', $filters)), 'csv', __('production_bench.output.columns.material'));
    expect($rows[0][__('production_bench.output.columns.material')])->toBe('Finished bars')
        ->and($rows[0][__('production_bench.output.columns.physical')])->toBe('-100')
        ->and($rows[0])->not->toHaveKey(__('production_bench.output.columns.location'));
    $this->get(route('production-bench.inventory.count.print', $filters))->assertSee('Finished bars')->assertDontSee(__('production_bench.output.columns.location'));
});

it('allows viewers after cancellation and rejects revocation or workspace switches', function (string $endpoint): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    $owner->entitlements()->create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subMinute()]);
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    $membership = WorkspaceMember::factory()->for($workspace)->for($viewer, 'user')->create(['role' => WorkspaceMemberRole::Viewer]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    app(ProductionBenchAccess::class)->cancel($owner, $workspace);
    $lot = StockLot::factory()->for($workspace)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '1000']);
    $url = route('production-bench.inventory.'.$endpoint, ['workspace' => $workspace->public_id, 'format' => 'csv']);
    $response = $this->actingAs($viewer)->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertSuccessful();
    if ($endpoint !== 'count.print') {
        queuedExportDownload($response)->streamedContent();
    }
    $membership->delete();
    $this->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertForbidden();
    $selected = Workspace::factory()->for($owner, 'owner')->create();
    $owner->forceFill(['active_workspace_id' => $selected->id])->save();
    $this->actingAs($owner)->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertStatus(409);
})->with(['materials.export', 'stock-lots.export', 'count.print']);

it('rejects inactive modules, empty results and bounded outputs', function (string $endpoint): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    $url = route('production-bench.inventory.'.$endpoint, ['workspace' => $workspace->public_id, 'format' => 'csv']);
    $this->actingAs($actor)->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertForbidden();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $this->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertUnprocessable()->assertJsonValidationErrors('export');
    foreach (range(1, 2) as $number) {
        $lot = StockLot::factory()->for($workspace)->released()->create();
        StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '1']);
    }
    config(['workspaces.list_output_limits.max_rows' => 1, 'workspaces.list_output_limits.max_print_rows' => 1]);
    $this->json($endpoint === 'count.print' ? 'GET' : 'POST', $url)->assertUnprocessable()->assertJsonValidationErrors('export');
})->with(['materials.export', 'stock-lots.export', 'count.print']);

it('validates lot identifiers dates enums and enabled storage before querying', function (string $key, mixed $value): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create(['uses_storage_locations' => true]);
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $this->actingAs($actor)->postJson(route('production-bench.inventory.stock-lots.export', ['workspace' => $workspace->public_id, 'format' => 'csv', $key => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($key);
})->with([
    ['lotMaterial', 'bad-uuid'], ['lotSupplier', 'bad-uuid'], ['lotDateFrom', '2026-02-31'], ['lotDateUntil', 'bad-date'],
    ['lotOrigin', 'unknown'], ['lotScope', 'unknown'], ['lotStatus', 'unknown'], ['lotStorageLocation', 'wrong'], ['lotStorageLocation', '999999'],
]);

it('shows only the current inventory view output actions with the complete query count', function (string $mode, string $visible, string $hidden): void {
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);
    $lot = StockLot::factory()->for($workspace)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create(['quantity_delta' => '1']);

    Livewire::actingAs($actor)->test(InventoryIndex::class, ['mode' => $mode])
        ->assertSee($visible)->assertDontSee($hidden)
        ->assertSee('data-export-has-results="true"', false)
        ->assertSee((string) $workspace->public_id);
})->with([
    ['materials', 'Export materials', 'Export stock lots'],
    ['stock', 'Export stock lots', 'Export materials'],
]);
