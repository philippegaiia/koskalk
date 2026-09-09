<?php

use App\Enums\MassDisplaySystem;
use App\Enums\StockMovementType;
use App\Enums\WorkspaceMemberRole;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Models\GoodsReceipt;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the material detail progressive disclosure structure', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Structural oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);

    $this->actingAs($user);

    $html = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->html();
    $document = materialDetailDocument($html);
    $xpath = new DOMXPath($document);
    $violations = [];
    $assertCount = static function (string $query, int $expected, string $label) use ($xpath, &$violations): void {
        $actual = materialDetailXPathCount($xpath, $query);

        if ($actual !== $expected) {
            $violations[] = sprintf('%s: expected %d, got %d', $label, $expected, $actual);
        }
    };

    $assertCount('//*[@data-material-stock-summary]', 1, 'one stock summary card');
    $assertCount('//*[@data-material-stock-summary and @aria-labelledby="current-position-heading"]', 1, 'stock summary labels current position');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-primary]', 2, 'two primary position metrics in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-primary="available"]', 1, 'Available is a primary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-primary="forecast"]', 1, 'Forecast is a primary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-material-buffer]', 1, 'one buffer area in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-material-position-breakdown]', 1, 'one current position breakdown in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary]', 5, 'five secondary position metrics in stock summary');
    $assertCount('//*[@data-material-position-breakdown]//*[@data-position-secondary]', 5, 'five secondary position metrics in position breakdown');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary="physical"]', 1, 'Physical is a secondary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary="reserved"]', 1, 'Reserved is a secondary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary="quarantined"]', 1, 'Quarantined is a secondary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary="incoming"]', 1, 'Incoming is a secondary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-position-secondary="required"]', 1, 'Required is a secondary metric in stock summary');
    $assertCount('//*[@data-material-stock-summary]//*[@data-material-forecast-equation]', 1, 'Forecast includes its calculation breakdown');
    $assertCount('//*[@data-material-open-lots]', 1, 'open lots section');
    $assertCount('//*[@data-material-view-all-lots]', 1, 'one marked View all lots link');
    $assertCount('//a[contains(normalize-space(.), "View all lots")]', 1, 'one visible View all lots link');
    $assertCount('//*[@data-material-supplier-listings]', 1, 'supplier listings disclosure');
    $assertCount('//*[@data-material-activity]', 1, 'period activity disclosure');
    $assertCount('//*[@data-material-activity]//summary//*[@data-material-activity-chevron]', 1, 'one activity summary chevron');
    $assertCount('//*[@data-material-activity]//*[@data-activity-metric-label and contains(concat(" ", normalize-space(@class), " "), " line-clamp-2 ") and contains(concat(" ", normalize-space(@class), " "), " min-h-8 ")]', 8, 'activity metric labels share a two-line height');

    $positionBreakdowns = $xpath->query('//*[@data-material-position-breakdown]');

    if (! $positionBreakdowns instanceof DOMNodeList || $positionBreakdowns->length !== 1) {
        $violations[] = 'data-material-position-breakdown: expected one visible position breakdown';
    } elseif ($positionBreakdowns->item(0) instanceof DOMElement && $positionBreakdowns->item(0)->tagName === 'details') {
        $violations[] = 'data-material-position-breakdown: expected visible content, not a disclosure';
    }

    $forecastEquations = $xpath->query('//*[@data-material-forecast-equation]');

    if (! $forecastEquations instanceof DOMNodeList || $forecastEquations->length !== 1) {
        $violations[] = 'forecast equation: expected one calculation';
    } else {
        $forecastEquation = $forecastEquations->item(0)?->textContent ?? '';

        foreach ([
            __('production_bench.inventory.available'),
            __('production_bench.inventory.incoming'),
            __('production_bench.inventory.required'),
            __('production_bench.inventory.forecast'),
            '+',
            '-',
            '=',
        ] as $term) {
            if (! str_contains($forecastEquation, $term)) {
                $violations[] = 'forecast equation: missing '.$term;
            }
        }
    }

    foreach (['data-material-supplier-listings', 'data-material-activity'] as $marker) {
        $nodes = $xpath->query('//*[@'.$marker.']');

        if (! $nodes instanceof DOMNodeList || $nodes->length !== 1) {
            $violations[] = $marker.': expected one native details element';

            continue;
        }

        $detail = $nodes->item(0);

        if (! $detail instanceof DOMElement || $detail->tagName !== 'details') {
            $violations[] = $marker.': expected a native details element';

            continue;
        }

        $firstElementChild = null;

        foreach ($detail->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $firstElementChild = $child;

                break;
            }
        }

        if (! $firstElementChild instanceof DOMElement || $firstElementChild->tagName !== 'summary') {
            $violations[] = $marker.': expected summary to be the first element child';
        }

        if ($detail->hasAttribute('open')) {
            $violations[] = $marker.': expected closed by default';
        }
    }

    $activitySummaries = $xpath->query('//*[@data-material-activity]//summary');

    if (! $activitySummaries instanceof DOMNodeList || $activitySummaries->length !== 1) {
        $violations[] = 'activity summary: expected one summary';
    } else {
        $activitySummary = $activitySummaries->item(0);

        if (! $activitySummary instanceof DOMElement) {
            $violations[] = 'activity summary: expected an element';
        } elseif (! str_contains($activitySummary->textContent, __('production_bench.inventory.net_change'))) {
            $violations[] = 'activity summary: expected Net change';
        }
    }

    expect($violations)->toBe([]);
});

