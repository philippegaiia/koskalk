<?php

use App\Models\ProductFamily;
use App\Services\RecipeWorkbenchIfraOptionsBuilder;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('builds the initial workbench payload without eager preview or costing data', function () {
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $draftPayload = [
        'recipe' => [
            'id' => 42,
            'draft_version_id' => 84,
            'version_number' => 3,
            'is_draft' => true,
        ],
        'formulaName' => 'Deferred Draft',
        'oilUnit' => 'g',
        'oilWeight' => 1000,
        'manufacturingMode' => 'saponify_in_formula',
        'exposureMode' => 'rinse_off',
        'regulatoryRegime' => 'eu',
        'editMode' => 'percentage',
        'lyeType' => 'naoh',
        'kohPurity' => 90,
        'dualKohPercentage' => 40,
        'waterMode' => 'percent_of_oils',
        'waterValue' => 38,
        'superfat' => 5,
        'selectedIfraProductCategoryId' => null,
        'phaseItems' => [
            'saponified_oils' => [],
            'additives' => [],
            'fragrance' => [],
        ],
        'catalogReview' => [
            'needs_review' => false,
        ],
    ];

    mock(RecipeWorkbenchService::class, function ($mock) use ($draftPayload): void {
        $mock->shouldReceive('draftPayload')
            ->once()
            ->andReturn($draftPayload);
        $mock->shouldReceive('draftSnapshot')->never();
        $mock->shouldReceive('costingPayload')->never();
        $mock->shouldReceive('phaseBlueprints')
            ->once()
            ->andReturn([]);
    });

    mock(RecipeWorkbenchIngredientCatalogBuilder::class, function ($mock): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturn([]);
    });

    mock(RecipeWorkbenchIfraOptionsBuilder::class, function ($mock): void {
        $mock->shouldReceive('categories')
            ->once()
            ->andReturn([]);
        $mock->shouldReceive('defaultCategoryId')
            ->once()
            ->andReturn(null);
    });

    $payload = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, null);

    expect($payload['savedDraft'])->toBe($draftPayload)
        ->and($payload['costing'])->toBeNull()
        ->and($payload['costingLoaded'])->toBeFalse();
});
