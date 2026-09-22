<?php

use App\Enums\HelpTopicDomain;
use App\Filament\Resources\HelpTopics\Pages\EditHelpTopic;
use App\Filament\Resources\HelpTopics\Pages\ListHelpTopics;
use App\Livewire\ProductionBench\Production\BatchSizeForm;
use App\Livewire\ProductionBench\Production\BatchSizeIndex;
use App\Livewire\ProductionBench\Production\FlashPlanner;
use App\Livewire\ProductionBench\Production\ProductionCalendar;
use App\Livewire\ProductionBench\Production\ProductionCreate;
use App\Livewire\ProductionBench\Production\ProductionDetail;
use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Livewire\ProductionBench\Production\StockPreparation;
use App\Livewire\ProductionBench\Production\TaskIndex;
use App\Livewire\ProductionBench\Production\TaskSetForm;
use App\Livewire\ProductionBench\Production\TaskSetIndex;
use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextualHelp\ProductionHelpTopics;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function publishedProductionHelp(string $key): HelpTopicLocale
{
    $topic = HelpTopic::factory()->create(['key' => $key, 'domain' => HelpTopicDomain::ProductionRuns]);
    $locale = HelpTopicLocale::factory()->for($topic, 'topic')->create();
    $revision = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Published production guidance']);
    $locale->update(['latest_revision_id' => $revision->id, 'published_revision_id' => $revision->id]);

    return $locale;
}

it('keeps production menus short while retaining specific inline help', function (): void {
    $topics = app(ProductionHelpTopics::class);
    foreach (['index', 'create', 'detail', 'stock', 'calendar', 'flash', 'tasks', 'presets', 'task_sets'] as $surface) {
        $scope = $topics->forSurface($surface);
        expect(count($scope['index']))->toBeLessThanOrEqual(6)->toBeGreaterThan(0);
        expect(array_diff($scope['index'], $scope['keys']))->toBe([]);
    }
    expect($topics->forSurface('detail')['keys'])->toContain('production.journal', 'production.formula_snapshot', 'production.tasks');
    expect($topics->forSurface('detail')['index'])->not->toContain('production.journal', 'production.formula_snapshot', 'production.tasks');
    expect($topics->forSurface('unknown'))->toBe(['keys' => [], 'index' => []]);
});

it('renders published help on production pages without exposing newer drafts', function (Closure $surface, string $key): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $locale = publishedProductionHelp($key);
    $draft = HelpTopicRevision::factory()->for($locale, 'topicLocale')->create(['title' => 'Private production draft']);
    $locale->update(['latest_revision_id' => $draft->id]);
    $this->actingAs($user);
    [$class, $parameters] = $surface($workspace);

    Livewire::test($class, $parameters)->assertSee('data-help-index', false)
        ->assertSee('data-contextual-help-scope', false)->assertSee('Published production guidance')
        ->assertDontSee('Private production draft')
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['production'] === [$key]);
})->with([
    'batch register' => [fn (Workspace $workspace): array => [ProductionIndex::class, []], 'production.planning'],
    'plan batch' => [fn (Workspace $workspace): array => [ProductionCreate::class, []], 'production.planning'],
    'batch detail' => [fn (Workspace $workspace): array => [ProductionDetail::class, ['productionId' => ProductionRun::factory()->for($workspace)->create()->public_id]], 'production.planning'],
    'stock preparation' => [fn (Workspace $workspace): array => [StockPreparation::class, []], 'production.stock_preparation'],
    'calendar' => [fn (Workspace $workspace): array => [ProductionCalendar::class, []], 'production.scheduling'],
    'flash planner' => [fn (Workspace $workspace): array => [FlashPlanner::class, []], 'production.flash_planning'],
    'tasks' => [fn (Workspace $workspace): array => [TaskIndex::class, []], 'production.tasks'],
    'batch sizes' => [fn (Workspace $workspace): array => [BatchSizeIndex::class, []], 'production.batch_size'],
    'batch size editor' => [fn (Workspace $workspace): array => [BatchSizeForm::class, []], 'production.batch_size'],
    'task sets' => [fn (Workspace $workspace): array => [TaskSetIndex::class, []], 'production.task_sets'],
    'task set editor' => [fn (Workspace $workspace): array => [TaskSetForm::class, []], 'production.task_sets'],
]);

it('keeps read-only help available and hides unpublished or disabled topics', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    $access = app(ProductionBenchAccess::class);
    $access->activate($user, $workspace);
    $access->cancel($user, $workspace);
    $locale = publishedProductionHelp('production.planning');
    $this->actingAs($user);

    Livewire::test(ProductionIndex::class)->assertSee('data-help-index', false);
    $locale->update(['published_revision_id' => null]);
    Livewire::test(ProductionIndex::class)->assertDontSee('data-help-index', false)->assertDontSee('Published production guidance');
    $locale->update(['published_revision_id' => $locale->latest_revision_id]);
    config(['contextual-help.enabled' => false]);
    Livewire::test(ProductionIndex::class)->assertDontSee('data-help-index', false);
});

it('loads production help in three queries regardless of batch page size', function (int $perPage): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    ProductionRun::factory()->for($workspace)->count($perPage)->create();
    publishedProductionHelp('production.planning');
    publishedProductionHelp('production.batch_size');
    $this->actingAs($user);
    $component = Livewire::test(ProductionIndex::class)->set('perPage', $perPage);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $component->call('$refresh')->assertSee('data-help-index', false);
        expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "help_topic')))->toHaveCount(3);
    } finally {
        DB::disableQueryLog();
    }
})->with([25, 100]);

it('provides production filters and page locations in the admin editor', function (): void {
    $locale = publishedProductionHelp('production.planning');
    $other = HelpTopic::factory()->create(['key' => 'soap.water_mode']);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListHelpTopics::class)->filterTable('domain', 'production_runs')
        ->assertCanSeeTableRecords([$locale->topic])->assertCanNotSeeTableRecords([$other]);
    $page = Livewire::test(EditHelpTopic::class, ['record' => $locale->topic->public_id]);
    expect($page->instance()->editorReference()['locations'])->toBe(['Production · Batches', 'Production · Plan a batch', 'Production · Batch details', 'Production · Calendar']);
});

it('resolves inline-only topics without adding them to the main menu', function (): void {
    publishedProductionHelp('production.planning');
    publishedProductionHelp('production.journal');
    $help = app(ProductionHelpTopics::class)->resolve('detail', 'en');
    expect($help['tabs']['production'])->toBe(['production.planning']);
    expect($help['topics'])->toHaveKeys(['production.planning', 'production.journal']);
});

it('offers journal help beside the journal even when no menu topic is published', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $production = ProductionRun::factory()->for($workspace)->create();
    publishedProductionHelp('production.journal');
    $this->actingAs($user);

    Livewire::test(ProductionDetail::class, ['productionId' => $production->public_id])
        ->assertSee('data-help-key="production.journal"', false)
        ->assertDontSee('data-help-index', false)
        ->assertViewHas('contextualHelp', fn (array $help): bool => $help['tabs']['production'] === []);
});
