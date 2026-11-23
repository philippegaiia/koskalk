<?php

it('uses the mapped app button variant for browse-page workflow actions', function (string $view, string $routeNeedle, string $variant): void {
    $source = file_get_contents(resource_path("views/{$view}.blade.php"));
    $needlePosition = strpos($source, $routeNeedle);

    expect($needlePosition)->not->toBeFalse();

    $anchorStart = strrpos(substr($source, 0, $needlePosition), '<a');
    $anchorEnd = strpos($source, '</a>', $needlePosition);
    $anchor = substr($source, $anchorStart, $anchorEnd - $anchorStart + 4);

    expect($anchor)
        ->toContain("sk-btn {$variant}")
        ->not->toContain('rounded-full');
})->with([
    'add supplier' => ['livewire/production-bench/purchasing/supplier-index', "route('production-bench.purchasing.suppliers.create')", 'sk-btn-primary'],
    'add listing from listing index' => ['livewire/production-bench/purchasing/supplier-listing-index', "route('production-bench.purchasing.listings.create')", 'sk-btn-primary'],
    'edit supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.edit', \$supplier)", 'sk-btn-outline'],
    'add listing from supplier' => ['livewire/production-bench/purchasing/supplier-detail', "route('production-bench.purchasing.suppliers.listings.create', \$supplier)", 'sk-btn-primary'],
    'add ingredient' => ['livewire/dashboard/ingredients-index', "route('ingredients.create')", 'sk-btn-primary'],
    'add packaging item' => ['livewire/dashboard/packaging-items-index', "route('packaging-items.create')", 'sk-btn-primary'],
]);
