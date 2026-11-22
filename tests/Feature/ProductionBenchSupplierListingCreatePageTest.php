<?php

use App\Enums\ListingPriceBasis;
use App\Enums\MassDisplaySystem;
use App\Enums\OwnerType;
use App\Enums\ProductionDocumentType;
use App\Enums\StockUnitKind;
use App\Enums\Visibility;
use App\Livewire\Dashboard\MediaLibraryIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingCreate;
use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use App\Services\ProductionBenchAccess;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('protects both supplier listing creation routes', function (): void {
    $supplier = Supplier::factory()->create();

    $this->get('/dashboard/production-bench/purchasing/listings/new')
        ->assertRedirect(route('login'));
    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id.'/listings/new')
        ->assertRedirect(route('login'));
});

it('adds and removes upload groups using the listing form actions', function (): void {
    [$owner] = listingCreateWorkspace();
    $this->actingAs($owner);

    $page = Livewire::test(SupplierListingCreate::class);
    $repeater = collect($page->instance()->form->getFlatFields())->sole(
        fn ($field): bool => $field instanceof Repeater && $field->getName() === 'new_documents',
    );
    $context = ['schemaComponent' => $repeater->getKey()];

    $page->call('mountAction', 'add', [], $context)->assertOk();
    $page->call('mountAction', 'add', [], $context)->assertOk();
    $groups = $page->get('data.new_documents');
    expect($groups)->toHaveCount(2);

    $page->call('mountAction', 'delete', ['item' => array_key_first($groups)], $context)
        ->assertOk();
    expect($page->get('data.new_documents'))->toHaveCount(1);
});

it('reuses a private library certificate across purchase formats', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $first = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $second = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
        'purchase_format' => '25 kg drum',
        'net_quantity' => '25',
        'canonical_quantity_per_purchase_format' => '25000',
    ]);
    $certificate = MediaAsset::factory()->for($workspace)->pdf()->ready()->create([
        'original_filename' => 'supplier-certificate.pdf',
    ]);
    $this->actingAs($owner);

    foreach ([$first, $second] as $listing) {
        Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
            ->set('data.new_documents', ['certificate' => [
                'library_asset_ids' => [(string) $certificate->id],
                'type' => ProductionDocumentType::Certificate->value,
                'note' => $listing->purchase_format,
            ]])
            ->assertSee('Choose from Media Library')
            ->assertSee('supplier-certificate.pdf')
            ->assertDontSeeHtml('data-media-picker-upload-tab')
            ->call('save')
            ->assertHasNoErrors();

        expect($listing->documents()->sole()->media_asset_id)->toBe($certificate->id)
            ->and($listing->documents()->sole()->note)->toBe($listing->purchase_format);
    }

    Livewire::test(SupplierListingCreate::class, ['listing' => $first->public_id])
        ->call('detachDocument', $first->documents()->sole()->id)->assertHasNoErrors();

    expect($first->documents()->exists())->toBeFalse()
        ->and($second->documents()->sole()->media_asset_id)->toBe($certificate->id)
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('refuses to attach a library file from another workspace or one that is not ready', function (bool $foreign): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    $asset = $foreign
        ? MediaAsset::factory()->ready()->create()
        : MediaAsset::factory()->for($workspace)->failed()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->set('data.new_documents', ['certificate' => [
            'library_asset_ids' => [$asset->id],
            'type' => ProductionDocumentType::Certificate->value,
        ]])
        ->call('save')
        ->assertHasErrors('data.new_documents.certificate.library_asset_ids');

    expect($listing->documents()->exists())->toBeFalse()
        ->and($asset->fresh())->not->toBeNull();
})->with(['another workspace' => true, 'failed asset' => false]);

