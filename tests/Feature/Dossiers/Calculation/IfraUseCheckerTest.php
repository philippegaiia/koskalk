<?php

use App\Enums\EvidenceType;
use App\Enums\IngredientCategory;
use App\Enums\SupplierQualificationStatus;
use App\Enums\UseLimitType;
use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use App\Models\IfraAmendment;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\Calculation\IfraUseChecker;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @return array{workspace: Workspace, category: IfraProductCategory, amendment: IfraAmendment, lavender: Ingredient}
 */
function ifraSetting(): array
{
    return [
        'workspace' => Workspace::factory()->create(),
        'category' => IfraProductCategory::factory()->create(['code' => '9']),
        'amendment' => IfraAmendment::factory()->create(['code' => '51']),
        'lavender' => Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials),
    ];
}

/**
 * A validated supplier's IFRA certificate. Pass null for a fact to leave it unrecorded.
 */
function supplierIfraCertificate(Workspace $workspace, Ingredient $ingredient, ?IfraProductCategory $category, ?IfraAmendment $amendment, ?string $maximum, ?bool $coversGrade = true, UseLimitType $type = UseLimitType::Maximum): SupplierQualification
{
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::IfraCertificate,
        'facts' => array_filter(['ifra_amendment_id' => $amendment?->id, 'covers_supplied_grade' => $coversGrade], fn (mixed $value): bool => $value !== null),
    ]);

    if ($category !== null) {
        DossierEvidenceUseLimit::factory()->create([
            'dossier_evidence_item_id' => $item->id,
            'ifra_product_category_id' => $category->id,
            'limit_type' => $type,
            'max_percent' => $maximum,
        ]);
    }

    return $qualification;
}

/**
 * The lavender at 30 g in a 1300 g cured soap: 2.307692 %.
 *
 * @param  array<string, mixed>  $setting
 * @return array<string, mixed>
 */
function lavenderUse(array $setting, ?string $justification = null, ?IfraProductCategory $category = null, ?int $amendmentId = -1): array
{
    $results = app(IfraUseChecker::class)->check(
        $setting['workspace'],
        [['key' => 'item:fragrance:0', 'ingredient_id' => $setting['lavender']->id, 'grams' => '30']],
        '1300',
        $category ?? $setting['category'],
        $amendmentId === -1 ? $setting['amendment']->id : $amendmentId,
        $justification,
    );

    return $results[0];
}

it('concludes when every validated supplier has an applicable certificate within its limit', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '3');

    $use = lavenderUse($setting);

    expect(DecimalMath::round($use['concentration_percent'], 6))->toBe('2.307692')
        ->and($use['state'])->toBe('conforme')
        ->and(DecimalMath::round($use['limit_percent'], 2))->toBe('3.00')
        ->and($use['uncovered'])->toBe([]);
});

it('fails on the strictest established limit even when another supplier is uncovered', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '2');
    SupplierQualification::factory()->create(['workspace_id' => $setting['workspace']->id, 'ingredient_id' => $setting['lavender']->id]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('non_conforme')
        ->and($use['reason'])->toBe('ifra_limit_exceeded');
});

it('treats a zero maximum as not permitted', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '0');

    expect(lavenderUse($setting)['state'])->toBe('non_conforme');
});

it('accepts an explicit no-restriction statement', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], null, type: UseLimitType::NoRestriction);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('conforme')
        ->and($use['limit_percent'])->toBeNull()
        ->and($use['limit_type'])->toBe('no_restriction');
});

it('names why a validated supplier does not conclude', function (Closure $certificate, string $reason): void {
    $setting = ifraSetting();
    $certificate($setting);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['reason'])->toBe('ifra_supplier_uncovered')
        ->and($use['uncovered'][0]['reason'])->toBe($reason);
})->with([
    'no certificate' => [fn (array $s) => SupplierQualification::factory()->create(['workspace_id' => $s['workspace']->id, 'ingredient_id' => $s['lavender']->id]), 'no_certificate'],
    'amendment not recorded' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], null, '5'), 'amendment_missing'],
    'older amendment' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], IfraAmendment::factory()->create(['code' => '50']), '5'), 'amendment_mismatch'],
    'grade not confirmed' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], $s['amendment'], '5', coversGrade: null), 'grade_not_confirmed'],
    'grade denied' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], $s['amendment'], '5', coversGrade: false), 'grade_not_confirmed'],
    'no limit for the category' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], IfraProductCategory::factory()->create(['code' => '4']), $s['amendment'], '5'), 'no_limit_for_category'],
]);

