<?php

use App\Models\ProductionRun;
use App\Services\Production\ProductionOutputReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports finished output variance in units and percentage', function (): void {
    $production = ProductionRun::factory()->create([
        'expected_units' => 288,
        'actual_output_units' => 283,
        'actual_output_mass_grams' => null,
    ]);

    expect(app(ProductionOutputReconciliation::class)->forProduction($production))->toBe([
        'unit' => 'unit',
        'planned' => '288',
        'actual' => '283',
        'variance' => '-5',
        'variance_percentage' => '-1.74',
    ]);
});

it('reports intermediate output variance in grams', function (): void {
    $production = ProductionRun::factory()->create([
        'basis_quantity_grams' => '14000.000000000',
        'actual_output_units' => null,
        'actual_output_mass_grams' => '14250.500000000',
    ]);

    expect(app(ProductionOutputReconciliation::class)->forProduction($production))->toBe([
        'unit' => 'g',
        'planned' => '14000',
        'actual' => '14250.5',
        'variance' => '250.5',
        'variance_percentage' => '1.79',
    ]);
});

it('reports zero and not-yet-recorded output without inventing wastage', function (): void {
    $planned = ProductionRun::factory()->create([
        'expected_units' => 288,
        'actual_output_units' => null,
    ]);
    $zero = ProductionRun::factory()->create([
        'expected_units' => 288,
        'actual_output_units' => 288,
    ]);

    expect(app(ProductionOutputReconciliation::class)->forProduction($planned)['actual'])->toBeNull()
        ->and(app(ProductionOutputReconciliation::class)->forProduction($zero)['variance'])->toBe('0');
});