it('saves private documents with a supplier listing and keeps earlier copies when updated', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);
    $pdf = listingDocumentPdf();

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.price_amount', '10')
        ->set('data.new_documents', ['first' => [], 'second' => []])
        ->set('data.new_documents.first.uploads', [
            UploadedFile::fake()->image('specification.jpg'),
            UploadedFile::fake()->image('specification-appendix.jpg'),
        ])
        ->set('data.new_documents.first.type', ProductionDocumentType::Specification->value)
        ->set('data.new_documents.first.note', 'Initial revision')
        ->set('data.new_documents.second.uploads', [$pdf])
        ->assertSee('PDFs up to 1 MB. Images up to 10 MB.')
        ->set('data.new_documents.second.type', ProductionDocumentType::SafetyDataSheet->value)
        ->set('data.new_documents.second.note', 'Supplier safety data')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.listings'));

    $listing = SupplierListing::query()->sole();
    $specifications = $listing->documents()->where('type', ProductionDocumentType::Specification)->with('mediaAsset')->get();
    $original = $specifications->firstWhere('mediaAsset.original_filename', 'specification.jpg');
    $safetySheet = $listing->documents()->where('type', ProductionDocumentType::SafetyDataSheet)->sole();
    expect($original->note)->toBe('Initial revision')
        ->and($original->workspace_id)->toBe($workspace->id)
        ->and($specifications->pluck('note')->all())->toBe(['Initial revision', 'Initial revision'])
        ->and($safetySheet->note)->toBe('Supplier safety data')
        ->and($safetySheet->mediaAsset->original_filename)->toBe('safety-sheet.pdf')
        ->and($safetySheet->mediaAsset->original_size)->toBe($pdf->getSize());

    $imagePreviewUrl = route('media.show', [$original->mediaAsset, 'master']);
    $pdfPreviewUrl = route('media.download', [$safetySheet->mediaAsset, 'inline' => 1]);
    $this->get($imagePreviewUrl)->assertSuccessful();
    $this->get($pdfPreviewUrl)->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename=safety-sheet.pdf');
    Livewire::test(MediaLibraryIndex::class)
        ->call('openAssetPanel', $original->media_asset_id, 'usage')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.listings.edit', $listing).'"');

    $this->travel(1)->day();
    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->assertSeeInOrder(['Attach document', 'specification.jpg'])
        ->assertSeeHtml('href="'.$imagePreviewUrl.'" target="_blank"')
        ->assertSeeHtml('href="'.$pdfPreviewUrl.'" target="_blank"')
        ->assertSee('Initial revision')
        ->set('data.new_documents', ['revision' => []])
        ->set('data.new_documents.revision.uploads', [UploadedFile::fake()->image('new-specification.jpg')])
        ->set('data.new_documents.revision.type', ProductionDocumentType::Specification->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($listing->documents()->count())->toBe(4)
        ->and($listing->fresh()->price_recorded_at->equalTo($listing->price_recorded_at))->toBeTrue();

    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->assertSee('new-specification.jpg')
        ->call('delete');
    expect($listing->fresh()->is_active)->toBeFalse()
        ->and($listing->documents()->count())->toBe(4);

    $unrelatedListing = SupplierListing::factory()->for($workspace)->for($supplier)->create();
    Livewire::test(SupplierListingCreate::class, ['listing' => $unrelatedListing->public_id])
        ->call('detachDocument', $original->id)
        ->assertNotFound();

    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->call('detachDocument', $original->id)
        ->assertHasNoErrors()
        ->assertDontSeeHtml('href="'.$imagePreviewUrl.'"');
    expect($listing->documents()->count())->toBe(3);
    $this->get(route('media.download', $original->mediaAsset))->assertSuccessful();

    $this->actingAs(User::factory()->create())->get($imagePreviewUrl)->assertNotFound();
    $this->get($pdfPreviewUrl)->assertNotFound();
});

it('requires a listing document type for uploads and rejects a batch certificate', function (): void {
    Storage::fake('local');
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.price_amount', '10')
        ->set('data.new_documents', ['certificate' => []])
        ->set('data.new_documents.certificate.uploads', [UploadedFile::fake()->image('certificate.jpg')])
        ->call('save')
        ->assertHasErrors(['data.new_documents.certificate.type' => 'required'])
        ->set('data.new_documents.certificate.type', ProductionDocumentType::CertificateOfAnalysis->value)
        ->call('save')
        ->assertHasErrors('data.new_documents.certificate.type');

    expect(SupplierListing::query()->count())->toBe(0);
});

