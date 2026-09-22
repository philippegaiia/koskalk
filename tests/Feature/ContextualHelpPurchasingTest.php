<?php

use App\Enums\HelpTopicDomain;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Livewire\ProductionBench\Purchasing\ProcurementCreate;
use App\Livewire\ProductionBench\Purchasing\ProcurementDetail;
use App\Livewire\ProductionBench\Purchasing\ProcurementIndex;
use App\Livewire\ProductionBench\Purchasing\ReceiptCreate;
use App\Livewire\ProductionBench\Purchasing\ReceiptDetail;
use App\Livewire\ProductionBench\Purchasing\ReceiptIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierCreate;
use App\Livewire\ProductionBench\Purchasing\SupplierDetail;
use App\Livewire\ProductionBench\Purchasing\SupplierEdit;
use App\Livewire\ProductionBench\Purchasing\SupplierIndex;
use App\Livewire\ProductionBench\Purchasing\SupplierListingCreate;
use App\Livewire\ProductionBench\Purchasing\SupplierListingIndex;
use App\Models\GoodsReceipt;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\PurchasingHelpTopics;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publishedPurchasingHelp(string $key): HelpTopicLocale
{
    $topic = HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::ProductionPurchasing]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $revision = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Published purchasing guidance']);
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);

    return $locale;
}

it('keeps purchasing menus relevant and limited to six topics', function (): void {
    $topics = app(PurchasingHelpTopics::class);
    expect($topics->forSurface('suppliers'))->toHaveCount(3)->toContain('purchasing.suppliers')->not->toContain('purchasing.corrections');
    expect($topics->forSurface('listings'))->toHaveCount(3)->toContain('purchasing.purchase_formats')->not->toContain('purchasing.partial_deliveries');
    expect($topics->forSurface('procurement'))->toHaveCount(6)->toContain('purchasing.quotations', 'purchasing.incoming_stock');
    expect($topics->forSurface('receipts'))->toHaveCount(6)->toContain('purchasing.partial_deliveries', 'purchasing.receipt_documents');
    expect($topics->forSurface('unknown'))->toBe([]);
});

it('renders published help on purchasing pages without exposing newer drafts', function (Closure $surface): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $locale = publishedPurchasingHelp('purchasing.prices_and_currency');
    $draft = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Private purchasing draft']);
    $locale->update(['latest_revision_id' => $draft->id]);
    $this->actingAs($user);
    [$class, $parameters] = $surface($workspace);

    Livewire::test($class, $parameters)->assertSee('data-help-index', false)
        ->assertSee('data-contextual-help-scope', false)->assertSee('Published purchasing guidance')
        ->assertDontSee('Private purchasing draft')
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['purchasing'] === ['purchasing.prices_and_currency']);
})->with([
    'supplier register' => [fn (Workspace $workspace): array => [SupplierIndex::class, []]],
    'new supplier' => [fn (Workspace $workspace): array => [SupplierCreate::class, []]],
    'edit supplier' => [fn (Workspace $workspace): array => [SupplierEdit::class, ['supplier' => Supplier::factory()->for($workspace)->create()->public_id]]],
    'supplier detail' => [fn (Workspace $workspace): array => [SupplierDetail::class, ['supplier' => Supplier::factory()->for($workspace)->create()->public_id]]],
    'listing register' => [fn (Workspace $workspace): array => [SupplierListingIndex::class, []]],
    'listing editor' => [fn (Workspace $workspace): array => [SupplierListingCreate::class, []]],
    'quotation register' => [fn (Workspace $workspace): array => [ProcurementIndex::class, ['stage' => 'quotation']]],
    'order register' => [fn (Workspace $workspace): array => [ProcurementIndex::class, ['stage' => 'purchase_order']]],
    'new quotation' => [fn (Workspace $workspace): array => [ProcurementCreate::class, ['stage' => 'quotation']]],
    'new order' => [fn (Workspace $workspace): array => [ProcurementCreate::class, ['stage' => 'purchase_order']]],
    'order detail' => [fn (Workspace $workspace): array => [ProcurementDetail::class, ['purchaseOrder' => PurchaseOrder::factory()->for($workspace)->for(Supplier::factory()->for($workspace))->create()->public_id]]],
    'receipt register' => [fn (Workspace $workspace): array => [ReceiptIndex::class, []]],
    'new receipt' => [fn (Workspace $workspace): array => [ReceiptCreate::class, []]],
    'receipt detail' => [fn (Workspace $workspace): array => [ReceiptDetail::class, ['goodsReceipt' => GoodsReceipt::factory()->for($workspace)->for(Supplier::factory()->for($workspace))->create()->public_id]]],
]);

it('keeps read-only help available and hides unpublished or disabled topics', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $access = app(ProductionBenchAccess::class);
    $access->activate($user, $workspace);
    $access->cancel($user, $workspace);
    $locale = publishedPurchasingHelp('purchasing.receiving_deliveries');
    $this->actingAs($user);

    Livewire::test(ReceiptIndex::class)->assertSee('data-help-index', false);
    $locale->update(['published_revision_id' => null]);
    Livewire::test(ReceiptIndex::class)->assertDontSee('data-help-index', false)->assertDontSee('Published purchasing guidance');
    $locale->update(['published_revision_id' => $locale->latest_revision_id]);
    config(['contextual-help.enabled' => false]);
    Livewire::test(ReceiptIndex::class)->assertDontSee('data-help-index', false);
});

it('loads purchasing help in three queries regardless of order page size', function (int $perPage): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    PurchaseOrder::factory()->for($workspace)->for($supplier)->count($perPage)->create();
    publishedPurchasingHelp('purchasing.purchase_orders');
    publishedPurchasingHelp('purchasing.prices_and_currency');
    $this->actingAs($user);
    $component = Livewire::test(ProcurementIndex::class, ['stage' => 'purchase_order'])->set('perPage', $perPage);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $component->call('$refresh')->assertSee('data-help-index', false);
        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "help_topic')))->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
})->with([25, 100]);

it('provides purchasing filters and page locations in the admin editor', function (): void {
    $locale = publishedPurchasingHelp('purchasing.receiving_deliveries');
    $other = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->filterTable('domain', 'production_purchasing')
        ->assertCanSeeTableRecords([$locale->topic])->assertCanNotSeeTableRecords([$other]);
    $page = Livewire::test(EditHelpTopic::class, ['record' => $locale->topic->public_id]);
    expect($page->instance()->editorReference()['locations'])->toBe(['Purchasing · Goods receipts']);
});
