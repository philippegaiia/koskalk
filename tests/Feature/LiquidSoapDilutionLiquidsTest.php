<?php

use App\Models\ProductFamily;
use App\Services\RecipeWorkbenchPhaseBlueprints;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('gives liquid soap first and final dilution phases in process order and bar soap none', function () {
    $blueprints = app(RecipeWorkbenchPhaseBlueprints::class);

    expect(collect($blueprints->all(ProductFamily::factory()->liquidSoapPaste()->make()))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'first_dilution', 'neutralization', 'additives', 'fragrance', 'final_dilution'])
        ->and(collect($blueprints->all(ProductFamily::factory()->make(['slug' => 'soap'])))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance'])
        ->and($blueprints->find('final_dilution')['phase_type'])->toBe('post_reaction');
});