it('names an oversized PDF and cleans up earlier files in the same listing save', function (): void {
    Storage::fake('local');
    config()->set('media.asset_pending_disk', 'local');
    config()->set('media.asset_disk', 'local');
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.price_amount', '10')
        ->set('data.new_documents', ['files' => []])
        ->set('data.new_documents.files.uploads', [
            UploadedFile::fake()->image('first.jpg'),
            UploadedFile::fake()->createWithContent('oversized-certificate.pdf', str_pad("%PDF-1.4\n", 1024 * 1024 + 1)),
        ])
        ->assertHasErrors('data.new_documents.files.uploads')
        ->assertSee('“oversized-certificate.pdf” exceeds the 1 MB PDF limit.')
        ->set('data.new_documents.files.type', ProductionDocumentType::Certificate->value)
        ->call('save')
        ->assertHasErrors('data.new_documents.files.uploads')
        ->assertSee('“oversized-certificate.pdf” exceeds the 1 MB PDF limit.');

    expect(SupplierListing::query()->exists())->toBeFalse()
        ->and(MediaAsset::query()->where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

it('shows a focused global listing form with searchable catalog selectors', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'OLVEA', 'name' => 'Olvea']);
    Ingredient::factory()->create(['display_name' => 'Olive oil']);
    PackagingItem::factory()->for($workspace)->create([
        'name' => 'Amber bottle',
        'material_code' => 'PK-BOT-250',
    ]);

    $response = $this->actingAs($owner)
        ->get('/dashboard/production-bench/purchasing/listings/new');

    $response
        ->assertOk()
        ->assertSee('New supplier listing')
        ->assertSee('Supplier')
        ->assertSee('OLVEA · Olvea')
        ->assertSee('Purchase format')
        ->assertSee('Pricing')
        ->assertSee('Calculated price')
        ->assertSee('Recent items shown. Type to search all.')
        ->assertSee('Ingredient not in the catalogue?')
        ->assertSee('Create ingredient')
        ->assertDontSee('Packaging item not in the catalogue?')
        ->assertSee('Purchase formats per order.')
        ->assertSee('Save supplier listing')
        ->assertSeeHtml('data-production-bench-save-bar')
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('class="sk-btn sk-btn-ghost"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary"')
        ->assertDontSeeHtml('class="sk-btn sk-btn-danger"')
        ->assertSeeHtml('pb-24')
        ->assertSeeHtml('class="fi-section')
        ->assertSee('data.supplier_id', escape: false)
        ->assertSeeHtml('inputmode="decimal"')
        ->assertDontSee('Select an existing Soapkraft ingredient.')
        ->assertDontSee('Enter quantity and price.')
        ->assertDontSee('Number of purchase formats.')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.listings').'"');

    expect(substr_count($response->getContent(), 'fi-compact'))->toBe(5);

    expect(strpos($response->getContent(), 'Catalog item'))
        ->toBeLessThan(strpos($response->getContent(), 'Ingredient not in the catalogue?'))
        ->and(strpos($response->getContent(), 'Ingredient not in the catalogue?'))
        ->toBeLessThan(strpos($response->getContent(), 'Purchase format'));

    $component = Livewire::test(SupplierListingCreate::class)
        ->assertSee('Ingredient not in the catalogue?')
        ->assertDontSee('Packaging item not in the catalogue?');

    expect($component->instance()->supplierSearchResults('Olvea'))->toBe([$supplier->id => 'OLVEA · Olvea'])
        ->and($component->instance()->ingredientSearchResults('Olive'))->toHaveCount(1);

    $component
        ->set('data.material_type', 'packaging')
        ->assertSee('Packaging item not in the catalogue?')
        ->assertSee('Create packaging item')
        ->assertDontSee('Ingredient not in the catalogue?');

    expect($component->instance()->packagingSearchResults('PK-BOT-250'))
        ->toBe([$workspace->packagingItems()->where('name', 'Amber bottle')->value('id') => 'PK-BOT-250 · Amber bottle']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('uses the workspace material code in ingredient labels and search', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil', 'catalog_key' => 'PLATFORM-OLIVE']);
    WorkspaceIngredientCode::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'material_code' => 'RM-OLIVE',
    ]);
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class);

    expect($component->instance()->ingredientSearchResults('RM-OLIVE'))
        ->toBe([$ingredient->id => 'RM-OLIVE · Olive oil'])
        ->and($component->instance()->ingredientOptionLabel($ingredient->id))
        ->toBe('RM-OLIVE · Olive oil')
        ->and($component->instance()->ingredientSearchResults('PLATFORM-OLIVE'))
        ->toBe([]);
});

