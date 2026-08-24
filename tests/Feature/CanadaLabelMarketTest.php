<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientLabelMarket;
use App\Models\Ingredient;
use App\Models\IngredientMarketLabel;
use App\Models\IngredientSapProfile;
use App\Services\InciGenerationService;
use App\Services\IngredientDeclarationNameResolver;
use App\Services\RecipeWorkbenchPreviewService;
use App\Services\SubstanceComplianceService;
use Database\Seeders\AllergenCatalogSeeder;
use Database\Seeders\RegulatoryRegimeSeeder;
use Database\Seeders\SubstanceSeeder;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([
        SupportedLocaleSeeder::class,
        AllergenCatalogSeeder::class,
        RegulatoryRegimeSeeder::class,
        SubstanceSeeder::class,
    ]);
});

it('resolves canadian declaration names through the EU market before falling back to INCI', function () {
    $ingredient = makeCanadaLabellingIngredient([
        'display_name' => 'Peppermint',
        'inci_name' => 'MENTHA PIPERITA OIL',
    ], euDeclarationName: 'MINT OIL');

    $ingredient->load('marketLabels');

    $resolver = app(IngredientDeclarationNameResolver::class);
    $withEuName = $resolver->resolveWithFallback($ingredient, 'ca');

    expect($withEuName['declaration_name'])->toBe('MINT OIL')
        ->and($withEuName['fallback'])->toBeNull();

    $ingredientWithCaName = makeCanadaLabellingIngredient([], caDeclarationName: 'MENTHE POIVRÉE');
    $ingredientWithCaName->load('marketLabels');

    $caName = $resolver->resolveWithFallback($ingredientWithCaName, 'ca');
    expect($caName['declaration_name'])->toBe('MENTHE POIVRÉE')
        ->and($caName['fallback'])->toBeNull();

    $bareInci = makeCanadaLabellingIngredient([
        'display_name' => 'Bare INCI',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER',
    ]);
    $bareInci->load('marketLabels');

    $inci = $resolver->resolveWithFallback($bareInci, 'ca', allowLegacyEuFallback: true);
    expect($inci['declaration_name'])->toBe('LAVANDULA ANGUSTIFOLIA FLOWER WATER')
        ->and($inci['fallback'])->toBeNull();
});

it('renders bilingual english/french plain-language labels for Canadian soap labelling', function () {
    $oil = makeCanadaLabellingIngredient([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
    ], frenchDisplayName: 'Huile d\'olive');
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $peppermint = makeCanadaLabellingIngredient([
        'display_name' => 'Peppermint',
        'inci_name' => 'MENTHA PIPERITA OIL',
    ], frenchDisplayName: 'Menthe poivrée');

    $payload = canadaSoapPayload($oil, $peppermint);
    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation($payload);

    $generated = app(InciGenerationService::class)->generate($payload, $calculation);

    expect($generated['plain_language_list']['final_label_text'])
        ->toContain('Saponified Oils of (Olive/Huile d\'olive)')
        ->toContain('Water/Eau')
        ->toContain('Peppermint/Menthe poivrée')
        ->toContain('Glycerin/Glycérine');
});

it('warns when an ingredient lacks a French name for the Canadian plain-language list', function () {
    $oil = makeCanadaLabellingIngredient([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
    ], frenchDisplayName: 'Huile d\'olive');
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $hydrosol = makeCanadaLabellingIngredient([
        'display_name' => 'Lavender Hydrosol',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER',
    ]);

    $payload = canadaSoapPayload($oil, $hydrosol);
    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation($payload);

    $generated = app(InciGenerationService::class)->generate($payload, $calculation);

    expect($generated['plain_language_list']['final_label_text'])->toContain('Lavender Hydrosol')
        ->and(collect($generated['warnings'])->first(
            fn (string $warning): bool => str_contains($warning, 'Lavender Hydrosol has no French name yet'),
        ))->not->toBeNull();
});

it('returns labeling and substance screening for the Canadian regime end-to-end', function () {
    $oil = makeCanadaLabellingIngredient([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
    ]);
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $payload = canadaSoapPayload($oil);
    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation($payload);
    $generated = app(InciGenerationService::class)->generate($payload, $calculation);
    $restrictions = app(SubstanceComplianceService::class)->evaluate($payload, $calculation);

    expect($generated['declaration_rows'])->not->toBeNull()
        ->and($generated['final_label_text'])->toContain('OLEA EUROPAEA FRUIT OIL')
        ->and($restrictions['regime']['uses_regime_rules'])->toBe(true);
});

it('keeps the EU plain-language list untouched', function () {
    $oil = makeCanadaLabellingIngredient([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'category' => IngredientCategory::Lipids,
    ], frenchDisplayName: 'Huile d\'olive');
    IngredientSapProfile::factory()->create([
        'ingredient_id' => $oil->id,
        'koh_sap_value' => 0.188,
    ]);

    $payload = canadaSoapPayload($oil);
    $payload['regulatory_regime'] = 'eu';
    $calculation = app(RecipeWorkbenchPreviewService::class)->previewSoapCalculation($payload);
    $generated = app(InciGenerationService::class)->generate($payload, $calculation);

    expect($generated['plain_language_list']['final_label_text'])
        ->toContain('Saponified Oils of (Olive)')
        ->toContain('Water')
        ->not->toContain('Huile d\'olive')
        ->not->toContain('Eau');
});

it('surfaces the expanded-list milestone hint under the label market switcher', function () {
    $partial = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/label-market-switcher.blade.php'));

    expect($partial)->toContain('x-text="regulatoryRegimeMilestoneHint"')
        ->and(__('workbench.cosmetic.expanded_list_hint', [
            'newFrom' => '2026-08-01',
            'existingFrom' => '2028-08-01',
        ]))->toContain('2026-08-01')
        ->toContain('2028-08-01');
});

function makeCanadaLabellingIngredient(array $overrides = [], ?string $frenchDisplayName = null, ?string $caDeclarationName = null, ?string $euDeclarationName = null): Ingredient
{
    $ingredient = Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::Other,
        'display_name' => 'Mint Water',
        'inci_name' => 'MINT WATER',
        'is_active' => true,
    ], $overrides));

    if ($frenchDisplayName !== null) {
        $ingredient->translations()->create([
            'locale' => 'fr',
            'display_name' => $frenchDisplayName,
        ]);
    }

    if ($caDeclarationName !== null) {
        IngredientMarketLabel::factory()->create([
            'ingredient_id' => $ingredient->id,
            'market_code' => IngredientLabelMarket::Ca->value,
            'declaration_name' => $caDeclarationName,
        ]);
    }

    if ($euDeclarationName !== null) {
        IngredientMarketLabel::factory()->create([
            'ingredient_id' => $ingredient->id,
            'market_code' => IngredientLabelMarket::Eu->value,
            'declaration_name' => $euDeclarationName,
        ]);
    }

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function canadaSoapPayload(Ingredient $oil, ?Ingredient $additive = null): array
{
    return [
        'name' => 'Canada Label Check',
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'canada_2026',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $oil->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => $additive === null ? [] : [
                [
                    'ingredient_id' => $additive->id,
                    'percentage' => 2,
                    'weight' => 20,
                    'note' => null,
                ],
            ],
            'fragrance' => [],
        ],
    ];
}
