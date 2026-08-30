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
    'settings numbering' => ['production-bench.production.settings.numbering', 'production-bench.production.settings.numbering'],
    'settings batch sizes' => ['production-bench.production.settings.presets', 'production-bench.production.settings.presets'],
    'settings task types' => ['production-bench.production.settings.task-types', 'production-bench.production.settings.task-types'],
    'settings task sets' => ['production-bench.production.settings.task-sets', 'production-bench.production.settings.task-sets'],
    'settings working calendar' => ['production-bench.production.settings.calendar', 'production-bench.production.settings.calendar'],
    'settings departments' => ['production-bench.production.settings.departments', 'production-bench.production.settings.departments'],
    'settings employees' => ['production-bench.production.settings.employees', 'production-bench.production.settings.employees'],
    'settings ready dates' => ['production-bench.production.settings.ready-dates', 'production-bench.production.settings.ready-dates'],
    'purchasing suppliers' => ['production-bench.purchasing.suppliers', 'production-bench.purchasing.suppliers'],
    'purchasing listings' => ['production-bench.purchasing.listings', 'production-bench.purchasing.listings'],
    'purchasing quotations' => ['production-bench.purchasing.quotations', 'production-bench.purchasing.quotations'],
    'purchasing orders' => ['production-bench.purchasing.orders', 'production-bench.purchasing.orders'],
    'purchasing receipts' => ['production-bench.purchasing.receipts', 'production-bench.purchasing.receipts'],
]);

it('sends the settings landing url to the numbering page', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    // The tab no longer renders every section at once. The old URL survives
    // only so bookmarks resolve, and forwards to Numbering, which is the
    // group's first child and therefore the tab's own destination.
    $this->actingAs($user)
        ->get(route('production-bench.production.settings'))
        ->assertRedirect(route('production-bench.production.settings.numbering'));
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
    // The Settings tab shares its href with Numbering, its first child, so
    // `subnavigation` null resolves to Numbering and the tab is a branch —
    // the same shape as Inventory overview and Production runs.
    'setup default' => ['production-setup', null, 'production-bench.production.settings.numbering', 'production-bench.production.settings.numbering'],
    'setup numbering' => ['production-setup', 'numbering', 'production-bench.production.settings.numbering', 'production-bench.production.settings.numbering'],
    'setup presets' => ['production-setup', 'presets', 'production-bench.production.settings.presets', 'production-bench.production.settings.numbering'],
    'setup task types' => ['production-setup', 'task-types', 'production-bench.production.settings.task-types', 'production-bench.production.settings.numbering'],
    'setup task sets' => ['production-setup', 'task-sets', 'production-bench.production.settings.task-sets', 'production-bench.production.settings.numbering'],
    'setup working calendar' => ['production-setup', 'calendar', 'production-bench.production.settings.calendar', 'production-bench.production.settings.numbering'],
    'setup ready dates' => ['production-setup', 'ready-dates', 'production-bench.production.settings.ready-dates', 'production-bench.production.settings.numbering'],
    'setup departments' => ['production-setup', 'departments', 'production-bench.production.settings.departments', 'production-bench.production.settings.numbering'],
    'setup employees' => ['production-setup', 'employees', 'production-bench.production.settings.employees', 'production-bench.production.settings.numbering'],
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

it('keeps the dashboard first and sets settings apart at the end of the top level row', function (): void {
    $html = Blade::render('<x-production-bench.page><span>Content</span></x-production-bench.page>');

    $dashboardHref = 'href="'.route('production-bench.home').'"';
    // The Settings tab links to Numbering, its first child, which is also where
    // the old `/settings` URL now redirects.
    $settingsHref = 'href="'.route('production-bench.production.settings.numbering').'"';

    expect(strpos($html, $dashboardHref))->toBeLessThan(strpos($html, $settingsHref))
        ->and(Str::of($html)->after($dashboardHref)->before('</a>')->toString())
        ->toContain('data-nav-divider="true"')
        ->and(Str::of($html)->after($settingsHref)->before('</a>')->toString())
        ->toContain('data-nav-end="true"');
});

it('stacks one nav cluster per line and gives each group label a line of its own', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // Two clusters sharing a row read as one run of chips, because the only
    // thing between them is a gap. Stacking the row puts the boundary in
    // position instead, and `flex-basis: 100%` on the label is what stops a
    // stacked cluster's items from starting at an offset set by its label's
    // translation. Asserted against the stylesheet because PHPUnit cannot
    // compute layout.
    expect($css)
        ->toMatch("/\.sk-nav-row \{[^}]*flex-direction: column;/")
        ->and($css)
        ->toMatch("/\.sk-nav-cluster > \.sk-nav-group-label \{[^}]*flex: 1 0 100%;/");
});