it('does not expose a catalogue key when an ingredient has no display name', function (): void {
    [$owner] = listingCreateWorkspace();
    $ingredient = Ingredient::factory()->create([
        'display_name' => null,
        'catalog_key' => 'HIDDEN-TECHNICAL-KEY',
    ]);
    $this->actingAs($owner);

    $label = Livewire::test(SupplierListingCreate::class)
        ->instance()
        ->ingredientOptionLabel($ingredient->id);

    expect($label)->toBe('Unnamed ingredient')
        ->not->toContain('HIDDEN-TECHNICAL-KEY');
});

it('requires a supplier on the global listing form', function (): void {
    [$owner] = listingCreateWorkspace();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', '200 kg drum')
        ->set('data.net_quantity', '200')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '4.20')
        ->set('data.price_unit', 'kg')
        ->call('save')
        ->assertHasErrors(['data.supplier_id' => 'required']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('keeps the correct listing navigation scope across live updates', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('navigationSection', 'listings')
        ->set('data.material_type', 'packaging')
        ->assertSet('navigationSection', 'listings');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('navigationSection', 'suppliers')
        ->set('data.material_type', 'packaging')
        ->assertSet('navigationSection', 'suppliers');
});

it('reports all required listing fields together', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', null)
        ->set('data.purchase_format', '')
        ->set('data.net_quantity', '')
        ->set('data.price_amount', '')
        ->call('save')
        ->assertHasErrors([
            'data.ingredient_id',
            'data.purchase_format' => 'required',
            'data.net_quantity' => 'required',
            'data.price_amount' => 'required',
        ]);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('creates an exact-price mass listing globally and returns to the listings index', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.supplier_sku', 'OO-200')
        ->set('data.internal_reference', ' BUY-042 ')
        ->set('data.supplier_item_name', 'Extra virgin olive oil')
        ->set('data.purchase_format', '200 kg drum')
        ->set('data.net_quantity', '200')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '4.20')
        ->set('data.price_unit', 'kg')
        ->set('data.currency', 'EUR')
        ->set('data.minimum_packs', 2)
        ->set('data.notes', 'Food grade')
        ->assertSee('EUR 840.00 total')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.listings'));

    $listing = SupplierListing::query()->firstOrFail();

    expect($listing)->toMatchArray([
        'workspace_id' => $workspace->id,
        'supplier_id' => $supplier->id,
        'ingredient_id' => $ingredient->id,
        'supplier_sku' => 'OO-200',
        'internal_reference' => 'BUY-042',
        'supplier_item_name' => 'Extra virgin olive oil',
        'purchase_format' => '200 kg drum',
        'net_quantity' => '200.000000000',
        'net_unit' => 'kg',
        'price_amount' => '4.200000000',
        'price_unit' => 'kg',
        'total_price' => '840.000000000',
        'currency' => 'EUR',
        'minimum_packs' => 2,
        'notes' => 'Food grade',
        'is_active' => true,
    ]);
});