it('opens supplier and activity disclosures for their server-side states', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Disclosure oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Disclosure supplier']);

    foreach (range(1, 11) as $number) {
        SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
            'supplier_sku' => 'DISC-'.$number,
        ]);
    }

    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);

    $this->actingAs($user);

    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ]);

    $defaultHtml = $component->html();
    $defaultSupplierKey = materialDetailAttributeValue($defaultHtml, '//*[@data-material-supplier-listings]', 'wire:key');
    $defaultActivityKey = materialDetailAttributeValue($defaultHtml, '//*[@data-material-activity]', 'wire:key');

    expect($defaultSupplierKey)->toBeString()->not->toBe('');
    expect($defaultActivityKey)->toBeString()->not->toBe('');

    $supplierHtml = $component->call('gotoPage', 2, 'supplier-listings')->html();

    expect(materialDetailDetailsHaveLiteralOpen($supplierHtml, 'data-material-supplier-listings'))->toBeTrue();
    expect(materialDetailAttributeValue($supplierHtml, '//*[@data-material-supplier-listings]', 'wire:key'))->toBe($defaultSupplierKey);

    $component->set('periodPreset', '365');

    $activityHtml = $component->html();

    expect(materialDetailDetailsHaveLiteralOpen($activityHtml, 'data-material-activity'))->toBeTrue();
    expect(materialDetailAttributeValue($activityHtml, '//*[@data-material-activity]', 'wire:key'))->toBe($defaultActivityKey);
});

it('keeps material detail collaborators available after a Livewire request', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Injected oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->set('periodPreset', '365')
        ->assertSee('Injected oil')
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.00')
        ->assertViewHas('activity', fn (array $activity): bool => $activity['closing_physical'] === '1.00');
});

it('renders a tracked ingredient detail with current position and lot navigation', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Local Oils']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_batch_number' => 'BATCH-1',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('Olive oil')
        ->assertSee('Current position')
        ->assertSee('1.00')
        ->assertSee('Local Oils')
        ->assertSee('BATCH-1')
        ->assertSee('View all lots')
        ->assertViewHas('lotRegisterUrl', fn (string $url): bool => str_contains($url, 'material_type=ingredient')
            && str_contains($url, 'lot_scope=all'))
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.00'
            && $position['available'] === '1.00');
});

