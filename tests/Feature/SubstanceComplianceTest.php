<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\ProductFamily;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates restricted constituents across ingredients for the selected regime', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = substanceWorkbenchOil();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oliveOil->id,
        'koh_sap_value' => 0.188,
    ]);
    $peppermint = substanceWorkbenchAromatic([
        'display_name' => 'Peppermint Essential Oil',
        'inci_name' => 'MENTHA PIPERITA OIL',
    ]);
    $spearmint = substanceWorkbenchAromatic([
        'display_name' => 'Spearmint Essential Oil',
        'inci_name' => 'MENTHA SPICATA HERB OIL',
    ]);
    $carvone = Substance::factory()->create([
        'name' => 'Carvone',
        'entity_type' => 'constituent',
    ]);
    $regime = RegulatoryRegime::factory()->create([
        'code' => 'eu',
        'name' => 'EU regime',
        'status' => 'active',
    ]);

    IngredientSubstanceEntry::factory()
        ->for($peppermint, 'ingredient')
        ->for($carvone, 'substance')
        ->create([
            'concentration_percent' => 20,
            'concentration_source' => 'supplier',
        ]);
    IngredientSubstanceEntry::factory()
        ->for($spearmint, 'ingredient')
        ->for($carvone, 'substance')
        ->create([
            'concentration_percent' => 60,
            'concentration_source' => 'supplier',
        ]);
    RegulatoryRegimeSubstanceRule::factory()
        ->for($regime, 'regulatoryRegime')
        ->for($carvone, 'substance')
        ->create([
            'rule_type' => 'restricted',
            'rinse_off_max_percent' => 0.1,
            'leave_on_max_percent' => 0.05,
            'is_active' => true,
        ]);

    $payload = substanceSoapDraft($oliveOil, [
        'regulatoryRegime' => 'eu',
        'phaseItems' => [
            'fragrance' => [
                substanceDraftRow($peppermint, 0, 5),
                substanceDraftRow($spearmint, 0, 5),
            ],
        ],
    ]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft($payload);
    $row = collect($snapshot['restrictions']['rows'])->firstWhere('substance_name', 'Carvone');

    expect($snapshot['restrictions']['summary']['status'])->toBe('fail')
        ->and($row)->not->toBeNull()
        ->and($row['status'])->toBe('over_limit')
        ->and($row['percent_of_formula'])->toBeGreaterThan(0.1)
        ->and($row['max_percent'])->toBe(0.1)
        ->and($row['source_ingredients'])->toBe(['Peppermint Essential Oil', 'Spearmint Essential Oil']);
});

it('flags unknown restricted constituent concentration as needing review', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = substanceWorkbenchOil();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oliveOil->id,
        'koh_sap_value' => 0.188,
    ]);
    $essentialOil = substanceWorkbenchAromatic([
        'display_name' => 'Unverified Mint Oil',
    ]);
    $pulegone = Substance::factory()->create([
        'name' => 'Pulegone',
        'entity_type' => 'constituent',
    ]);
    $regime = RegulatoryRegime::factory()->create([
        'code' => 'eu',
        'name' => 'EU regime',
        'status' => 'active',
    ]);

    IngredientSubstanceEntry::factory()
        ->for($essentialOil, 'ingredient')
        ->for($pulegone, 'substance')
        ->create([
            'concentration_percent' => null,
            'concentration_source' => 'unknown',
        ]);
    RegulatoryRegimeSubstanceRule::factory()
        ->for($regime, 'regulatoryRegime')
        ->for($pulegone, 'substance')
        ->create([
            'rule_type' => 'restricted',
            'rinse_off_max_percent' => 0.1,
            'is_active' => true,
        ]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        substanceSoapDraft($oliveOil, [
            'regulatoryRegime' => 'eu',
            'phaseItems' => [
                'fragrance' => [
                    substanceDraftRow($essentialOil, 0, 5),
                ],
            ],
        ]),
    );

    $row = collect($snapshot['restrictions']['rows'])->firstWhere('substance_name', 'Pulegone');

    expect($snapshot['restrictions']['summary']['status'])->toBe('warning')
        ->and($row)->not->toBeNull()
        ->and($row['status'])->toBe('unknown_concentration')
        ->and($row['requires_review'])->toBeTrue();
});