it('opens an existing workspace listing in the edit form', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'OLVEA', 'name' => 'Olvea']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
        'purchase_format' => '200 kg drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4',
        'price_unit' => 'kg',
        'is_active' => true,
    ]);

    $editUrl = route('production-bench.purchasing.listings.edit', $listing);

    $editResponse = $this->actingAs($owner)
        ->get($editUrl)
        ->assertOk()
        ->assertSee('Edit supplier listing')
        ->assertSee('Olive oil')
        ->assertSeeHtml('data-production-bench-save-bar')
        ->assertSeeHtml('data-workflow-action-bar')
        ->assertSeeHtml('class="sk-btn sk-btn-danger"')
        ->assertSeeHtml('class="sk-btn sk-btn-ghost"')
        ->assertSeeHtml('class="sk-btn sk-btn-primary"')
        ->assertSeeHtml('pb-24')
        ->assertDontSee('Ingredient not in the catalogue?')
        ->assertDontSee('Packaging item not in the catalogue?')
        ->assertSee('OLVEA · Olvea');

    expect(strpos($editResponse->getContent(), 'Delete listing'))
        ->toBeLessThan(strpos($editResponse->getContent(), 'Cancel'));

    $this->get('/dashboard/production-bench/purchasing/listings')
        ->assertOk()
        ->assertSeeHtml('href="'.$editUrl.'"');

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id)
        ->assertOk()
        ->assertSeeHtml('href="'.$editUrl.'"');
});

it('updates an existing supplier listing without changing its supplier or material', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
        'internal_reference' => 'BUY-042',
        'purchase_format' => '200 kg drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
        'currency' => 'EUR',
    ]);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->assertSet('data.net_quantity', '200.00')
        ->assertSet('data.price_amount', '4.20')
        ->assertSet('data.supplier_id', $supplier->id)
        ->assertSet('data.ingredient_id', $ingredient->id)
        ->assertSet('data.internal_reference', 'BUY-042')
        ->set('data.internal_reference', '')
        ->set('data.purchase_format', '190 kg drum')
        ->set('data.net_quantity', '190')
        ->set('data.price_amount', '4.65')
        ->set('data.notes', 'Updated quotation')
        ->call('save')
        ->assertHasNoErrors();

    expect($listing->fresh())
        ->supplier_id->toBe($supplier->id)
        ->ingredient_id->toBe($ingredient->id)
        ->internal_reference->toBeNull()
        ->purchase_format->toBe('190 kg drum')
        ->net_quantity->toBe('190.000000000')
        ->price_amount->toBe('4.650000000')
        ->notes->toBe('Updated quotation');
});

it('keeps meaningful sub-cent precision when formatting a listing for editing', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->create([
        'ingredient_id' => null,
        'packaging_item_id' => $packaging->id,
        'unit_kind' => StockUnitKind::Count,
        'canonical_quantity_per_purchase_format' => '12',
        'net_quantity' => '12',
        'net_unit' => 'count',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '0.035',
        'price_unit' => 'count',
        'total_price' => '0.42',
    ]);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class, ['listing' => $listing->public_id])
        ->assertSet('data.net_quantity', '12')
        ->assertSet('data.price_amount', '0.035')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($listing->fresh())
        ->net_quantity->toBe('12.000000000')
        ->price_amount->toBe('0.035000000')
        ->and($packaging->fresh()->currentPrice?->price_per_canonical_unit)->toBe('0.035000000000');
});

it('accepts localized decimal quantities and prices', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'EUR']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $this->actingAs($owner);
    app()->setLocale('fr');

    Livewire::test(SupplierListingCreate::class)
        ->fillForm([
            'supplier_id' => $supplier->id,
            'material_type' => 'ingredient',
            'ingredient_id' => $ingredient->id,
            'purchase_format' => '200 kg drum',
            'net_quantity' => '200,00',
            'net_unit' => 'kg',
            'price_basis' => ListingPriceBasis::PerUnit->value,
            'price_amount' => '4,20',
            'price_unit' => 'kg',
            'currency' => 'EUR',
        ])
        ->assertSee('EUR 840.00 total')
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('production-bench.purchasing.listings'));

    expect(SupplierListing::query()->sole())
        ->net_quantity->toBe('200.000000000')
        ->price_amount->toBe('4.200000000')
        ->total_price->toBe('840.000000000');
});

