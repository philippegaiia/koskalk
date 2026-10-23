<?php

use App\Data\DossierAssessmentOptions;
use App\Enums\IngredientCategory;
use App\Enums\WorkspaceMemberRole;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\IngredientAllergenEntry;
use App\Models\Qra2Parameter;
use App\Models\RecipeVersion;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceMember;
use App\Services\Dossiers\Calculation\DossierAssessmentEngine;
use App\Support\DecimalMath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $soap
 * @return array<string, mixed>
 */
function assessSoap(array $soap, ?DossierAssessmentOptions $options = null): array
{
    return app(DossierAssessmentEngine::class)->assess($soap['owner'], $soap['workspace'], $soap['version'], $options ?? new DossierAssessmentOptions);
}

/**
 * @param  array<string, mixed>  $assessment
 * @return array<string, mixed>
 */
function assessedRow(array $assessment, string $key): array
{
    return collect($assessment['rows'])->firstWhere('key', $key) ?? throw new RuntimeException("No row [{$key}].");
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reviewedEuEntry(Substance $substance, array $attributes): RegulatoryLegalEntry
{
    $regime = RegulatoryRegime::query()->firstWhere('code', 'eu') ?? RegulatoryRegime::factory()->create(['code' => 'eu']);
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create(['regulatory_regime_id' => $regime->id, 'annex' => 'III', ...$attributes]);
    $entry->substances()->attach($substance->id);

    return $entry;
}

it('assesses the lavender soap end to end against the hand-calculated values', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $substances = $soap['substances'];

    $assessment = assessSoap($soap);
    $limonene = assessedRow($assessment, 'substance:'.$substances['limonene']->id);
    $glycerin = assessedRow($assessment, 'substance:'.$substances['glycerin']->id);

    expect($assessment['status'])->toBe('calculated')
        ->and($assessment['blockers'])->toBe([])
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse()
        ->and($assessment['composition']['tracing']['passes'])->toBeTrue()
        ->and($limonene['category'])->toBe('allergen')
        ->and(DecimalMath::round($limonene['concentration_percent'], 6))->toBe('0.922804')
        ->and(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.015380072')
        ->and(DecimalMath::round($limonene['indicators']['mos']['value'], 2))->toBe('4876.44')
        ->and($limonene['indicators']['qra2'])->toBe(['available' => false, 'reason' => 'no_qra2_preset'])
        ->and($limonene['state'])->toBe('route_suggere')
        ->and($limonene['suggested_route'])->toBe('mos')
        ->and($limonene['needs_justification'])->toBeFalse()
        ->and(DecimalMath::round($glycerin['indicators']['mos']['value'], 2))->toBe('64169.75')
        ->and($glycerin['profile']['basis'])->toBe('own')
        ->and(assessedRow($assessment, 'substance:'.$substances['aqua']->id)['suggested_route'])->toBe('cmax')
        ->and(assessedRow($assessment, 'substance:'.$substances['lavenderOil']->id)['indicators']['hazard_endpoints'])->toBe(['sensitisation'])
        ->and(collect($assessment['warnings'])->pluck('code')->all())->toContain('final_composition_estimated', 'no_qra2_preset', 'suppliers_without_declaration');
});

it('concludes rows only through confirmed routes, and an allergen only with QRA2', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $glycerinKey = 'substance:'.$soap['substances']['glycerin']->id;
    $limoneneKey = 'substance:'.$soap['substances']['limonene']->id;
    $options = new DossierAssessmentOptions(confirmations: [
        $glycerinKey => ['route' => 'mos', 'justification' => 'Oral NOAEL, SCCS default bioavailability.'],
        $limoneneKey => ['route' => 'mos', 'justification' => 'Systemic NOAEL; sensitisation through QRA2.', 'endpoints_addressed' => true],
    ]);

    $withoutQra2 = assessSoap($soap, $options);

    $category = IfraProductCategory::factory()->create();
    $amendment = IfraAmendment::factory()->create();
    Qra2Parameter::factory()->create(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    RecipeVersion::withoutGlobalScopes()->whereKey($soap['version']->id)->update(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    $soap['version'] = $soap['version']->fresh();
    $withQra2 = assessSoap($soap, $options);

    expect(assessedRow($withoutQra2, $glycerinKey)['state'])->toBe('conforme')
        ->and(assessedRow($withoutQra2, $limoneneKey)['state'])->toBe('a_justifier')
        ->and(assessedRow($withoutQra2, $limoneneKey)['state_reason'])->toBe('qra2_required')
        ->and(DecimalMath::round(assessedRow($withQra2, $limoneneKey)['indicators']['qra2']['cmax_percent'], 6))->toBe('8.333333')
        ->and(assessedRow($withQra2, $limoneneKey)['state'])->toBe('conforme');
});

it('records a justified override in the parameters used', function (): void {
    $soap = Fixtures::assessedLavenderSoap();

    $assessment = assessSoap($soap, new DossierAssessmentOptions(overrides: [
        'amount_per_use_g' => ['value' => '2', 'justification' => 'Large bar: weighed use of 2 g.'],
    ]));
    $limonene = assessedRow($assessment, 'substance:'.$soap['substances']['limonene']->id);

    expect(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.030760144')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'amount_per_use_g')['source'])->toBe('override')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'residual_water_percent')['source'])->toBe('formula_estimate');
});

it('checks legal limits on the actual substance even when it is assessed through a read-across profile', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $olivate = $soap['substances']['olivate'];
    $readAcross = Fixtures::substance('Olive fatty acid soaps', null, ['noael_mg_kg_bw_day' => '5000']);
    Fixtures::supersedeProfiles($olivate);
    $soap['coverages']['olivate']->delete();
    Fixtures::cover($olivate, $readAcross);
    reviewedEuEntry($olivate, ['max_concentration_percent' => '50']);
    $key = 'substance:'.$olivate->id;

    $row = assessedRow(assessSoap($soap, new DossierAssessmentOptions(confirmations: [
        $key => ['route' => 'mos', 'justification' => 'Read-across from olive fatty acid soaps.'],
    ])), $key);

    expect($row['profile']['basis'])->toBe('reference')
        ->and($row['profile']['substance_id'])->toBe($readAcross->id)
        ->and($row['indicators']['mos']['passes'])->toBeTrue()
        ->and($row['state'])->toBe('non_conforme')
        ->and($row['state_reason'])->toBe('binding_requirement');
});

it('sums reaction glycerin and added glycerin before checking a limit', function (): void {
    $glycerinIngredient = Fixtures::ingredient('Vegetable Glycerin', 'GLYCERIN', IngredientCategory::HumectantsPolyols);
    $soap = Fixtures::assessedLavenderSoap(['additives' => [Fixtures::row($glycerinIngredient, 2, 20)]]);
    reviewedEuEntry($soap['substances']['glycerin'], ['max_concentration_percent' => '8']);

    $row = assessedRow(assessSoap($soap), 'substance:'.$soap['substances']['glycerin']->id);
    $parts = collect($row['contributions'])->map(fn (array $part): string => DecimalMath::round($part['concentration_percent'], 6))->sort()->values()->all();

    expect(DecimalMath::round($row['concentration_percent'], 6))->toBe('8.900034')
        ->and($parts)->toBe(['1.511880', '7.388153'])
        ->and($row['state'])->toBe('non_conforme');
});

it('sums a neat allergen with the same allergen declared in another ingredient, without counting the neat one twice', function (): void {
    $neatLimonene = Fixtures::ingredient('d-Limonene', 'LIMONENE', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::assessedLavenderSoap(['fragrance' => [Fixtures::row($neatLimonene, 0.6, 6)]]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $neatLimonene->id, 'allergen_id' => $soap['limoneneAllergen']->id, 'concentration_percent' => 100]);
    reviewedEuEntry($soap['substances']['limonene'], ['max_concentration_percent' => '1']);

    $row = assessedRow(assessSoap($soap), 'substance:'.$soap['substances']['limonene']->id);

    expect(DecimalMath::round($row['concentration_percent'], 6))->toBe('1.377067')
        ->and($row['contributions'])->toHaveCount(2)
        ->and($row['category'])->toBe('allergen')
        ->and($row['state'])->toBe('non_conforme');
});

it('blocks submission for an unreviewed or unidentified final material', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['coverages']['glycerin']->delete();
    $soap['substances']['olivate']->update(['inci_name' => 'SODIUM OLIVATE (RENAMED)']);

    $assessment = assessSoap($soap);

    expect($assessment['can_submit'])->toBeFalse()
        ->and(collect($assessment['blockers'])->map(fn (array $blocker): string => $blocker['code'].':'.($blocker['key'] ?? ''))->all())
        ->toContain('material_not_reviewed:glycerin', 'final_material_unidentified:soap_salt:SODIUM OLIVATE');
});

it('blocks submission for an initial ingredient whose review is insufficient', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['coverages']['lavender']->forceFill(['review_state' => 'insufficient', 'insufficient_reason' => 'Grade not equivalent to the profiled oil.'])->save();

    $codes = collect(assessSoap($soap)['blockers'])->map(fn (array $blocker): string => $blocker['code'].':'.($blocker['scope'] ?? ''));

    expect($codes->all())->toContain('material_insufficient:initial');
});