it('formats ingredient and packaging quantities with exact localized decimals', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $user->update(['number_locale' => 'fr_FR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Precise oil']);
    $ingredientLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($ingredientLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '59870.000000000',
    ]);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Precise jars']);
    $packagingLot = StockLot::factory()->for($workspace)->forPackaging()->create([
        'packaging_item_id' => $packaging->id,
    ]);
    StockMovement::factory()->for($packagingLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '2274.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->assertViewHas('position', fn (array $position): bool => $position['physical'] === '59,87');

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $packaging->public_id,
        'subjectType' => 'packaging',
    ])
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '2274')
        ->assertDontSee('2,274');
});

it('keeps high precision and negative inventory quantities exact in localized positions', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $user->update(['number_locale' => 'fr_FR']);
    $boundaryIngredient = Ingredient::factory()->create(['display_name' => 'Boundary oil']);
    $boundaryLot = StockLot::factory()->for($workspace)->for($boundaryIngredient)->released()->create();
    StockMovement::factory()->for($boundaryLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '99999999999.995000000',
    ]);
    $negativeIngredient = Ingredient::factory()->create(['display_name' => 'Negative oil']);
    $negativeLot = StockLot::factory()->for($workspace)->for($negativeIngredient)->released()->create();
    StockMovement::factory()->for($negativeLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '-59870.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $boundaryIngredient->public_id,
        'subjectType' => 'ingredient',
    ])->assertViewHas('position', fn (array $position): bool => $position['physical'] === '100000000,00');

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $negativeIngredient->public_id,
        'subjectType' => 'ingredient',
    ])->assertViewHas('position', fn (array $position): bool => $position['physical'] === '-59,87'
        && $position['available'] === '-59,87');
});

it('renders the material detail headings in French for a French interface locale', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    SupportedLocale::query()->where('code', 'fr')->update(['is_active' => true]);
    app()->setLocale('fr');

    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $user->update(['locale' => 'fr']);
    $this->artisan('translations:catalogue:import', [
        '--mode' => 'authoritative',
    ])->assertSuccessful();

    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Local Oils']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'supplier_listing_id' => $listing->id,
        'supplier_batch_number' => 'BATCH-1',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('Position actuelle')
        ->assertSee('Lots ouverts')
        ->assertSee('Mouvements de la période')
        ->assertSee('Voir tous les lots')
        ->assertSee('Commandes d’achat en cours')
        ->assertSee('30 derniers jours')
        ->assertSee('Consommé en production')
        ->assertSee('Fournisseur')
        ->assertDontSeeHtml('>Current position<')
        ->assertDontSeeHtml('>Open lots<')
        ->assertDontSeeHtml('>View all lots<')
        ->assertDontSeeHtml('>Outstanding purchase orders<')
        ->assertDontSeeHtml('>Last 30 days<')
        ->assertDontSeeHtml('>Production consumed<')
        ->assertDontSeeHtml('>Supplier<');
});

it('shows the material name and lot code together in the open lots table', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Audit rosemary oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Local Oils']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'supplier_listing_id' => $listing->id,
        'internal_lot_code' => 'AUDIT-LOT-001',
        'supplier_batch_number' => 'BATCH-AUDIT',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);

    $this->actingAs($user);

    // The open lots first cell carries both the material name and its lot code,
    // so each row is identifiable without leaving the detail page.
    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('Audit rosemary oil')
        ->assertSee('AUDIT-LOT-001');
});

