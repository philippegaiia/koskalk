<?php

use App\Support\ProductionBenchNavigation;

it('declares five top level nodes with settings last', function (): void {
    $keys = array_column(ProductionBenchNavigation::tree(), 'key');

    expect($keys)->toBe(['home', 'inventory', 'production', 'purchasing', 'production-setup']);
});

it('resolves every navigation state the production bench views pass', function (
    string $active,
    ?string $subnavigation,
    array $expectedPath,
): void {
    $resolved = ProductionBenchNavigation::resolve($active, $subnavigation);

    expect($resolved['path'])->toBe($expectedPath);
})->with([
    'home' => ['home', null, ['home']],
    'inventory overview' => ['inventory', 'overview', ['inventory', 'overview']],
    'inventory stock' => ['inventory', 'stock', ['inventory', 'stock']],
    'inventory requirements' => ['inventory', 'requirements', ['inventory', 'requirements']],
    'inventory defaults to its first child' => ['inventory', null, ['inventory', 'overview']],
    'production workflow' => ['production', null, ['production', 'runs']],
    'tasks resolves through the production group' => ['tasks', null, ['production', 'tasks']],
    'flash planner resolves through the production group' => ['flash', null, ['production', 'flash']],
    'purchasing suppliers' => ['purchasing', 'suppliers', ['purchasing', 'suppliers']],
    'purchasing listings' => ['purchasing', 'listings', ['purchasing', 'listings']],
    'purchasing quotations' => ['purchasing', 'quotations', ['purchasing', 'quotations']],
    'purchasing orders' => ['purchasing', 'orders', ['purchasing', 'orders']],
    'purchasing receipts' => ['purchasing', 'receipts', ['purchasing', 'receipts']],
    'purchasing defaults to its first child' => ['purchasing', null, ['purchasing', 'suppliers']],
    'settings numbering' => ['production-setup', 'numbering', ['production-setup', 'numbering']],
    'settings presets' => ['production-setup', 'presets', ['production-setup', 'presets']],
    'settings departments' => ['production-setup', 'departments', ['production-setup', 'departments']],
    'settings employees' => ['production-setup', 'employees', ['production-setup', 'employees']],
    'settings task types' => ['production-setup', 'task-types', ['production-setup', 'task-types']],
    'settings task sets' => ['production-setup', 'task-sets', ['production-setup', 'task-sets']],
    'settings working calendar' => ['production-setup', 'calendar', ['production-setup', 'calendar']],
    'settings ready dates' => ['production-setup', 'ready-dates', ['production-setup', 'ready-dates']],
    // The Settings tab lands on Numbering, its first child, so it behaves like
    // every other group: the tab is a branch and the child is the page.
    'settings landing resolves to numbering' => ['production-setup', null, ['production-setup', 'numbering']],
]);

it('defaults to the first child only where the group and that child share a route', function (): void {
    foreach (ProductionBenchNavigation::tree() as $node) {
        if ($node['children'] === []) {
            continue;
        }

        $sharesRoute = $node['route'] === $node['children'][0]['route'];
        $path = ProductionBenchNavigation::resolve($node['key'], null)['path'];

        // A group resolves two deep only when its first child is the same
        // destination. Otherwise it stays the current page itself.
        expect(count($path) === 2)->toBe($sharesRoute, $node['key'].' resolved unexpectedly');
    }
});

it('resolves the calendar key to the production calendar, not the settings working calendar', function (): void {
    expect(ProductionBenchNavigation::resolve('calendar', null)['path'])->toBe(['production', 'calendar']);
});

it('keeps settings at two levels after promotion', function (): void {
    $resolved = ProductionBenchNavigation::resolve('production-setup', 'presets');

    expect($resolved['path'])->toHaveCount(2)
        ->and($resolved['rows'])->toHaveCount(2);
});

it('renders a second row for every group', function (string $active): void {
    $resolved = ProductionBenchNavigation::resolve($active, null);

    expect($resolved['rows'])->toHaveKeys([1, 2]);
})->with(['inventory', 'production', 'purchasing', 'production-setup']);

it('omits the second row for the dashboard leaf', function (): void {
    expect(ProductionBenchNavigation::resolve('home', null)['rows'])->toHaveCount(1);
});

it('marks only the deepest node as the current leaf', function (): void {
    $path = ProductionBenchNavigation::resolve('purchasing', 'receipts')['path'];

    expect(ProductionBenchNavigation::isActive($path, 'purchasing'))->toBeTrue()
        ->and(ProductionBenchNavigation::isActive($path, 'receipts'))->toBeTrue()
        ->and(ProductionBenchNavigation::isActive($path, 'suppliers'))->toBeFalse()
        ->and(ProductionBenchNavigation::isLeaf($path, 'receipts'))->toBeTrue()
        ->and(ProductionBenchNavigation::isLeaf($path, 'purchasing'))->toBeFalse();
});

it('groups settings children and leaves other rows ungrouped', function (): void {
    $settingsGroups = ProductionBenchNavigation::groups(
        ProductionBenchNavigation::resolve('production-setup', null)['rows'][2],
    );

    expect($settingsGroups)->toHaveCount(2)
        ->and($settingsGroups[0]['label'])->toBe('production_bench.navigation.production_workflow')
        ->and(array_column($settingsGroups[0]['nodes'], 'key'))
        ->toBe(['numbering', 'presets', 'task-types', 'task-sets', 'calendar', 'ready-dates'])
        ->and($settingsGroups[1]['label'])->toBe('production_bench.navigation.settings_group_resources')
        ->and(array_column($settingsGroups[1]['nodes'], 'key'))->toBe(['departments', 'employees']);

    $inventoryGroups = ProductionBenchNavigation::groups(
        ProductionBenchNavigation::resolve('inventory', null)['rows'][2],
    );

    expect($inventoryGroups)->toHaveCount(1)
        ->and($inventoryGroups[0]['label'])->toBeNull();
});

it('falls back to an empty path for unknown keys instead of guessing', function (): void {
    $resolved = ProductionBenchNavigation::resolve('does-not-exist', null);

    expect($resolved['path'])->toBe([])
        ->and($resolved['rows'])->toHaveCount(1);
});