it('does not draw a rule under the whole navigation', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // Whichever row renders last already carries its own edge: the level-1 tabs
    // have a 2px underline and the level-2 rail is a raised panel. A rule on the
    // container sits directly under one of them and reads as a double line.
    expect(Str::of($css)->after('.sk-nav {')->before('}')->toString())
        ->not->toContain('border-bottom');
});

it('shares the content left edge instead of indenting the navigation', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // The navigation sits inside the same `max-w-app` wrapper as the content, so
    // any inline-start offset on a row pulls its left edge inboard while its
    // right edge stays flush — the menu then reads as right-aligned. The rail is
    // a raised panel; the shadow and the panel fill carry the recess, not an
    // offset. Asserted against the stylesheet because PHPUnit cannot compute
    // layout.
    preg_match_all('/\.sk-nav-rail\s*\{([^}]*)\}/', $css, $railBlocks);

    expect(implode(' ', $railBlocks[1] ?? []))
        ->not->toContain('margin-inline-start')
        // Level-1 tabs carry their own `padding-inline`; padding on the row would
        // double-indent them off the content edge.
        ->and($css)
        ->not->toMatch("/\.sk-nav-row\[data-level='1'\] \{[^}]*padding-inline/");
});

it('raises the navigation drawer and the table panels by shadow, not by a border', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // Every other surface in the app is lifted off the page by shadow rather
    // than outlined, and a 1px rule around a full-width strip reads as a
    // hairline box instead of a panel (owner override O6). The rail keeps its
    // start padding — that is now what sets the level-2 tabs inboard of the
    // level-1 tabs above them, a job the 2px start border used to do.
    preg_match_all('/\.sk-nav-rail\s*\{([^}]*)\}/', $css, $railBlocks);
    $rail = implode(' ', $railBlocks[1] ?? []);

    expect($rail)
        ->toContain('box-shadow: var(--shadow-card)')
        ->not->toContain('border:')
        ->not->toContain('border-inline-start-width')
        // The nesting cue, not an offset: it survives the border's removal.
        ->toContain('padding-inline: 1.125rem 0.75rem');

    // The table panels borrow `.sk-card` wholesale, so they must not re-add an
    // outline of their own — internal `border-b` / `divide-y` dividers are
    // separate and stay. Scoped to the old table-shell pattern on purpose: the
    // flash planner's celebration banner keeps its accent border, which is a
    // signal rather than a panel edge.
    $outlinedPanels = [];

    foreach (productionBenchViews() as $view) {
        if (str_contains(
            (string) file_get_contents($view),
            'rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]',
        )) {
            $outlinedPanels[] = basename($view);
        }
    }

    expect($outlinedPanels)->toBe([]);
});

it('defines every colour token the production bench views reference', function (): void {
    // `--color-panel-muted` was used ~40 times across the production bench —
    // table heads, row hovers, nested blocks — but was never defined anywhere in
    // the project's history. `background-color: var(--undefined)` is invalid at
    // computed-value time, so every one of those panels silently rendered
    // transparent. There is no build step to catch it, so pin it here.
    $stylesheets = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        [
            resource_path('css/app.css'),
            resource_path('css/public.css'),
            resource_path('css/shared/soapkraft.css'),
            resource_path('css/shared/filament-soapkraft.css'),
        ],
    ));

    $undefined = [];

    foreach (productionBenchViews() as $view) {
        preg_match_all('/var\(--(color-[a-z0-9-]+)\)/', (string) file_get_contents($view), $matches);

        foreach (array_unique($matches[1]) as $token) {
            if (! str_contains($stylesheets, '--'.$token.':')) {
                $undefined[$token][] = basename($view);
            }
        }
    }

    expect($undefined)->toBe([]);
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

/**
 * Every production bench Livewire view, one and two directories deep.
 *
 * File-scoped: `glob()` does not treat `**` as recursive, so the two patterns
 * are matched separately rather than trusting one to walk the tree.
 */
function productionBenchViews(): array
{
    $base = resource_path('views/livewire/production-bench');

    return array_merge(
        glob($base.'/*.blade.php') ?: [],
        glob($base.'/*/*.blade.php') ?: [],
    );
}
