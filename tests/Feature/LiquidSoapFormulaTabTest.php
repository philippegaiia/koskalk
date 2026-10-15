<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\User;
use App\Services\RecipeWorkbenchIngredientCatalogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{section: bool, additions_share_label: bool, batch_totals: bool, lye_buttons: array<int, string>}
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
        'batch_totals' => collect($xpath->query('//p[contains(@class, "sk-eyebrow")]'))->contains(fn (DOMElement $heading): bool => trim($heading->textContent) === __('workbench.additions.batch_totals')),
        'lye_buttons' => $lyeGroup === null
            ? []
            : collect($xpath->query('.//button[@role="radio"]', $lyeGroup))->map(fn (DOMElement $button): string => trim($button->textContent))->values()->all(),
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

it('shows the liquid soap process with KOH as the only alkali on the liquid bench', function () {
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
    ]);
});