it('lists the purchasing listings that can replenish the material', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    // A supplier listing is what makes the material tracked here, so this
    // material has a purchasing catalogue and no stock at all.
    $ingredient = Ingredient::factory()->create(['display_name' => 'Shea butter']);
    $alpha = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    $beta = Supplier::factory()->for($workspace)->create(['name' => 'Beta Supply']);

    SupplierListing::factory()->for($workspace)->for($alpha)->for($ingredient)->create([
        'supplier_sku' => 'SKU-ALPHA',
        'supplier_item_name' => 'Raw shea butter',
        'purchase_format' => 'Drum of 25 kg',
        'is_active' => true,
    ]);
    SupplierListing::factory()->for($workspace)->for($beta)->for($ingredient)->create([
        'supplier_sku' => 'SKU-BETA',
        'supplier_item_name' => 'Refined shea butter',
        'purchase_format' => 'Box of 12 kg',
        'is_active' => false,
    ]);

    // Ingredients are a global catalogue, so the foreign listing shares this
    // subject. Only the workspace scoping keeps it out.
    $foreignWorkspace = Workspace::factory()->for(User::factory(), 'owner')->create();
    SupplierListing::factory()
        ->for($foreignWorkspace)
        ->for(Supplier::factory()->for($foreignWorkspace)->create(['name' => 'Foreign Oils']))
        ->for($ingredient)
        ->create(['supplier_sku' => 'SKU-FOREIGN']);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee(__('production_bench.inventory.related_supplier_listings'))
        ->assertSee('Alpha Oils')
        ->assertSee('SKU-ALPHA')
        ->assertSee('Raw shea butter')
        ->assertSee('Drum of 25 kg')
        ->assertSee(route('production-bench.purchasing.supplier', $alpha), false)
        ->assertSee('Beta Supply')
        ->assertSee('SKU-BETA')
        ->assertSee('Refined shea butter')
        ->assertSee('Box of 12 kg')
        ->assertSee(route('production-bench.purchasing.supplier', $beta), false)
        // "Active" is a substring of "Inactive", so the badge is matched with
        // its closing tag; otherwise the assertion could never fail.
        ->assertSeeHtml('>'.__('production_bench.common.active').'</span>')
        ->assertSee(__('production_bench.common.inactive'))
        ->assertDontSee('Foreign Oils')
        ->assertDontSee('SKU-FOREIGN')
        // No stock exists, so the listing section has to stand on its own.
        ->assertSee(__('production_bench.inventory.no_open_lots'));
});

it('explains a missing supplier listing without implying that stock is missing', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Cocoa butter']);
    // Tracked by its buffer alone: no lot and no listing.
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee(__('production_bench.inventory.related_supplier_listings'))
        ->assertSee(__('production_bench.inventory.no_supplier_listings'))
        ->assertSee(__('production_bench.inventory.no_open_lots'));

    // The empty state has to describe the purchasing catalogue. Reusing the
    // stock wording here would read as "this material has no stock".
    expect(__('production_bench.inventory.no_supplier_listings'))
        ->not->toBe(__('production_bench.inventory.no_open_lots'));
});

it('paginates supplier listings independently of the period activity', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);

    foreach (range(1, 12) as $n) {
        SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
            'supplier_sku' => 'SKU-'.$n,
        ]);
    }

    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '10',
            'occurred_at' => now()->subDays(2)->addHours($n),
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->total() === 12
            && $page->count() === 10
            && $page->lastPage() === 2)
        // Each paginator has to drive its own page size; a shared control would
        // let one section silently rewrite the other's.
        ->assertSeeHtml('wire:model.live="supplierListingsPerPage"')
        ->assertSeeHtml('wire:model.live="perPage"')
        ->call('gotoPage', 2, 'supplier-listings')
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2
            && $page->count() === 2)
        // Two paginators share the page, so each has to keep its own position.
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 25)
        ->set('supplierListingsPerPage', 25)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 12)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->count() === 25)
        ->set('perPage', 50)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->count() === 30)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->count() === 12)
        // A page size that is not offered falls back to the default rather than
        // being handed to the paginator.
        ->set('supplierListingsPerPage', 7)
        ->assertSet('supplierListingsPerPage', 10)
        ->assertViewHas('supplierListings', fn (LengthAwarePaginator $page): bool => $page->count() === 10);
});

it('changes activity periods without changing the current position', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Rose water']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(40),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::PurchaseReceipt,
        'quantity_delta' => '250',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.25')
        ->set('periodPreset', '365')
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '1.25')
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.25'
            && $activity['reconciliation_ok'] === true);
});

it('renders the period controls from a filament schema', function (): void {
    $this->travelTo('2026-09-09 10:00:00');

    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Rose water']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSeeHtml('for="activityFiltersForm.period"')
        // The custom dates are only meaningful once "custom" is selected, so
        // the schema has to render them conditionally rather than always.
        ->assertDontSeeHtml('for="activityFiltersForm.from"')
        ->set('periodPreset', 'custom')
        ->assertHasNoErrors()
        ->assertSet('customFrom', '2026-08-11')
        ->assertSet('customTo', '2026-09-09')
        ->assertSeeHtml('for="activityFiltersForm.from"')
        ->assertSeeHtml('for="activityFiltersForm.to"');
});

