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

    // The overview is the exact destination, so it is the only `page`. Its
    // parent tab shares the same href and is marked as a `branch` instead.
    $assertInventoryNavigationIsActive = function (string $html): void {
        expect(substr_count($html, 'aria-current="page"'))->toBe(1)
            ->and(Str::of($html)->afterLast('href="'.route('production-bench.inventory').'"')->before('</a>')->toString())
            ->toContain('aria-current="page"')
            ->toContain('class="sk-nav-item is-active"')
            ->and($html)
            ->toContain('class="sk-nav-item is-branch"');
    };
    $component = Livewire::test(InventoryIndex::class, ['mode' => 'overview']);

    $assertInventoryNavigationIsActive($component->html());

    $component->refresh();

    $assertInventoryNavigationIsActive($component->html());
});

it('marks exactly one navigation entry current on every production bench page', function (string $routeName, string $currentRoute): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $html = $this->actingAs($user)
        ->get(route($routeName))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and(Str::of($html)->afterLast('href="'.route($currentRoute).'"')->before('</a>')->toString())
        ->toContain('aria-current="page"');
})->with([
    'dashboard' => ['production-bench.home', 'production-bench.home'],
    'inventory overview' => ['production-bench.inventory', 'production-bench.inventory'],
    'inventory stock' => ['production-bench.inventory.stock', 'production-bench.inventory.stock'],
    'inventory requirements' => ['production-bench.inventory.requirements', 'production-bench.inventory.requirements'],
    'production runs' => ['production-bench.production.index', 'production-bench.production.index'],
    'tasks' => ['production-bench.production.tasks', 'production-bench.production.tasks'],
    'flash planner' => ['production-bench.production.flash', 'production-bench.production.flash'],
    'production calendar' => ['production-bench.production.calendar', 'production-bench.production.calendar'],
    // The settings landing page shows every section, so no child route matches
    // and the Settings tab itself stays the current page.
    'settings landing' => ['production-bench.production.settings', 'production-bench.production.settings'],
    'settings numbering' => ['production-bench.production.settings.numbering', 'production-bench.production.settings.numbering'],
    'settings batch sizes' => ['production-bench.production.settings.presets', 'production-bench.production.settings.presets'],
    'settings task types' => ['production-bench.production.settings.task-types', 'production-bench.production.settings.task-types'],
    'settings task sets' => ['production-bench.production.settings.task-sets', 'production-bench.production.settings.task-sets'],
    'settings working calendar' => ['production-bench.production.settings.calendar', 'production-bench.production.settings.calendar'],
    'settings departments' => ['production-bench.production.settings.departments', 'production-bench.production.settings.departments'],
    'settings employees' => ['production-bench.production.settings.employees', 'production-bench.production.settings.employees'],
    'purchasing suppliers' => ['production-bench.purchasing.suppliers', 'production-bench.purchasing.suppliers'],
    'purchasing listings' => ['production-bench.purchasing.listings', 'production-bench.purchasing.listings'],
    'purchasing quotations' => ['production-bench.purchasing.quotations', 'production-bench.purchasing.quotations'],
    'purchasing orders' => ['production-bench.purchasing.orders', 'production-bench.purchasing.orders'],
    'purchasing receipts' => ['production-bench.purchasing.receipts', 'production-bench.purchasing.receipts'],
]);

it('leaves every settings child unmarked on the all sections landing page', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    $html = $this->actingAs($user)
        ->get(route('production-bench.production.settings'))
        ->assertOk()
        ->getContent();

    // The landing page renders all seven sections, so no child is the
    // destination. Marking one would announce `/settings/numbering` as the
    // current page while the browser sits on `/settings`.
    expect(Str::of($html)->after('sk-nav-rail')->before('</nav>')->toString())
        ->not->toContain('aria-current')
        ->and(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and(Str::of($html)->afterLast('href="'.route('production-bench.production.settings').'"')->before('</a>')->toString())
        ->toContain('aria-current="page"');
});

