<?php

use App\Enums\HelpTopicDomain;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Livewire\ProductionBench\InventoryIndex;
use App\Livewire\ProductionBench\InventoryMaterialDetail;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\InventoryHelpTopics;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publishedInventoryHelp(string $key): HelpTopicLocale
{
    $topic = HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::ProductionInventory]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $revision = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Published inventory answer']);
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);

    return $locale;
}

it('limits inventory indexes to six topics and hides optional location guidance', function (): void {
    $topics = app(InventoryHelpTopics::class);
    foreach (['materials', 'stock', 'detail'] as $surface) {
        expect($topics->forSurface($surface, false)['index'])->toHaveCount(6);
        expect($topics->forSurface($surface, false)['keys'])->not->toContain('inventory.storage_locations');
    }
    expect($topics->forSurface('detail', true)['keys'])->toContain('inventory.storage_locations', 'inventory.buffer_stock');
    expect($topics->forSurface('stock', true)['keys'])->toContain('inventory.storage_locations');
    expect($topics->forSurface('materials', true)['keys'])->not->toContain('inventory.storage_locations', 'inventory.adjustments');
    expect($topics->forSurface('unknown', true)['keys'])->toBe([]);
});

it('shows published inventory help on each surface without exposing newer drafts', function (string $surface): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $locale = publishedInventoryHelp('inventory.quantities');
    $draft = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['revision_number' => 2, 'title' => 'Private inventory wording']);
    $locale->update(['latest_revision_id' => $draft->id]);
    $ingredient = Ingredient::factory()->create();
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $this->actingAs($user);
    $component = $surface === 'detail'
        ? Livewire::test(InventoryMaterialDetail::class, ['subjectType' => 'ingredient', 'subject' => $ingredient->public_id])
        : Livewire::test(InventoryIndex::class, ['mode' => $surface]);

    $component->assertSee('data-contextual-help-scope', false)->assertSee('data-help-index', false)
        ->assertSee('data-help-key="inventory.quantities"', false)
        ->assertSee('Published inventory answer')->assertDontSee('Private inventory wording');
    $component->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['inventory'] === ['inventory.quantities']);
})->with(['materials', 'stock', 'detail']);

it('keeps help available on a read-only bench and removes unpublished and disabled help', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $access = app(ProductionBenchAccess::class);
    $access->activate($user, $workspace);
    $access->cancel($user, $workspace);
    $locale = publishedInventoryHelp('inventory.quantities');
    $this->actingAs($user);

    Livewire::test(InventoryIndex::class)->assertSee('data-help-index', false);
    $locale->update(['published_revision_id' => null]);
    Livewire::test(InventoryIndex::class)->assertDontSee('data-help-index', false)->assertDontSee('Published inventory answer');
    $locale->update(['published_revision_id' => $locale->latest_revision_id]);
    config(['contextual-help.enabled' => false]);
    Livewire::test(InventoryIndex::class)->assertDontSee('data-help-index', false);
});

it('loads published help in a fixed number of queries for a full lot page', function (int $perPage): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    StockLot::factory()->for($workspace)->for(Ingredient::factory()->create())->released()->count($perPage)->create();
    publishedInventoryHelp('inventory.quantities');
    publishedInventoryHelp('inventory.reservations');
    $this->actingAs($user);
    $component = Livewire::test(InventoryIndex::class, ['mode' => 'stock']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $component->set('perPage', $perPage)->set('lotScope', 'all');
        DB::flushQueryLog();
        $component->call('$refresh')->assertSee('data-help-key="inventory.quantities"', false);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "help_topic'));
        expect($queries)->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
})->with([25, 100]);

it('lets the admin find inventory drafts and see their page locations', function (): void {
    $locale = publishedInventoryHelp('inventory.quantities');
    $workbench = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->filterTable('domain', 'production_inventory')
        ->assertCanSeeTableRecords([$locale->topic])->assertCanNotSeeTableRecords([$workbench]);
    $page = Livewire::test(EditHelpTopic::class, ['record' => $locale->topic->public_id]);
    expect($page->instance()->editorReference()['locations'])->toContain('Inventory · Stock by material', 'Inventory · Lot Register', 'Inventory · Material details');
});