it('initializes and validates custom periods loaded from the url', function (): void {
    $this->travelTo('2026-09-09 10:00:00');

    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Rose water']);
    StockLot::factory()->for($workspace)->for($ingredient)->create();

    $this->actingAs($user);

    $response = $this->get(route('production-bench.inventory.material.ingredient', [
        'ingredient' => $ingredient,
        'period' => 'custom',
    ]));
    $response
        ->assertOk()
        ->assertDontSeeText(__('production_bench.inventory.period_date_required'));

    $document = materialDetailDocument($response->getContent());
    $component = (new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]][descendant::*[@data-material-stock-summary]]')?->item(0);
    $snapshot = json_decode($component?->getAttribute('wire:snapshot') ?? '', true);

    expect(data_get($snapshot, 'data.customFrom'))->toBe('2026-08-11')
        ->and(data_get($snapshot, 'data.customTo'))->toBe('2026-09-09');

    $this->get(route('production-bench.inventory.material.ingredient', [
        'ingredient' => $ingredient,
        'period' => 'custom',
        'from' => '2026-09-01',
    ]))
        ->assertOk()
        ->assertSeeText(__('production_bench.inventory.period_date_required'));
});

it('keeps the custom period validation and the activity page through the schema', function (): void {
    $this->travelTo('2026-09-09 10:00:00');

    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Rose water']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '10',
            'occurred_at' => now()->subDays(2)->addHours($n),
        ]);
    }

    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::PurchaseReceipt,
        'quantity_delta' => '40',
        'occurred_at' => now()->subDays(200),
    ]);

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        // 30 x 10 g landed in the last two days; the 40 g movement is 200 days
        // old and only enters the 365-day window.
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.30')
        ->call('gotoPage', 2, 'activity')
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2)
        // Widening the period has to reset the activity paginator back to the
        // first page, which is what updatedPeriodPreset() already does.
        ->set('periodPreset', '365')
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1)
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.34')
        // Choosing "custom" starts with the selected preset's range, so the
        // form is useful immediately and does not flash required errors.
        ->set('periodPreset', 'custom')
        ->assertHasNoErrors()
        ->assertSet('customFrom', '2025-09-10')
        ->assertSet('customTo', '2026-09-09')
        ->set('customFrom', today()->subDays(10)->toDateString())
        ->set('customTo', today()->subDays(20)->toDateString())
        ->assertHasErrors(['customFrom'])
        ->set('customTo', today()->toDateString())
        ->assertHasNoErrors()
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.30');
});

it('paginates the period activity rows while reconciliation covers every movement', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '10',
            'occurred_at' => now()->subDays(2)->addHours($n),
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->total() === 30
            && $page->count() === 25
            && $page->lastPage() === 2)
        ->call('gotoPage', 2, 'activity')
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 2
            && $page->count() === 5)
        ->set('perPage', 50)
        ->assertViewHas('movements', fn (LengthAwarePaginator $page): bool => $page->currentPage() === 1
            && $page->count() === 30
            && $page->lastPage() === 1)
        // 30 movements of 10 g received = 0.30 kg; the totals never depend on
        // which page of rows is shown.
        ->assertViewHas('activity', fn (array $activity): bool => $activity['received'] === '0.30'
            && $activity['reconciliation_ok'] === true);
});

it('renders packaging balances in units', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Clear bottle']);
    $lot = StockLot::factory()
        ->for($workspace)
        ->forPackaging()
        ->released()
        ->create([
            'packaging_item_id' => $packaging->id,
            'unit_kind' => 'count',
        ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '12',
        'original_quantity' => '12',
        'original_unit' => 'count',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $packaging->public_id,
        'subjectType' => 'packaging',
    ])
        ->assertSee('Clear bottle')
        ->assertSee('units')
        ->assertSee('12')
        ->assertViewHas('position', fn (array $position): bool => $position['physical'] === '12'
        && $position['available'] === '12');
});