it('locks supplier context and returns scoped packaging listings to supplier detail', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'BOTTLES', 'name' => 'Bottle House']);
    $packaging = PackagingItem::factory()->for($workspace)->create([
        'name' => 'Amber bottle',
        'material_code' => 'PK-BOT-250',
    ]);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$supplier->public_id.'/listings/new')
        ->assertOk()
        ->assertSee('BOTTLES')
        ->assertSee('Bottle House')
        ->assertSeeHtml('disabled')
        ->assertSeeHtml('href="'.route('production-bench.purchasing.supplier', $supplier).'"');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('data.supplier_id', $supplier->id)
        ->set('data.supplier_id', Supplier::factory()->for($workspace)->create()->id)
        ->set('data.material_type', 'packaging')
        ->set('data.packaging_item_id', $packaging->id)
        ->set('data.material_code', 'FORGED-CODE')
        ->set('data.purchase_format', 'Carton of 500')
        ->set('data.net_quantity', '500')
        ->set('data.price_basis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('data.price_amount', '90')
        ->set('data.currency', 'EUR')
        ->assertSee('EUR 0.18 / item')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('production-bench.purchasing.supplier', $supplier));

    $listing = SupplierListing::query()->firstOrFail();

    expect($listing->supplier_id)->toBe($supplier->id)
        ->and($listing->packaging_item_id)->toBe($packaging->id)
        ->and($listing->ingredient_id)->toBeNull()
        ->and($listing->net_unit)->toBe('count')
        ->and($listing->total_price)->toBe('90.000000000')
        ->and($packaging->fresh()->material_code)->toBe('PK-BOT-250');
});

it('uses the workspace mass basis for listing defaults and previews total prices', function (): void {
    [$owner, $workspace] = listingCreateWorkspace(MassDisplaySystem::UsCustomary);
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'USD']);
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('data.net_unit', 'lb')
        ->assertSet('data.price_unit', 'lb')
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', '50 lb pail')
        ->set('data.net_quantity', '50')
        ->set('data.net_unit', 'lb')
        ->set('data.price_basis', ListingPriceBasis::TotalPurchaseFormat->value)
        ->set('data.price_amount', '125')
        ->set('data.price_unit', '')
        ->set('data.currency', 'USD')
        ->assertSee('USD 2.50 / lb');
});

it('rejects a currency outside the maintained catalog', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg')
        ->set('data.currency', 'ZZZ')
        ->call('save')
        ->assertHasErrors(['data.currency']);

    expect(SupplierListing::query()->count())->toBe(0);
});

it('does not preselect a historical supplier currency for a new listing', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['default_currency' => 'HRK']);
    $this->actingAs($owner);

    Livewire::test(SupplierListingCreate::class)
        ->assertSet('data.currency', 'EUR')
        ->set('data.supplier_id', $supplier->id)
        ->assertSet('data.currency', 'EUR');

    Livewire::test(SupplierListingCreate::class, ['supplier' => $supplier->public_id])
        ->assertSet('data.currency', 'EUR');

    $historicalOwner = User::factory()->create();
    $historicalWorkspace = Workspace::factory()->for($historicalOwner, 'owner')->create(['default_currency' => 'HRK']);
    $historicalSupplier = Supplier::factory()->for($historicalWorkspace)->create(['default_currency' => 'HRK']);
    app(ProductionBenchAccess::class)->activate($historicalOwner, $historicalWorkspace);
    $this->actingAs($historicalOwner);

    Livewire::test(SupplierListingCreate::class, ['supplier' => $historicalSupplier->public_id])
        ->assertSet('data.currency', '')
        ->call('save')
        ->assertHasErrors(['data.currency' => 'required']);
});