it('flags prohibited whole ingredients independently from constituent accumulation', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = substanceWorkbenchOil();
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oliveOil->id,
        'koh_sap_value' => 0.188,
    ]);
    $calamusOil = substanceWorkbenchAromatic([
        'display_name' => 'Calamus Oil',
        'inci_name' => 'ACORUS CALAMUS ROOT OIL',
    ]);
    $calamusSubstance = Substance::factory()->create([
        'name' => 'Calamus Oil',
        'entity_type' => 'whole_ingredient',
    ]);
    $regime = RegulatoryRegime::factory()->create([
        'code' => 'us_mocra_preview',
        'name' => 'US MoCRA preview',
        'status' => 'preview',
    ]);

    IngredientSubstanceEntry::factory()
        ->for($calamusOil, 'ingredient')
        ->for($calamusSubstance, 'substance')
        ->create([
            'concentration_percent' => 100,
            'concentration_source' => 'supplier',
        ]);
    RegulatoryRegimeSubstanceRule::factory()
        ->for($regime, 'regulatoryRegime')
        ->for($calamusSubstance, 'substance')
        ->create([
            'rule_type' => 'prohibited',
            'is_active' => true,
        ]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        substanceSoapDraft($oliveOil, [
            'regulatoryRegime' => 'us_mocra_preview',
            'phaseItems' => [
                'fragrance' => [
                    substanceDraftRow($calamusOil, 0, 2),
                ],
            ],
        ]),
    );

    $row = collect($snapshot['restrictions']['rows'])->firstWhere('substance_name', 'Calamus Oil');

    expect($snapshot['restrictions']['summary']['status'])->toBe('fail')
        ->and($row)->not->toBeNull()
        ->and($row['status'])->toBe('prohibited')
        ->and($row['entity_type'])->toBe('whole_ingredient')
        ->and($row['source_ingredients'])->toBe(['Calamus Oil']);
});

function substanceWorkbenchOil(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ], $overrides));
}

function substanceWorkbenchAromatic(array $overrides = []): Ingredient
{
    return Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Aromatic Oil',
        'inci_name' => 'AROMATIC OIL',
        'is_active' => true,
    ], $overrides));
}

/**
 * @return array<string, mixed>
 */
function substanceSoapDraft(Ingredient $oil, array $overrides = []): array
{
    $phaseItemsOverrides = is_array($overrides['phaseItems'] ?? null) ? $overrides['phaseItems'] : [];
    unset($overrides['phaseItems']);

    $payload = array_merge([
        'formulaName' => 'Substance Rule Preview',
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
            'saponified_oils' => [
                substanceDraftRow($oil, 100, 1000),
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ], $overrides);

    foreach (['saponified_oils', 'additives', 'fragrance'] as $phaseKey) {
        if (array_key_exists($phaseKey, $phaseItemsOverrides)) {
            $payload['phaseItems'][$phaseKey] = $phaseItemsOverrides[$phaseKey];
        }
    }

    return $payload;
}

/**
 * @return array<string, mixed>
 */
function substanceDraftRow(Ingredient $ingredient, float $percentage, float $weight): array
{
    return [
        'id' => 'row-'.$ingredient->id.'-'.$weight,
        'ingredient_id' => $ingredient->id,
        'name' => $ingredient->display_name,
        'inci_name' => $ingredient->inci_name,
        'category' => $ingredient->category?->value,
        'percentage' => $percentage,
        'weight' => $weight,
        'note' => null,
    ];
}