it('keeps custom activity dates null-safe when a date is cleared', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->set('periodPreset', '365')
        ->set('periodPreset', 'custom')
        ->assertHasNoErrors()
        ->set('customFrom', null)
        ->assertHasErrors(['customFrom'])
        ->assertHasNoErrors(['customTo'])
        ->set('customFrom', '2026-08-31')
        ->set('customTo', '2026-08-01')
        ->assertHasErrors(['customFrom', 'customTo'])
        // Filament's non-native picker emits its internal midnight format
        // during a live update; the URL state must be normalized to date-only.
        ->set('customTo', '2026-08-31 00:00:00')
        ->assertSet('customTo', '2026-08-31')
        ->assertHasNoErrors()
        ->set('periodPreset', '30')
        ->assertSet('customFrom', null)
        ->assertSet('customTo', null)
        ->assertHasNoErrors();
});

it('saves and clears a buffer from the detail page', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '1,250.5'])
        ->assertHasNoFormErrors()
        ->assertDispatched('app-notification');

    // 1 250,5 kg in the metric display unit is persisted as canonical grams.
    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('1250500.000000000');

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('clearBuffer')
        ->assertDispatched('app-notification');

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('clears a buffer by saving an empty quantity', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => ''])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->count())->toBe(0);
});

it('hides buffer actions for a read-only bench', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    app(ProductionBenchAccess::class)->cancel($user, $workspace);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionHidden('editBuffer')
        ->assertActionHidden('clearBuffer');
});

it('hides buffer actions from an active viewer', function (): void {
    ['user' => $owner, 'workspace' => $workspace] = materialDetailWorkspace();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($viewer);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertActionHidden('editBuffer')
        ->assertActionHidden('clearBuffer');
});

it('keeps another workspace material out of the detail route', function (): void {
    ['user' => $user] = materialDetailWorkspace();
    $otherWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($otherWorkspace)->for($ingredient)->create();
    $this->actingAs($user);

    $this->get(route('production-bench.inventory.material.ingredient', $ingredient))
        ->assertNotFound();
});

it('rejects mutation of the locked route-bound subject identifiers', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ]);

    // Without #[Locked] these set() calls would silently succeed and redirect the
    // component to another material on the next request.
    expect(fn () => $component->set('ingredientPublicId', 'tampered'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('packagingPublicId', 'tampered'))
        ->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => $component->set('subjectType', 'packaging'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('seeds the buffer input in the workspace display unit', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '1200.000000000',
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->mountAction('editBuffer')
        ->assertSchemaStateSet(['buffer_quantity' => '1.200000000']);
});

it('stores a metric buffer in canonical grams', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '1.2'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('1200.000000000');
});

it('stores a us customary buffer in canonical grams', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace(MassDisplaySystem::UsCustomary);
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->create();
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '2'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('907.184740000');
});

it('leaves packaging buffers unconverted', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    StockLot::factory()->for($workspace)->forPackaging()->create([
        'packaging_item_id' => $packaging->id,
    ]);
    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $packaging->public_id,
        'subjectType' => 'packaging',
    ])
        ->callAction('editBuffer', data: ['buffer_quantity' => '12'])
        ->assertHasNoFormErrors();

    expect(WorkspaceMaterialSetting::query()->value('buffer_quantity'))->toBe('12.000000000');
});

