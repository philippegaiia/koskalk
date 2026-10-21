<?php

use App\Actions\Dossiers\SaveDossierEvidenceData;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceItem;
use App\Models\IfraProductCategory;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Workspace}
 */
function evidenceDataWorkspace(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();

    return [$owner, $workspace];
}

function evidenceOfType(Workspace $workspace, EvidenceType $type): DossierEvidenceItem
{
    return DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => SupplierQualification::factory()->create(['workspace_id' => $workspace->id])->id,
        'type' => $type,
    ]);
}

it('records an allergen declaration and replaces it on the next save', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::AllergenDeclaration);
    $linalool = Allergen::factory()->create(['inci_name' => 'Linalool']);
    $limonene = Allergen::factory()->create(['inci_name' => 'Limonene']);

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'constituents' => [
            ['kind' => 'allergen', 'allergen_id' => $linalool->id, 'max_value' => '35.5'],
            ['kind' => 'allergen', 'allergen_id' => $limonene->id, 'max_value' => '0.8'],
        ],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'constituents' => [['kind' => 'allergen', 'allergen_id' => $linalool->id, 'max_value' => '36']],
    ]);

    $item->refresh();

    expect($item->constituents)->toHaveCount(1)
        ->and($item->constituents->first()->max_value)->toBe('36.000000')
        ->and($item->constituents->first()->unit)->toBe('percent')
        ->and($item->data_updated_by_user_id)->toBe($owner->id);
});

it('records impurities in ppm, IFRA limits per category and RCE facts', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $lead = Substance::factory()->create(['name' => 'Lead']);
    $sheet = evidenceOfType($workspace, EvidenceType::TechnicalSheet);
    $ifra = evidenceOfType($workspace, EvidenceType::IfraCertificate);
    $rce = evidenceOfType($workspace, EvidenceType::RegulatoryConformityCertificate);
    $category9 = IfraProductCategory::factory()->create(['code' => '9']);

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $sheet, [
        'constituents' => [['kind' => 'impurity', 'substance_id' => $lead->id, 'max_value' => '2']],
        'facts' => ['microbiology_specification' => 'TAMC < 100 CFU/g'],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $ifra, [
        'use_limits' => [['ifra_product_category_id' => $category9->id, 'max_percent' => '12.5']],
        'facts' => ['peroxide_value_mmol_l' => '4.2'],
    ]);
    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $rce, [
        'use_limits' => [['product_class_label' => 'Autres produits à rincer', 'max_percent' => '100']],
    ]);

    expect($sheet->constituents()->sole()->unit)->toBe('ppm')
        ->and($sheet->refresh()->facts['microbiology_specification'])->toBe('TAMC < 100 CFU/g')
        ->and($ifra->useLimits()->sole()->ifra_product_category_id)->toBe($category9->id)
        ->and($rce->useLimits()->sole()->product_class_label)->toBe('Autres produits à rincer');
});

it('rejects data the document type cannot carry', function (EvidenceType $type, array $data, string $errorKey): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, $type);
    $data = json_decode(str_replace(
        ['"ALLERGEN"', '"SUBSTANCE"'],
        [(string) Allergen::factory()->create()->id, (string) Substance::factory()->create()->id],
        json_encode($data),
    ), true);

    try {
        app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, $data);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($errorKey);
    }
})->with([
    'impurity on an allergen declaration' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'impurity', 'substance_id' => 'SUBSTANCE', 'max_value' => '1']]], 'constituents.0.kind'],
    'allergen without allergen reference' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'allergen', 'substance_id' => 'SUBSTANCE', 'max_value' => '1']]], 'constituents.0.allergen_id'],
    'percentage above 100' => [EvidenceType::AllergenDeclaration, ['constituents' => [['kind' => 'allergen', 'allergen_id' => 'ALLERGEN', 'max_value' => '120']]], 'constituents.0.max_value'],
    'use limits on a technical sheet' => [EvidenceType::TechnicalSheet, ['use_limits' => [['product_class_label' => 'x', 'max_percent' => '1']]], 'use_limits'],
    'IFRA limit without category' => [EvidenceType::IfraCertificate, ['use_limits' => [['product_class_label' => 'x', 'max_percent' => '1']]], 'use_limits.0.ifra_product_category_id'],
    'unknown fact' => [EvidenceType::AllergenDeclaration, ['facts' => ['peroxide_value_mmol_l' => '1']], 'facts'],
]);

it('refuses data on a retired document or without the module', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $retired = evidenceOfType($workspace, EvidenceType::AllergenDeclaration);
    $retired->update(['retired_at' => now()]);

    expect(fn () => app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $retired, ['constituents' => []]))
        ->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    $plain = Workspace::factory()->for($outsider, 'owner')->create();

    expect(fn () => app(SaveDossierEvidenceData::class)->handle($outsider, $plain, evidenceOfType($plain, EvidenceType::AllergenDeclaration), ['constituents' => []]))
        ->toThrow(AuthorizationException::class);
});
