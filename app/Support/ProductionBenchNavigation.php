<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Declarative navigation tree for the Production Bench.
 *
 * Node keys deliberately mirror the `active` / `subnavigation` values that the
 * Livewire views under `resources/views/livewire/production-bench/` already
 * pass to `<x-production-bench.page>`. That is what keeps this a lookup rather
 * than a rewrite: promoting `production-setup` to a top level, for example,
 * required no view changes at all.
 *
 * `aria` and `also` are optional because only `leaf()` populates them; the five
 * top-level nodes are written out longhand. The shape is recursive, hence the
 * alias — a bare `list<array>` cannot express the nesting.
 *
 * @phpstan-type NavNode array{key: string, route: string, label: string, icon: string, group: ?string, end: bool, divider: bool, children: list<NavNode>, aria?: ?string, also?: list<string>}
 * @phpstan-type NavGroup array{label: ?string, nodes: list<NavNode>}
 */
final class ProductionBenchNavigation
{
    /**
     * Node shape: `key`, `route`, `label`, `aria`, `icon`, `group`, `end`, `divider`, `children`.
     *
     * `end` marks a top-level tab as a utility and draws a rule before it;
     * `divider` draws a rule before the following tab. Both are layout flags
     * consumed by `navigation-items.blade.php`, so the partial never hard-codes
     * "the last item" or "the first item" positionally. Neither moves an item:
     * the tab keeps its place in the reading order and the rule is what sets it
     * apart (plan §9, override O3).
     *
     * @return list<NavNode>
     */
    public static function tree(): array
    {
        return [
            [
                'key' => 'home',
                'route' => 'production-bench.home',
                'label' => 'production_bench.navigation.home',
                'aria' => 'production_bench.navigation.home_aria',
                'icon' => 'dashboard',
                'group' => null,
                'end' => false,
                'divider' => true,
                'children' => [],
            ],
            [
                'key' => 'inventory',
                'route' => 'production-bench.inventory',
                'label' => 'production_bench.navigation.inventory',
                'aria' => null,
                'icon' => 'inventory',
                'group' => null,
                'end' => false,
                'divider' => false,
                'children' => [
                    // Two entries, not three. Materials carries every material
                    // the workspace tracks — demanded or merely listed — so the
                    // separate requirements page had nothing left to say and
                    // its route now redirects here.
                    self::leaf('materials', 'production-bench.inventory', 'production_bench.inventory.stock_by_material', 'materials', null, [
                        'production-bench.inventory.material.ingredient',
                        'production-bench.inventory.material.packaging',
                    ]),
                    self::leaf('stock', 'production-bench.inventory.stock', 'production_bench.inventory.lot_register', 'stock'),
                ],
            ],
            [
                'key' => 'production',
                'route' => 'production-bench.production.index',
                'label' => 'production_bench.navigation.production_workflow',
                'aria' => null,
                'icon' => 'production',
                'group' => null,
                'end' => false,
                'divider' => false,
                'children' => [
                    self::leaf('runs', 'production-bench.production.index', 'production_bench.navigation.production_runs', 'runs', null, [
                        'production-bench.production.show',
                        'production-bench.production.prepare',
                        'production-bench.production.create',
                    ]),
                    self::leaf('tasks', 'production-bench.production.tasks', 'production_bench.navigation.tasks', 'tasks'),
                    self::leaf('flash', 'production-bench.production.flash', 'production_bench.navigation.flash', 'flash'),
                    // Reuses the calendar page title so "Calendar" is not ambiguous with
                    // the settings "Working calendar" without introducing a new key.
                    self::leaf('calendar', 'production-bench.production.calendar', 'production_bench.calendar.title', 'calendar'),
                ],
            ],
            [
                'key' => 'purchasing',
                'route' => 'production-bench.purchasing.suppliers',
                'label' => 'production_bench.navigation.purchasing',
                'aria' => null,
                'icon' => 'purchasing',
                'group' => null,
                'end' => false,
                'divider' => false,
                'children' => [
                    self::leaf('suppliers', 'production-bench.purchasing.suppliers', 'production_bench.navigation.suppliers', 'suppliers', null, [
                        'production-bench.purchasing.supplier',
                        'production-bench.purchasing.suppliers.create',
                        'production-bench.purchasing.suppliers.edit',
                        'production-bench.purchasing.suppliers.listings.create',
                    ]),
                    self::leaf('listings', 'production-bench.purchasing.listings', 'production_bench.navigation.supplier_listings', 'listings', null, [
                        'production-bench.purchasing.listings.create',
                        'production-bench.purchasing.listings.edit',
                    ]),
                    self::leaf('quotations', 'production-bench.purchasing.quotations', 'production_bench.navigation.quotation_requests', 'quotations', null, [
                        'production-bench.purchasing.quotations.create',
                    ]),
                    self::leaf('orders', 'production-bench.purchasing.orders', 'production_bench.navigation.purchase_orders', 'orders', null, [
                        'production-bench.purchasing.orders.create',
                        'production-bench.purchasing.procurement.show',
                    ]),
                    self::leaf('receipts', 'production-bench.purchasing.receipts', 'production_bench.navigation.receipts', 'receipts', null, [
                        'production-bench.purchasing.receipts.*',
                    ]),
                ],
            ],
            [
                'key' => 'production-setup',
                // Deliberately the same route as the first child. The tab used to
                // point at `/settings`, which rendered every section at once; it
                // now lands on Numbering, the group's first child, which is what
                // Inventory, Production and Purchasing already do. `/settings`
                // itself is a route-level redirect kept for old bookmarks.
                'route' => 'production-bench.production.settings.numbering',
                'label' => 'production_bench.navigation.settings',
                'aria' => null,
                'icon' => 'settings',
                'group' => null,
                'end' => true,
                'divider' => false,
                'children' => [
                    // The Production sub-heading reuses the level-1 Production tab
                    // label, so the two can never drift apart.
                    self::leaf('numbering', 'production-bench.production.settings.numbering', 'production_bench.settings.numbering', 'numbering', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.numbering*']),
                    self::leaf('presets', 'production-bench.production.settings.presets', 'production_bench.settings.presets', 'presets', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.presets.*']),
                    self::leaf('task-types', 'production-bench.production.settings.task-types', 'production_bench.settings.task_types', 'task-types', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.task-types*']),
                    self::leaf('task-sets', 'production-bench.production.settings.task-sets', 'production_bench.settings.task_sets', 'task-sets', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.task-sets.*']),
                    // Keyed `calendar` to match the `$section` value `SettingsIndex`
                    // derives, since that one string drives both the subnavigation
                    // and the section filtering inside the settings view. It does
                    // not collide with Production's `calendar` child: `resolve()`
                    // locates the level 1 node first, then looks up `subnavigation`
                    // within that node's children only.
                    self::leaf('calendar', 'production-bench.production.settings.calendar', 'production_bench.settings.working_calendar', 'working-calendar', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.calendar*']),
                    // Ready dates lived only on the page that rendered every
                    // section at once. It gets its own entry because it is the
                    // workspace default behind every production run's estimated
                    // ready date, so it cannot be left unreachable.
                    self::leaf('ready-dates', 'production-bench.production.settings.ready-dates', 'production_bench.settings.ready_dates', 'ready-dates', 'production_bench.navigation.production_workflow', ['production-bench.production.settings.ready-dates*']),
                    self::leaf('departments', 'production-bench.production.settings.departments', 'production_bench.settings.departments', 'departments', 'production_bench.navigation.settings_group_resources', ['production-bench.production.settings.departments*']),
                    self::leaf('employees', 'production-bench.production.settings.employees', 'production_bench.settings.employees', 'employees', 'production_bench.navigation.settings_group_resources', ['production-bench.production.settings.employees*']),
                ],
            ],
        ];
    }

    /**
     * Resolve the active trail and the rows that should be rendered.
     *
     * @return array{path: list<string>, rows: array<int, list<NavNode>>}
     */
    public static function resolve(?string $active, ?string $subnavigation): array
    {
        $tree = self::tree();
        $activeKey = $active ?? self::detectActiveKey();

        if ($activeKey === null) {
            return ['path' => [], 'rows' => [1 => $tree]];
        }

        $located = self::locate($tree, $activeKey);

        if ($located === null) {
            return ['path' => [], 'rows' => [1 => $tree]];
        }

        $node = $located['node'];
        $path = [...$located['trail'], $node['key']];

        if ($node['children'] !== []) {
            $child = self::find($node['children'], $subnavigation)
                ?? self::find($node['children'], self::detectSubnavigationKey($node['children']));

            // Default to the first child only where the group's own link points
            // at the same route, which is what makes the group and its first
            // child two spellings of one destination. This holds for all four
            // groups now that Settings lands on Numbering.
            //
            // The default also has to survive Livewire update requests, where
            // `routeIs()` sees `livewire.update` rather than the page route and
            // `detectSubnavigationKey()` therefore resolves to null.
            if ($child === null && $node['route'] === $node['children'][0]['route']) {
                $child = $node['children'][0];
            }

            if ($child !== null) {
                $path[] = $child['key'];
            }
        }

        return ['path' => $path, 'rows' => self::rows($tree, $path)];
    }

    public static function isActive(array $path, string $key): bool
    {
        return in_array($key, $path, true);
    }

    public static function isLeaf(array $path, string $key): bool
    {
        return ($path === [] ? null : $path[count($path) - 1]) === $key;
    }

    /**
     * Group the given siblings into chunks keyed by their optional group label.
     *
     * Siblings without a group produce a single unlabelled chunk, so Inventory
     * and Purchasing keep a plain row while Settings gains sub-headings.
     *
     * @param  list<NavNode>  $nodes
     * @return list<NavGroup>
     */
    public static function groups(array $nodes): array
    {
        // `chunkWhile` seeds the first sibling into the chunk and only then
        // starts asking, so comparing against the chunk's last entry groups
        // consecutive runs without a counter.
        return collect($nodes)
            ->chunkWhile(
                fn (array $node, int|string $key, Collection $chunk): bool => ($node['group'] ?? null) === ($chunk->last()['group'] ?? null),
            )
            ->map(fn (Collection $chunk): array => [
                'label' => $chunk->first()['group'] ?? null,
                'nodes' => $chunk->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The rows to render: level 1 always, level 2 only for the active branch.
     *
     * Both levels are named here, which is why the tree has exactly two visual
     * depths today despite `data-level` being open-ended in CSS.
     *
     * @param  list<NavNode>  $tree
     * @param  list<string>  $path
     * @return array<int, list<NavNode>>
     */
    private static function rows(array $tree, array $path): array
    {
        $rows = [1 => $tree];
        $rootKey = $path[0] ?? null;
        $root = $rootKey === null ? null : self::find($tree, $rootKey);

        if ($root !== null && $root['children'] !== []) {
            $rows[2] = $root['children'];
        }

        return $rows;
    }

    /**
     * Find a node by key, preferring the shallowest match in declaration order.
     *
     * This is what keeps `calendar` unambiguous: the Production calendar is
     * declared before the Settings working calendar.
     *
     * @param  list<NavNode>  $nodes
     * @return ?NavNode
     */
    private static function find(array $nodes, ?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node['key'] === $key) {
                return $node;
            }
        }

        foreach ($nodes as $node) {
            if ($node['children'] !== []) {
                $found = self::find($node['children'], $key);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<NavNode>  $nodes
     * @return ?array{node: NavNode, trail: list<string>}
     */
    private static function locate(array $nodes, string $key, array $trail = []): ?array
    {
        foreach ($nodes as $node) {
            if ($node['key'] === $key) {
                return ['node' => $node, 'trail' => $trail];
            }
        }

        foreach ($nodes as $node) {
            if ($node['children'] !== []) {
                $found = self::locate($node['children'], $key, [...$trail, $node['key']]);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Fall back to the request route when no explicit `active` prop is given.
     *
     * Order matters: the settings branch must be tested before the generic
     * production branches, since both share the `production-bench.production.`
     * prefix.
     */
    private static function detectActiveKey(): ?string
    {
        return match (true) {
            self::routeIs('production-bench.home') => 'home',
            self::routeIs('production-bench.inventory*') => 'inventory',
            self::routeIs('production-bench.production.settings*') => 'production-setup',
            self::routeIs('production-bench.production.index', 'production-bench.production.show', 'production-bench.production.prepare') => 'production',
            self::routeIs('production-bench.production.tasks') => 'tasks',
            self::routeIs('production-bench.production.flash') => 'flash',
            self::routeIs('production-bench.production.calendar') => 'calendar',
            self::routeIs('production-bench.purchasing', 'production-bench.purchasing.*') => 'purchasing',
            default => null,
        };
    }

    /**
     * @param  list<string>  $also  Extra route patterns that should mark this child current.
     * @return NavNode
     */
    private static function leaf(string $key, string $route, string $label, string $icon, ?string $group = null, array $also = []): array
    {
        return [
            'key' => $key,
            'route' => $route,
            'label' => $label,
            'aria' => null,
            'icon' => $icon,
            'group' => $group,
            'end' => false,
            'divider' => false,
            'also' => $also,
            'children' => [],
        ];
    }

    /**
     * Pick the child whose route matches the current request, so a page that
     * omits `subnavigation` still highlights the right entry.
     *
     * @param  list<NavNode>  $children
     */
    private static function detectSubnavigationKey(array $children): ?string
    {
        foreach ($children as $child) {
            if (self::routeIs($child['route'], ...($child['also'] ?? []))) {
                return $child['key'];
            }
        }

        return null;
    }

    /**
     * Route matching that degrades to false outside an HTTP request, so the
     * class stays usable from plain unit tests.
     */
    private static function routeIs(string ...$patterns): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        return request()->routeIs(...$patterns);
    }
}