it('keeps a non-conforming row submittable but not approvable', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    reviewedEuEntry($soap['substances']['limonene'], ['max_concentration_percent' => '0.5']);

    $assessment = assessSoap($soap);

    expect(assessedRow($assessment, 'substance:'.$soap['substances']['limonene']->id)['state'])->toBe('non_conforme')
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse();
});

it('reports a calculation that cannot run instead of failing', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['version'] = $soap['workingCopy'];

    $assessment = assessSoap($soap);

    expect($assessment['status'])->toBe('cannot_run')
        ->and($assessment['reason'])->toBe('working_copy')
        ->and($assessment['can_submit'])->toBeFalse()
        ->and($assessment['blockers'][0]['code'])->toBe('calculation_cannot_run');
});

it('refuses editors, revoked workspaces and formulas from another workspace', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($soap['workspace'])->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $other = Workspace::factory()->for($soap['owner'], 'owner')->create();
    WorkspaceDossierGrant::factory()->for($other)->create();
    $engine = app(DossierAssessmentEngine::class);

    expect(fn () => $engine->assess($editor, $soap['workspace'], $soap['version']))->toThrow(AuthorizationException::class)
        ->and(fn () => $engine->assess($soap['owner'], $other, $soap['version']))->toThrow(AuthorizationException::class);

    WorkspaceDossierGrant::query()->where('workspace_id', $soap['workspace']->id)->update(['revoked_at' => now()]);

    expect(fn () => assessSoap($soap))->toThrow(AuthorizationException::class);
});