it('links only the movement sources that belong to the workspace', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    $ownReceipt = GoodsReceipt::factory()
        ->direct()
        ->for($workspace)
        ->for(Supplier::factory()->for($workspace), 'supplier')
        ->create(['delivery_reference' => 'DEL-OURS']);

    // The source is a morphTo, so nothing in the schema ties it to the movement's
    // workspace. A foreign record must not surface its identifier here, and its
    // route would 404 at the destination anyway.
    $foreignWorkspace = Workspace::factory()->for(User::factory(), 'owner')->create();
    $foreignReceipt = GoodsReceipt::factory()
        ->direct()
        ->for($foreignWorkspace)
        ->for(Supplier::factory()->for($foreignWorkspace), 'supplier')
        ->create(['delivery_reference' => 'DEL-THEIRS']);

    foreach ([['10', $ownReceipt], ['5', $foreignReceipt]] as [$delta, $receipt]) {
        StockMovement::factory()->for($lot, 'stockLot')->create([
            'workspace_id' => $workspace->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => $delta,
            'source_type' => $receipt->getMorphClass(),
            'source_id' => $receipt->id,
        ]);
    }

    $this->actingAs($user);

    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSee('DEL-OURS')
        ->assertDontSee('DEL-THEIRS')
        ->assertSee(__('production_bench.inventory.source_not_available'));
});

it('keeps the material detail table headers in view', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Sticky header oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    // Every table grows with its rows and retains native horizontal scrolling.
    // The shared wrapper moves each header with the page at every width.
    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertDontSeeHtml('max-h-[')
        ->assertSeeHtml('data-sticky-table-scroll')
        ->assertSeeHtml('x-data="stickyTableHeader()"')
        ->assertSeeHtml('wire:ignore.self data-sticky-table-header')
        ->assertSeeHtml('overflow-x-auto')
        ->assertDontSeeHtml('@min-[57rem]:overflow-x-visible')
        ->assertSeeHtml('min-w-[900px]')
        ->assertDontSeeHtml('@min-[54rem]:overflow-x-visible')
        ->assertSeeHtml('min-w-[860px]')
        ->assertDontSeeHtml('@min-[48rem]:overflow-x-visible')
        ->assertSeeHtml('min-w-[760px]')
        ->assertDontSeeHtml('sticky top-0 z-20');

    expect(substr_count($component->html(), 'data-sticky-table-scroll'))->toBe(3)
        ->and(substr_count($component->html(), 'data-sticky-table-header'))->toBe(3);
});

it('lifts the period filter controls above the sticky activity header', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Filter stacking oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
        'occurred_at' => now()->subDays(5),
    ]);

    $this->actingAs($user);

    // The period controls hold a Filament select, and its dropdown panel is
    // `position: absolute; z-index: 20` and is not teleported — the same layer
    // as the sticky `z-20` thead further down the DOM, which wins on document
    // order. The wrapper has to be its own stacking context above the header
    // for the panel to open over the table rather than behind it.
    Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])
        ->assertSeeHtml('class="relative z-30 border-b border-[var(--color-line)] p-4"');
});

/** @return array{user: User, workspace: Workspace} */
function materialDetailWorkspace(MassDisplaySystem $displaySystem = MassDisplaySystem::Metric): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create([
        'mass_display_system' => $displaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return ['user' => $user, 'workspace' => $workspace];
}

function materialDetailDocument(string $html): DOMDocument
{
    $previousLibxmlSetting = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlSetting);

    return $document;
}

function materialDetailXPathCount(DOMXPath $xpath, string $query): int
{
    $nodes = $xpath->query($query);

    return $nodes instanceof DOMNodeList ? $nodes->length : 0;
}

function materialDetailDetailsHaveLiteralOpen(string $html, string $marker): bool
{
    $xpath = new DOMXPath(materialDetailDocument($html));
    $nodes = $xpath->query('//*[@'.$marker.']');

    if (! $nodes instanceof DOMNodeList || $nodes->length !== 1) {
        return false;
    }

    $detail = $nodes->item(0);

    return $detail instanceof DOMElement
        && $detail->tagName === 'details'
        && $detail->hasAttribute('open');
}

function materialDetailAttributeValue(string $html, string $query, string $attribute): ?string
{
    $xpath = new DOMXPath(materialDetailDocument($html));
    $nodes = $xpath->query($query);

    if (! $nodes instanceof DOMNodeList || $nodes->length !== 1) {
        return null;
    }

    $element = $nodes->item(0);

    return $element instanceof DOMElement
        ? $element->getAttribute($attribute)
        : null;
}