it('bounds server-side catalog searches and retains selected rows', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $suppliers = Supplier::factory()->count(30)->for($workspace)->sequence(
        fn ($sequence): array => [
            'code' => sprintf('SUP-%02d', $sequence->index + 1),
            'name' => sprintf('Supplier %02d', $sequence->index + 1),
        ],
    )->create();
    $ingredients = Ingredient::factory()->count(30)->sequence(
        fn ($sequence): array => ['display_name' => sprintf('Ingredient %02d', $sequence->index + 1)],
    )->create();
    $packagingItems = PackagingItem::factory()->count(30)->for($workspace)->sequence(
        fn ($sequence): array => ['name' => sprintf('Packaging %02d', $sequence->index + 1)],
    )->create();
    $selectedSupplier = $suppliers->last();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class);

    expect($component->get('ingredientOptionLabels'))->toHaveCount(10)
        ->and($component->get('packagingOptionLabels'))->toHaveCount(10)
        ->and($component->instance()->supplierSearchResults(''))->toHaveCount(20)
        ->and($component->instance()->ingredientSearchResults(''))->toHaveCount(20)
        ->and($component->instance()->packagingSearchResults(''))->toBe([]);

    $component
        ->set('data.supplier_id', $selectedSupplier->id);

    expect($component->instance()->supplierSearchResults('no matching supplier'))->toBe([])
        ->and($component->instance()->supplierOptionLabel($selectedSupplier->id))->toBe($selectedSupplier->code.' · '.$selectedSupplier->name);

    $catalogQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$catalogQueries): void {
        $catalogQueries[] = $query->sql;
    });

    $component->instance()->ingredientSearchResults('Ingredient');

    expect(collect($catalogQueries)->contains(
        fn (string $sql): bool => Str::contains($sql, 'from "ingredients"')
            && Str::contains($sql, 'limit 20'),
    ))->toBeTrue()
        ->and(collect($catalogQueries)->contains(
            fn (string $sql): bool => Str::contains($sql, 'packaging_items'),
        ))->toBeFalse();

    $component
        ->set('data.ingredient_id', $ingredients->last()->id);

    expect($component->instance()->ingredientSearchResults('no matching ingredient'))->toBe([])
        ->and($component->instance()->ingredientOptionLabel($ingredients->last()->id))->toBe('Ingredient 30');

    $component->set('data.material_type', 'packaging');

    expect($component->instance()->ingredientSearchResults(''))->toBe([])
        ->and($component->instance()->packagingSearchResults(''))->toHaveCount(20);

    $component
        ->set('data.packaging_item_id', $packagingItems->last()->id);

    expect($component->instance()->packagingSearchResults('no matching packaging item'))->toBe([])
        ->and($component->instance()->packagingOptionLabel($packagingItems->last()->id))->toBe('Packaging 30');
});

it('uses postgres-compatible ordering for recent catalogue items', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    Supplier::factory()->for($workspace)->create();
    Ingredient::factory()->create();
    PackagingItem::factory()->for($workspace)->create();
    $this->actingAs($owner);
    $recentItemQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$recentItemQueries): void {
        if (Str::contains($query->sql, 'last_listing_at')) {
            $recentItemQueries[] = $query->sql;
        }
    });

    Livewire::test(SupplierListingCreate::class);

    expect($recentItemQueries)->toHaveCount(2);

    foreach ($recentItemQueries as $query) {
        expect($query)
            ->not->toContain('last_listing_at IS NULL')
            ->toContain('"last_listing_at" DESC NULLS LAST');
    }
});

it('does not requery supplier or material catalogs for price preview updates', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create(['code' => 'SELECTED', 'name' => 'Selected supplier']);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Selected ingredient']);
    Supplier::factory()->count(2)->for($workspace)->create();
    Ingredient::factory()->count(2)->create();
    PackagingItem::factory()->count(3)->for($workspace)->create();
    $this->actingAs($owner);

    $component = Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.ingredient_id', $ingredient->id);
    $selectorQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$selectorQueries): void {
        if (Str::contains($query->sql, ['suppliers', 'ingredients', 'ingredient_translations', 'packaging_items'])) {
            $selectorQueries[] = $query->sql;
        }
    });

    $component
        ->set('data.net_quantity', '2')
        ->set('data.price_amount', '4.25')
        ->assertSee('SELECTED · Selected supplier')
        ->assertSee('Selected ingredient');

    expect($selectorQueries)->toBe([]);
});

