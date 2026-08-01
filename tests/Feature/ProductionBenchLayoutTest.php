<?php

use App\Models\Supplier;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
            ->assertSeeHtml('max-w-7xl');
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
