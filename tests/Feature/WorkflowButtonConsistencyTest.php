<?php

it('uses app button primitives for browse-page workflow actions', function (string $view, string $routeNeedle): void {
    $source = file_get_contents(resource_path("views/{$view}.blade.php"));
    $needlePosition = strpos($source, $routeNeedle);

    expect($needlePosition)->not->toBeFalse();

    $anchorStart = strrpos(substr($source, 0, $needlePosition), '<a');
    $anchorEnd = strpos($source, '</a>', $needlePosition);
    $anchor = substr($source, $anchorStart, $anchorEnd - $anchorStart + 4);

    expect($anchor)
        ->toContain('sk-btn')
        ->not->toContain('rounded-full');
})->with([
    'add supplier' => ['livewire/production-bench/purchasing/supplier-index', "route('production-bench.purchasing.suppliers.create')"],
    'add listing from listing index' => ['livewire/production-bench/purchasing/supplier-listing-index', "route('production-bench.purchasing.listings.create')"],
    'edit supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.edit', \$supplier)"],
    'add listing from supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.listings.create', \$supplier)"],
    'back from ingredients' => ['livewire/dashboard/ingredients-index', "route('dashboard')"],
    'back from packaging' => ['livewire/dashboard/packaging-items-index', "route('dashboard')"],
]);
