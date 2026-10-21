<?php

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\SupplierConstituentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function declaredAllergen(SupplierQualification $qualification, Allergen $allergen, string $value, bool $retired = false): DossierEvidenceItem
{
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::AllergenDeclaration,
        'retired_at' => $retired ? now() : null,
        'constituents_complete' => true,
    ]);
    DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $item->id,
        'kind' => ConstituentKind::Allergen,
        'allergen_id' => $allergen->id,
        'max_value' => $value,
    ]);

    return $item;
}

it('takes the worst case across validated suppliers and ignores retired documents and withdrawn suppliers', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $linalool = Allergen::factory()->create();
    $a = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $b = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $withdrawn = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id, 'status' => 'retire']);
    declaredAllergen($a, $linalool, '30');
    $worst = declaredAllergen($b, $linalool, '36');
    declaredAllergen($b, $linalool, '50', retired: true);
    declaredAllergen($withdrawn, $linalool, '80');

    $row = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->sole();

    expect($row['allergen_id'])->toBe($linalool->id)
        ->and($row['max_value'])->toBe('36.000000')
        ->and($row['source'])->toBe('supplier')
        ->and($row['evidence_item_ids'])->toContain($worst->id)
        ->and($row['suppliers_without_declaration'])->toBe([])
        ->and($row['needs_justification'])->toBeFalse();
});

it('flags a validated supplier that has no allergen declaration', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $declared = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $silent = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    declaredAllergen($declared, Allergen::factory()->create(), '30');

    $row = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->sole();

    expect($row['suppliers_without_declaration'])->toBe([$silent->id])
        ->and($row['needs_justification'])->toBeTrue();
});

it('falls back to the platform composition only where no supplier document gives a value, flagged', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $linalool = Allergen::factory()->create();
    $limonene = Allergen::factory()->create();
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    declaredAllergen($qualification, $linalool, '30');
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $lavandin->id, 'allergen_id' => $linalool->id, 'concentration_percent' => 45]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $lavandin->id, 'allergen_id' => $limonene->id, 'concentration_percent' => 0.5]);

    $rows = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->keyBy('allergen_id');

    expect($rows[$linalool->id]['source'])->toBe('supplier')
        ->and($rows[$linalool->id]['max_value'])->toBe('30.000000')
        ->and($rows[$limonene->id]['source'])->toBe('platform')
        ->and($rows[$limonene->id]['max_value'])->toBe('0.500000')
        ->and($rows[$limonene->id]['needs_justification'])->toBeTrue();
});

it('resolves impurities in ppm the same way', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $sheet = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id, 'type' => EvidenceType::TechnicalSheet, 'constituents_complete' => true]);
    $lead = DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $sheet->id, 'kind' => ConstituentKind::Impurity,
        'allergen_id' => null, 'substance_id' => Substance::factory(), 'max_value' => '0.1', 'unit' => 'ppm',
    ]);

    $row = app(SupplierConstituentResolver::class)->impurities($workspace, $olive)->sole();

    expect($row['substance_id'])->toBe($lead->substance_id)
        ->and($row['unit'])->toBe('ppm')
        ->and($row['max_value'])->toBe('0.100000');
});

it('keeps flagging a supplier whose current document is not fully transcribed', function (): void {
    $workspace = Workspace::factory()->create();
    $lavandin = Ingredient::factory()->create();
    $linalool = Allergen::factory()->create();
    $a = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    $b = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavandin->id]);
    declaredAllergen($a, $linalool, '30');
    declaredAllergen($b, $linalool, '60', retired: true);
    DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $b->id, 'type' => EvidenceType::AllergenDeclaration]);

    $row = app(SupplierConstituentResolver::class)->allergens($workspace, $lavandin)->sole();

    expect($row['max_value'])->toBe('30.000000')
        ->and($row['suppliers_without_declaration'])->toBe([$b->id])
        ->and($row['needs_justification'])->toBeTrue();
});

it('does not treat a technical sheet with only microbiology data as an impurity declaration', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $declared = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $microOnly = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $sheet = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $declared->id, 'type' => EvidenceType::TechnicalSheet, 'constituents_complete' => true]);
    DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $sheet->id, 'kind' => ConstituentKind::Impurity,
        'allergen_id' => null, 'substance_id' => Substance::factory(), 'max_value' => '0.1', 'unit' => 'ppm',
    ]);
    DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $microOnly->id, 'type' => EvidenceType::TechnicalSheet,
        'facts' => ['microbiology_specification' => 'TAMC < 100 CFU/g'],
    ]);

    $row = app(SupplierConstituentResolver::class)->impurities($workspace, $olive)->sole();

    expect($row['suppliers_without_declaration'])->toBe([$microOnly->id])
        ->and($row['needs_justification'])->toBeTrue();
});

it('does not treat a complete technical sheet that states no impurity values as an impurity declaration', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $declared = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $silentSheet = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $sheet = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $declared->id, 'type' => EvidenceType::TechnicalSheet, 'constituents_complete' => true]);
    DossierEvidenceConstituent::factory()->create([
        'dossier_evidence_item_id' => $sheet->id, 'kind' => ConstituentKind::Impurity,
        'allergen_id' => null, 'substance_id' => Substance::factory(), 'max_value' => '0', 'unit' => 'ppm',
    ]);
    DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $silentSheet->id, 'type' => EvidenceType::TechnicalSheet, 'constituents_complete' => true]);

    $row = app(SupplierConstituentResolver::class)->impurities($workspace, $olive)->sole();

    expect($row['max_value'])->toBe('0.000000')
        ->and($row['suppliers_without_declaration'])->toBe([$silentSheet->id])
        ->and($row['needs_justification'])->toBeTrue();
});

it('treats a complete allergen declaration with no allergens as declaring none', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $none = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    $declared = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $none->id, 'type' => EvidenceType::AllergenDeclaration, 'constituents_complete' => true]);
    declaredAllergen($declared, Allergen::factory()->create(), '0.01');

    expect(app(SupplierConstituentResolver::class)->allergens($workspace, $olive)->sole()['suppliers_without_declaration'])->toBe([]);
});

it('reports suppliers without a declaration even when no supplier declares anything', function (): void {
    $workspace = Workspace::factory()->create();
    $olive = Ingredient::factory()->create();
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $olive->id]);
    DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id, 'type' => EvidenceType::TechnicalSheet, 'constituents_complete' => true]);
    $resolver = app(SupplierConstituentResolver::class);

    expect($resolver->impurities($workspace, $olive))->toBeEmpty()
        ->and($resolver->suppliersWithoutDeclaration($workspace, $olive, ConstituentKind::Impurity))->toBe([$qualification->id])
        ->and($resolver->suppliersWithoutDeclaration($workspace, $olive, ConstituentKind::Allergen))->toBe([$qualification->id]);
});