it('renders explicit production bench navigation state without relying on the request route', function (string $active, ?string $subnavigation, string $currentRoute, ?string $branchRoute): void {
    $html = Blade::render(
        '<x-production-bench.page :active="$active" :subnavigation="$subnavigation"><span>Content</span></x-production-bench.page>',
        compact('active', 'subnavigation'),
    );

    // Exactly one link is the page itself. Every ancestor is a branch, so the
    // parent tab and the child entry are never both announced as "page". Where
    // parent and child share an href (Inventory overview, Production runs,
    // Purchasing suppliers) the level 1 tab renders first, so `after` reads the
    // branch and `afterLast` reads the current entry.
    expect(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and(Str::of($html)->afterLast('href="'.route($currentRoute).'"')->before('</a>')->toString())
        ->toContain('aria-current="page"');

    if ($branchRoute !== null) {
        expect(Str::of($html)->after('href="'.route($branchRoute).'"')->before('</a>')->toString())
            ->toContain('aria-current="true"');
    }
})->with([
    'dashboard' => ['home', null, 'production-bench.home', null],
    'inventory overview' => ['inventory', 'overview', 'production-bench.inventory', 'production-bench.inventory'],
    'inventory stock' => ['inventory', 'stock', 'production-bench.inventory.stock', 'production-bench.inventory'],
    'inventory requirements' => ['inventory', 'requirements', 'production-bench.inventory.requirements', 'production-bench.inventory'],
    'production runs' => ['production', 'runs', 'production-bench.production.index', 'production-bench.production.index'],
    'production default' => ['production', null, 'production-bench.production.index', 'production-bench.production.index'],
    'tasks' => ['tasks', null, 'production-bench.production.tasks', 'production-bench.production.index'],
    'flash planner' => ['flash', null, 'production-bench.production.flash', 'production-bench.production.index'],
    'calendar' => ['calendar', null, 'production-bench.production.calendar', 'production-bench.production.index'],
    'purchasing suppliers' => ['purchasing', 'suppliers', 'production-bench.purchasing.suppliers', 'production-bench.purchasing.suppliers'],
    'purchasing listings' => ['purchasing', 'listings', 'production-bench.purchasing.listings', 'production-bench.purchasing.suppliers'],
    'purchasing quotations' => ['purchasing', 'quotations', 'production-bench.purchasing.quotations', 'production-bench.purchasing.suppliers'],
    'purchasing orders' => ['purchasing', 'orders', 'production-bench.purchasing.orders', 'production-bench.purchasing.suppliers'],
    'purchasing receipts' => ['purchasing', 'receipts', 'production-bench.purchasing.receipts', 'production-bench.purchasing.suppliers'],
    // `subnavigation` null means "no child is the destination", so the Settings
    // tab is the page and there is no branch to mark.
    'setup all sections' => ['production-setup', null, 'production-bench.production.settings', null],
    'setup numbering' => ['production-setup', 'numbering', 'production-bench.production.settings.numbering', 'production-bench.production.settings'],
    'setup presets' => ['production-setup', 'presets', 'production-bench.production.settings.presets', 'production-bench.production.settings'],
    'setup task types' => ['production-setup', 'task-types', 'production-bench.production.settings.task-types', 'production-bench.production.settings'],
    'setup task sets' => ['production-setup', 'task-sets', 'production-bench.production.settings.task-sets', 'production-bench.production.settings'],
    'setup working calendar' => ['production-setup', 'calendar', 'production-bench.production.settings.calendar', 'production-bench.production.settings'],
    'setup departments' => ['production-setup', 'departments', 'production-bench.production.settings.departments', 'production-bench.production.settings'],
    'setup employees' => ['production-setup', 'employees', 'production-bench.production.settings.employees', 'production-bench.production.settings'],
]);

it('renders a nested second row for every branch destination', function (string $active): void {
    $html = Blade::render(
        '<x-production-bench.page :active="$active"><span>Content</span></x-production-bench.page>',
        ['active' => $active],
    );

    expect($html)
        ->toContain('class="sk-nav-row sk-nav-rail"')
        ->toContain('data-level="2"');
})->with(['inventory', 'production', 'tasks', 'flash', 'calendar', 'purchasing', 'production-setup']);

it('keeps the dashboard first and pins settings to the trailing edge of the top level row', function (): void {
    $html = Blade::render('<x-production-bench.page><span>Content</span></x-production-bench.page>');

    $dashboardHref = 'href="'.route('production-bench.home').'"';
    $settingsHref = 'href="'.route('production-bench.production.settings').'"';

    expect(strpos($html, $dashboardHref))->toBeLessThan(strpos($html, $settingsHref))
        ->and(Str::of($html)->after($dashboardHref)->before('</a>')->toString())
        ->toContain('data-nav-divider="true"')
        ->and(Str::of($html)->after($settingsHref)->before('</a>')->toString())
        ->toContain('data-nav-end="true"');
});

it('lets the top level row span its width so the trailing edge pin has space to act on', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // `margin-inline-start: auto` on `[data-nav-end]` only pushes Settings right
    // if the cluster it sits in spans the row. Left content-sized, the cluster
    // leaves the slack in `.sk-nav-row` where the auto margin cannot reach it,
    // and Settings sits inline beside Purchasing. Asserted against the
    // stylesheet because PHPUnit cannot compute layout.
    expect($css)->toMatch("/\.sk-nav-row\[data-level='1'\] > \.sk-nav-cluster \{[^}]*flex: 1 1 auto;/");
});

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
    $items = file_get_contents(resource_path('views/components/production-bench/navigation-items.blade.php'));

    expect($navigation)
        ->not->toContain('overflow-x-auto')
        ->not->toContain('-mb-px')
        ->and($items)
        ->toContain('aria-current="page"')
        ->toContain('wire:navigate');
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