it('keeps supplier and material selection inside the current workspace', function (): void {
    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $foreignWorkspace = Workspace::factory()->create();
    $foreignSupplier = Supplier::factory()->for($foreignWorkspace)->create(['name' => 'Foreign Supplier']);
    $foreignIngredient = Ingredient::factory()->create([
        'display_name' => 'Foreign oil',
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $foreignWorkspace->id,
        'workspace_id' => $foreignWorkspace->id,
        'visibility' => Visibility::Private,
    ]);
    $foreignPackaging = PackagingItem::factory()->create(['name' => 'Foreign bottle']);
    $this->actingAs($owner);

    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$foreignSupplier->public_id.'/listings/new')
        ->assertNotFound();

    Livewire::test(SupplierListingCreate::class)
        ->assertDontSee('Foreign Supplier')
        ->assertDontSee('Foreign oil')
        ->assertDontSee('Foreign bottle')
        ->set('data.supplier_id', $foreignSupplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $foreignIngredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg')
        ->call('save')
        ->assertHasErrors('data.supplier_id');

    Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'packaging')
        ->set('data.packaging_item_id', $foreignPackaging->id)
        ->set('data.purchase_format', 'Carton')
        ->set('data.net_quantity', '1')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->call('save')
        ->assertHasErrors('data.packaging_item_id');

    expect(SupplierListing::query()->count())->toBe(0);
});

it('rejects inactive and read-only listing pages and blocks a later save', function (): void {
    $inactiveOwner = User::factory()->create();
    $inactiveWorkspace = Workspace::factory()->for($inactiveOwner, 'owner')->create();
    $inactiveSupplier = Supplier::factory()->for($inactiveWorkspace)->create();

    $this->actingAs($inactiveOwner)
        ->get('/dashboard/production-bench/purchasing/listings/new')
        ->assertForbidden();
    $this->get('/dashboard/production-bench/purchasing/suppliers/'.$inactiveSupplier->public_id.'/listings/new')
        ->assertForbidden();

    [$owner, $workspace] = listingCreateWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $this->actingAs($owner);
    $component = Livewire::test(SupplierListingCreate::class)
        ->set('data.supplier_id', $supplier->id)
        ->set('data.material_type', 'ingredient')
        ->set('data.ingredient_id', $ingredient->id)
        ->set('data.purchase_format', 'Drum')
        ->set('data.net_quantity', '1')
        ->set('data.net_unit', 'kg')
        ->set('data.price_basis', ListingPriceBasis::PerUnit->value)
        ->set('data.price_amount', '1')
        ->set('data.price_unit', 'kg');

    app(ProductionBenchAccess::class)->cancel($owner, $workspace);

    $this->get('/dashboard/production-bench/purchasing/listings/new')->assertForbidden();
    $component->call('save')->assertHasErrors('data.production_bench');

    expect(SupplierListing::query()->count())->toBe(0);
});

function listingDocumentPdf(): UploadedFile
{
    $content = "%PDF-1.4\n".str_repeat("% padding\n", 30720);
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>',
    ];
    $offsets = "0000000000 65535 f \n";
    foreach ($objects as $index => $object) {
        $offsets .= sprintf("%010d 00000 n \n", strlen($content));
        $number = $index + 1;
        $content .= "{$number} 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($content);
    $content .= "xref\n0 4\n{$offsets}trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

    return UploadedFile::fake()->createWithContent('safety-sheet.pdf', $content);
}

/** @return array{0: User, 1: Workspace} */
function listingCreateWorkspace(MassDisplaySystem $massDisplaySystem = MassDisplaySystem::Metric): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'mass_display_system' => $massDisplaySystem,
    ]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);

    return [$owner, $workspace];
}