it('shows the ingredient certificate as an unconcluded fallback when no supplier is validated', function (?string $maximum, string $reason): void {
    $setting = ifraSetting();
    $certificate = IfraCertificate::factory()->create(['ingredient_id' => $setting['lavender']->id, 'ifra_amendment_id' => $setting['amendment']->id, 'is_current' => true]);
    IfraCertificateLimit::factory()->create(['ifra_certificate_id' => $certificate->id, 'ifra_product_category_id' => $setting['category']->id, 'max_percentage' => $maximum]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['reason'])->toBe($reason)
        ->and($use['fallback']['certificate_id'])->toBe($certificate->id);
})->with([
    'within the fallback limit' => ['5', 'ifra_fallback_only'],
    'above the fallback limit' => ['2', 'ifra_fallback_limit_exceeded'],
    'blank maximum means unknown' => [null, 'ifra_no_evidence'],
]);

it('requires category 9 or a justification, and a known amendment', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    $other = IfraProductCategory::factory()->create(['code' => '5A']);

    expect(lavenderUse($setting, category: $other)['reason'])->toBe('ifra_category_unresolved')
        ->and(lavenderUse($setting, amendmentId: null)['reason'])->toBe('ifra_amendment_unresolved')
        ->and(lavenderUse($setting)['state'])->toBe('conforme');
});

it('treats a category stated twice on one certificate as unresolved', function (): void {
    $setting = ifraSetting();
    // Saved before duplicates were refused: "no restriction" first, then 1 %.
    $qualification = supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], null, type: UseLimitType::NoRestriction);
    DossierEvidenceUseLimit::factory()->create([
        'dossier_evidence_item_id' => DossierEvidenceItem::query()->where('supplier_qualification_id', $qualification->id)->value('id'),
        'ifra_product_category_id' => $setting['category']->id,
        'limit_type' => UseLimitType::Maximum,
        'max_percent' => '1',
    ]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['uncovered'][0]['reason'])->toBe('duplicate_category_limits');
});

it('checks a blend that contains a fragrance, at its whole dosage, and keeps it to justify without a certificate', function (): void {
    $setting = ifraSetting();
    $blend = Fixtures::ingredient('House Base', 'GLYCERIN, PARFUM', IngredientCategory::BasesBlendsPremixes);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $setting['lavender']->id, 'percentage_in_parent' => 10]);

    $results = app(IfraUseChecker::class)->check($setting['workspace'], [['key' => 'item:additives:0', 'ingredient_id' => $blend->id, 'grams' => '26']], '1300', $setting['category'], $setting['amendment']->id, null);

    expect($results[0]['state'])->toBe('a_justifier')
        ->and($results[0]['reason'])->toBe('ifra_no_evidence')
        ->and(DecimalMath::round($results[0]['concentration_percent'], 6))->toBe('2.000000');
});

it('adds up an ingredient used in several rows before comparing it', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '2.2');

    $results = app(IfraUseChecker::class)->check($setting['workspace'], [
        ['key' => 'item:additives:0', 'ingredient_id' => $setting['lavender']->id, 'grams' => '20'],
        ['key' => 'item:fragrance:0', 'ingredient_id' => $setting['lavender']->id, 'grams' => '10'],
    ], '1300', $setting['category'], $setting['amendment']->id, null);

    // 20 g and 10 g are each below 2.2 % of 1300 g (28.6 g); together, 2.307692 %, they are above it.
    expect($results)->toHaveCount(1)
        ->and($results[0]['initial_keys'])->toBe(['item:additives:0', 'item:fragrance:0'])
        ->and($results[0]['state'])->toBe('non_conforme');
});

it('ignores a withdrawn certificate', function (): void {
    $setting = ifraSetting();
    $qualification = supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    DossierEvidenceItem::query()->where('supplier_qualification_id', $qualification->id)->update(['retired_at' => now()]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['uncovered'][0]['reason'])->toBe('no_certificate');
});

it('does not check an ingredient with no fragrance role and no IFRA data', function (): void {
    $setting = ifraSetting();
    $olive = Fixtures::ingredient('Olive Oil', 'OLEA EUROPAEA FRUIT OIL', IngredientCategory::Lipids);

    $results = app(IfraUseChecker::class)->check($setting['workspace'], [['key' => 'item:saponified_oils:0', 'ingredient_id' => $olive->id, 'grams' => '1000']], '1300', $setting['category'], $setting['amendment']->id, null);

    expect($results[0]['state'])->toBe('not_applicable');
});

it('ignores a supplier that is not validated', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    SupplierQualification::factory()->create([
        'workspace_id' => $setting['workspace']->id,
        'ingredient_id' => $setting['lavender']->id,
        'status' => SupplierQualificationStatus::EnEvaluation,
    ]);

    expect(lavenderUse($setting)['state'])->toBe('conforme');
});
