<?php

use App\Livewire\ProductionBench\InventoryIndex;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses one stable page shell across production bench routes', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $this->actingAs($user);

    foreach ([
        'production-bench.home',
        'production-bench.inventory',
        'production-bench.purchasing.suppliers',
        'production-bench.purchasing.listings',
        'production-bench.purchasing.suppliers.create',
        'production-bench.purchasing.listings.create',
        'production-bench.purchasing.supplier' => ['supplier' => $supplier],
        'production-bench.purchasing.suppliers.edit' => ['supplier' => $supplier],
    ] as $routeName => $parameters) {
        if (is_int($routeName)) {
            $routeName = $parameters;
            $parameters = [];
        }

        $this->get(route($routeName, $parameters))
            ->assertOk()
            ->assertSeeHtml('data-production-bench-page')
            ->assertSeeHtml('max-w-app');
    }
});

it('keeps the inventory overview navigation visibly selected across live updates', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $this->actingAs($user);

    $assertInventoryNavigationIsActive = function (string $html): void {
        expect(substr_count($html, 'aria-current="page"'))->toBe(2)
            ->and($html)
            ->toContain('border-[var(--color-accent)] text-[var(--color-ink-strong)]')
            ->toContain('bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]');
    };
    $component = Livewire::test(InventoryIndex::class, ['mode' => 'overview']);

    $assertInventoryNavigationIsActive($component->html());

    $component->refresh();

    $assertInventoryNavigationIsActive($component->html());
});

it('renders explicit production bench navigation state without relying on the request route', function (string $active, ?string $subnavigation, array $currentRoutes): void {
    $html = Blade::render(
        '<x-production-bench.page :active="$active" :subnavigation="$subnavigation"><span>Content</span></x-production-bench.page>',
        compact('active', 'subnavigation'),
    );

    expect(substr_count($html, 'aria-current="page"'))->toBe(count($currentRoutes));

    foreach ($currentRoutes as $currentRoute) {
        $link = Str::of($html)
            ->after('href="'.route($currentRoute).'"')
            ->before('</a>');

        expect($link->toString())->toContain('aria-current="page"');
    }
})->with([
    'home' => ['home', null, ['production-bench.home']],
    'inventory stock' => ['inventory', 'stock', ['production-bench.inventory', 'production-bench.inventory.stock']],
    'production workflow' => ['production', null, ['production-bench.production.index']],
    'tasks' => ['tasks', null, ['production-bench.production.tasks']],
    'flash planner' => ['flash', null, ['production-bench.production.flash']],
    'calendar' => ['calendar', null, ['production-bench.production.calendar']],
    'purchasing suppliers' => ['purchasing', 'suppliers', ['production-bench.purchasing.suppliers', 'production-bench.purchasing.suppliers']],
    'purchasing listings' => ['purchasing', 'listings', ['production-bench.purchasing.suppliers', 'production-bench.purchasing.listings']],
    'purchasing quotations' => ['purchasing', 'quotations', ['production-bench.purchasing.suppliers', 'production-bench.purchasing.quotations']],
    'purchasing orders' => ['purchasing', 'orders', ['production-bench.purchasing.suppliers', 'production-bench.purchasing.orders']],
    'purchasing receipts' => ['purchasing', 'receipts', ['production-bench.purchasing.suppliers', 'production-bench.purchasing.receipts']],
    'setup numbering' => ['production-setup', 'numbering', ['production-bench.production.settings.presets', 'production-bench.production.settings.numbering']],
    'setup presets' => ['production-setup', 'presets', ['production-bench.production.settings.presets', 'production-bench.production.settings.presets']],
    'setup departments' => ['production-setup', 'departments', ['production-bench.production.settings.presets', 'production-bench.production.settings.departments']],
    'setup employees' => ['production-setup', 'employees', ['production-bench.production.settings.presets', 'production-bench.production.settings.employees']],
    'setup task types' => ['production-setup', 'task-types', ['production-bench.production.settings.presets', 'production-bench.production.settings.task-types']],
    'setup task sets' => ['production-setup', 'task-sets', ['production-bench.production.settings.presets', 'production-bench.production.settings.task-sets']],
    'setup calendar' => ['production-setup', 'calendar', ['production-bench.production.settings.presets', 'production-bench.production.settings.calendar']],
]);

it('declares durable navigation state in every production bench Livewire view', function (): void {
    $viewExpectations = [
        'home-index.blade.php' => ['active="home"'],
        'inventory-index.blade.php' => ['active="inventory"', 'subnavigation'],
        'production/production-index.blade.php' => ['active="production"'],
        'production/production-create.blade.php' => ['active="production"'],
        'production/production-detail.blade.php' => ['active="production"'],
        'production/stock-preparation.blade.php' => ['active="production"'],
        'production/task-index.blade.php' => ['active="tasks"'],
        'production/flash-planner.blade.php' => ['active="flash"'],
        'production/production-calendar.blade.php' => ['active="calendar"'],
        'production/numbering-settings.blade.php' => ['active="production-setup"', 'subnavigation'],
        'production/settings-index.blade.php' => ['active="production-setup"', 'subnavigation'],
        'production/batch-size-index.blade.php' => ['active="production-setup"', 'subnavigation'],
        'production/batch-size-form.blade.php' => ['active="production-setup"', 'subnavigation'],
        'production/task-set-index.blade.php' => ['active="production-setup"', 'subnavigation'],
        'production/task-set-form.blade.php' => ['active="production-setup"', 'subnavigation'],
        'purchasing-index.blade.php' => ['active="purchasing"'],
        'purchasing/supplier-index.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/supplier-create.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/supplier-detail.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/supplier-edit.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/supplier-listing-index.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/supplier-listing-create.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/procurement-index.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/procurement-create.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/procurement-detail.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/receipt-index.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/receipt-create.blade.php' => ['active="purchasing"', 'subnavigation'],
        'purchasing/receipt-detail.blade.php' => ['active="purchasing"', 'subnavigation'],
    ];

    foreach ($viewExpectations as $view => $expectations) {
        $contents = file_get_contents(resource_path('views/livewire/production-bench/'.$view));

        expect($contents)->toContain(...$expectations);
    }
});

it('keeps the production bench navigation out of a scroll container', function (): void {
    $navigation = file_get_contents(resource_path('views/components/production-bench/navigation.blade.php'));

    expect($navigation)
        ->not->toContain('overflow-x-auto')
        ->not->toContain('-mb-px')
        ->toContain('aria-current="page"');
});

it('reserves the document scrollbar gutter', function (): void {
    $stylesheet = file_get_contents(resource_path('css/app.css'));

    expect($stylesheet)->toContain('scrollbar-gutter: stable');
});

it('defines the load-bearing --container-app token that max-w-app depends on', function (): void {
    $stylesheet = file_get_contents(resource_path('css/shared/soapkraft.css'));

    expect($stylesheet)->toContain('--container-app: 74rem');
});

it('uses one consistent full-width inner across all production bench pages', function (): void {
    $page = file_get_contents(resource_path('views/components/production-bench/page.blade.php'));

    expect($page)
        ->toContain('space-y-8')
        ->not->toContain('$compact')
        ->not->toContain('max-w-5xl');
});
