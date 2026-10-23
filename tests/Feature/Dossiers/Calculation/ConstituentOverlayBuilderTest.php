<?php

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\Calculation\ConstituentOverlayBuilder;
use App\Services\Dossiers\SupplierConstituentResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function overlayDeclaration(Workspace $workspace, Ingredient $ingredient, EvidenceType $type, ConstituentKind $kind, array $constituent): void
{
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => $type,
        'constituents_complete' => true,
    ]);
    DossierEvidenceConstituent::factory()->create(['dossier_evidence_item_id' => $item->id, 'kind' => $kind, ...$constituent]);
}

/**
 * @return array{key: string, ingredient_id: int|null, name: string, grams: string}
 */
function overlayInitial(string $key, ?Ingredient $ingredient, string $grams): array
{
    return ['key' => $key, 'ingredient_id' => $ingredient?->id, 'name' => $ingredient?->display_name ?? $key, 'grams' => $grams];
}

it('sums the same allergen from every ingredient over the cured mass', function (): void {
    $workspace = Workspace::factory()->create();
    $limonene = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
    $lavender = Ingredient::factory()->create();
    $orange = Ingredient::factory()->create();
    overlayDeclaration($workspace, $lavender, EvidenceType::AllergenDeclaration, ConstituentKind::Allergen, ['allergen_id' => $limonene->id, 'max_value' => '40']);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $orange->id, 'allergen_id' => $limonene->id, 'concentration_percent' => 2]);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [
        overlayInitial('item:fragrance:0', $lavender, '30'),
        overlayInitial('item:fragrance:1', $orange, '10'),
        overlayInitial('naoh', null, '127.3418'),
    ], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'allergen:'.$limonene->id);

    expect(DecimalMath::round($row['grams'], 6))->toBe('12.200000')
        ->and(DecimalMath::round($row['final_percent'], 6))->toBe('0.938462')
        ->and(DecimalMath::round($row['initial_percent'], 6))->toBe('0.813333')
        ->and($row['needs_justification'])->toBeTrue()
        ->and(collect($row['contributions'])->pluck('source')->all())->toBe(['supplier', 'platform']);
});

it('expresses impurities in ppm and reports suppliers that declared nothing', function (): void {
    $workspace = Workspace::factory()->create();
    $lead = Substance::factory()->create(['name' => 'Lead']);
    $olive = Ingredient::factory()->create();
    overlayDeclaration($workspace, $olive, EvidenceType::TechnicalSheet, ConstituentKind::Impurity, ['allergen_id' => null, 'substance_id' => $lead->id, 'max_value' => '2', 'unit' => 'ppm']);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [overlayInitial('item:saponified_oils:0', $olive, '1000')], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'impurity:'.$lead->id);

    expect(DecimalMath::round($row['final_ppm'], 6))->toBe('1.538462')
        ->and($row['needs_justification'])->toBeFalse()
        ->and(collect($overlays['undeclared'])->where('ingredient_id', $olive->id)->pluck('kind')->sort()->values()->all())
        ->toBe(['allergen', 'restricted_substance']);
});

it('reads a blend without its own validated supplier through its components', function (): void {
    $workspace = Workspace::factory()->create();
    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    $blend = Ingredient::factory()->create();
    $component = Ingredient::factory()->create();
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $component->id, 'percentage_in_parent' => 50]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $component->id, 'allergen_id' => $linalool->id, 'concentration_percent' => 10]);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [overlayInitial('item:fragrance:0', $blend, '20')], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'allergen:'.$linalool->id);

    expect(DecimalMath::round($row['grams'], 6))->toBe('1.000000')
        ->and($row['contributions'][0]['ingredient_id'])->toBe($component->id);
});

it('resolves restricted substances from conformity certificates first, then the flagged platform composition', function (): void {
    $workspace = Workspace::factory()->create();
    $coumarin = Substance::factory()->create(['name' => 'Coumarin']);
    $safrole = Substance::factory()->create(['name' => 'Safrole']);
    $oil = Ingredient::factory()->create();
    overlayDeclaration($workspace, $oil, EvidenceType::RegulatoryConformityCertificate, ConstituentKind::RestrictedSubstance, ['allergen_id' => null, 'substance_id' => $coumarin->id, 'max_value' => '0.8']);
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $oil->id, 'substance_id' => $coumarin->id, 'concentration_percent' => 0.1]);
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $oil->id, 'substance_id' => $safrole->id, 'concentration_percent' => 0.05]);

    $values = app(SupplierConstituentResolver::class)->restrictedSubstances($workspace, $oil)->keyBy('substance_id');

    expect(DecimalMath::round(DecimalMath::of($values[$coumarin->id]['max_value']), 2))->toBe('0.80')
        ->and($values[$coumarin->id]['source'])->toBe('supplier')
        ->and($values[$safrole->id]['source'])->toBe('platform')
        ->and($values[$safrole->id]['needs_justification'])->toBeTrue();
});
