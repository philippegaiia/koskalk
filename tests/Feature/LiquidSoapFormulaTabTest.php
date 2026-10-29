<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\User;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{section: bool, additions_share_label: bool, batch_totals: bool, lye_buttons: array<int, string>, quality_tabs: bool, liquid_quality_note: bool}
 */
function liquidSoapFormulaTabMarkup(string $html): array
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $lyeGroup = $xpath->query('//*[@role="radiogroup"][@aria-label="Lye type"]')->item(0);

    return [
        'section' => $document->getElementById('liquid-soap-process') !== null,
        'additions_share_label' => str_contains((string) $document->getElementById('post-reaction-phases')?->textContent, __('workbench.liquid.final_share_label')),
        'batch_totals' => collect($xpath->query('//h3'))->contains(fn (DOMElement $heading): bool => trim($heading->textContent) === __('workbench.additions.batch_totals')),
        'lye_buttons' => $lyeGroup === null
            ? []
            : collect($xpath->query('.//button[@role="radio"]', $lyeGroup))->map(fn (DOMElement $button): string => trim($button->textContent))->values()->all(),
        'quality_tabs' => $document->getElementById('tab-bar-cure') !== null,
        'liquid_quality_note' => str_contains($document->textContent, __('workbench.qualities.liquid_disclaimer')),
        'dilution_liquids' => $document->getElementById('liquid-final_dilution-liquids-heading') !== null,
        'output_heading' => trim(preg_replace('/\s+/', ' ', (string) $document->getElementById('output-soap-heading')?->firstChild?->textContent)),
    ];
}

it('offers platform neutralizers in the neutralization phase only on liquid soap families', function (bool $liquid, ?string $type, bool $inNeutralization) {
    $family = $liquid
        ? ProductFamily::factory()->liquidSoapPaste()->create()
        : ProductFamily::factory()->create(['slug' => 'soap']);
    $citricAcid = Ingredient::factory()->create([
        'category' => IngredientCategory::PhAdjustersBuffers,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
        'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous,
    ]);

    $row = collect(app(RecipeWorkbenchIngredientCatalogBuilder::class)->build(User::factory()->create(), $family))
        ->firstWhere('id', $citricAcid->id);

    expect($row['liquid_soap_neutralizer'])->toBe($type)
        ->and(in_array('neutralization', $row['available_phases'], true))->toBe($inNeutralization);
})->with([
    'liquid soap family' => [true, 'citric_acid_anhydrous', true],
    'bar soap family' => [false, null, false],
]);

it('shows the liquid soap process, KOH only and untabbed qualities on the liquid bench', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('recipes.create', ['family' => 'liquid-soap', 'type' => $productType->slug]));

    $response->assertOk();
    expect(liquidSoapFormulaTabMarkup($response->getContent()))->toBe([
        'section' => true,
        'additions_share_label' => true,
        'batch_totals' => false,
        'lye_buttons' => ['KOH'],
        'quality_tabs' => false,
        'liquid_quality_note' => true,
        'dilution_liquids' => true,
        'output_heading' => 'Finished liquid soap output',
    ]);
});

it('keeps the bar soap bench without the liquid process and with every alkali', function () {
    $family = ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('recipes.create', ['family' => 'soap', 'type' => $productType->slug]));

    $response->assertOk();
    expect(liquidSoapFormulaTabMarkup($response->getContent()))->toBe([
        'section' => false,
        'additions_share_label' => false,
        'batch_totals' => true,
        'lye_buttons' => ['NaOH', 'KOH', 'Dual lye'],
        'quality_tabs' => true,
        'liquid_quality_note' => false,
        'dilution_liquids' => false,
        'output_heading' => 'Cured soap output',
    ]);
});

it('offers only liquids as dilution liquids', function () {
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $platform = ['owner_type' => null, 'owner_id' => null, 'is_active' => true];
    Ingredient::factory()->create([...$platform, 'category' => IngredientCategory::WaterSolventsCarriers, 'subcategory' => IngredientSubcategory::Water, 'display_name' => 'Plain Tap Water']);
    Ingredient::factory()->create([...$platform, 'category' => IngredientCategory::PhAdjustersBuffers, 'display_name' => 'Citric Neutralizer', 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    Ingredient::factory()->create([...$platform, 'category' => IngredientCategory::Lipids, 'subcategory' => IngredientSubcategory::VegetableOils, 'display_name' => 'Olive Carrier Oil']);
    Ingredient::factory()->create([...$platform, 'category' => IngredientCategory::BotanicalsExtracts, 'subcategory' => IngredientSubcategory::Hydrosols, 'display_name' => 'Rose Hydrosol']);
    Ingredient::factory()->create([...$platform, 'category' => IngredientCategory::HumectantsPolyols, 'subcategory' => IngredientSubcategory::GlycerinGlycols, 'display_name' => 'Vegetable Glycerine']);

    $html = $this->actingAs(User::factory()->create())
        ->get(route('recipes.create', ['family' => 'liquid-soap', 'type' => $productType->slug]))
        ->assertOk()
        ->getContent();
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $search = (string) $document->getElementById('liquid-final_dilution-liquids-heading')?->parentNode?->C14N();

    expect($search)->toContain('Rose Hydrosol')
        ->and($search)->toContain('Vegetable Glycerine')
        ->and($search)->not->toContain('Plain Tap Water')
        ->and($search)->not->toContain('Citric Neutralizer')
        ->and($search)->not->toContain('Olive Carrier Oil');
});

it('accepts only liquids as liquid soap dilution liquids', function (IngredientCategory $category, ?IngredientSubcategory $subcategory, bool $accepted) {
    $ingredient = Ingredient::factory()->make(['category' => $category, 'subcategory' => $subcategory]);

    expect($ingredient->isLiquidSoapDilutionLiquid())->toBe($accepted);
})->with([
    'hydrosol' => [IngredientCategory::BotanicalsExtracts, IngredientSubcategory::Hydrosols, true],
    'aqueous extract' => [IngredientCategory::BotanicalsExtracts, IngredientSubcategory::AqueousGlycerinatedExtracts, true],
    'oil macerate' => [IngredientCategory::BotanicalsExtracts, IngredientSubcategory::OilMacerates, false],
    'glycerine' => [IngredientCategory::HumectantsPolyols, IngredientSubcategory::GlycerinGlycols, true],
    'alcohol' => [IngredientCategory::WaterSolventsCarriers, IngredientSubcategory::Alcohols, true],
    'plain water' => [IngredientCategory::WaterSolventsCarriers, IngredientSubcategory::Water, false],
    'milk filed under other' => [IngredientCategory::Other, null, true],
    'vegetable oil' => [IngredientCategory::Lipids, IngredientSubcategory::VegetableOils, false],
    'surfactant' => [IngredientCategory::Surfactants, IngredientSubcategory::Amphoteric, false],
    'salt' => [IngredientCategory::MineralsSaltsPowders, IngredientSubcategory::Salts, false],
    'essential oil' => [IngredientCategory::AromaticMaterials, IngredientSubcategory::EssentialOils, false],
    'alkali' => [IngredientCategory::SoapmakingAlkalis, IngredientSubcategory::PotassiumHydroxide, false],
]);
