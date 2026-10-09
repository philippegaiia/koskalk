<?php

use App\Actions\FormulaSharing\AcceptFormulaShare;
use App\Enums\IngredientCategory;
use App\Enums\IngredientLabelMarket;
use App\Filament\Resources\RegulatoryRegimes\Pages\CreateRegulatoryRegime;
use App\Livewire\Dashboard\SettingsIndex;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientMarketLabel;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FormulaSharePreview;
use App\Services\InciGenerationService;
use App\Services\RecipeWorkbenchService;
use App\Services\RecipeWorkbenchViewDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FormulaSharingFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap']);
});

it('compares soap allergen thresholds with the cured product rather than the wet batch', function (): void {
    // 20 g of fragrance at 0.7% allergen: about 0.009% of the wet batch but
    // about 0.011% of the cured soap, which crosses the 0.01% rinse-off threshold.
    $fixture = marketCheckFormula(allergenConcentration: 0.7);
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active'])->id,
        'allergen_id' => $fixture['allergen']->id,
    ]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'eu'),
    );
    $row = collect($snapshot['labeling']['declaration_rows'])->firstWhere('label', 'LINALOOL');

    expect($row['percent_of_formula'])->toBeLessThan(0.01)
        ->and($row['percent_of_cured_basis'])->toBeGreaterThanOrEqual(0.01)
        ->and($row['exceeds_threshold'])->toBeTrue()
        ->and($row['included_in_inci'])->toBeTrue()
        ->and($snapshot['labeling']['final_labels'])->toContain('LINALOOL');
});

it('flags a soap list saved under the wet batch basis for review', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 0.7);
    RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    $wetBasisHash = fn (array $payload): string => hash('sha256', json_encode(marketCheckSortedForHash($payload), JSON_THROW_ON_ERROR));
    $listState = function (array $payload) use ($wetBasisHash): array {
        $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation($payload);

        return app(InciGenerationService::class)->generate([
            ...$payload,
            'final_ingredient_list' => 'OLEA EUROPAEA FRUIT OIL, LAVANDULA ANGUSTIFOLIA OIL',
            'final_ingredient_list_basis_hash' => $wetBasisHash($payload),
        ], $calculation)['final_ingredient_list'];
    };

    $soap = marketCheckSavePayload($fixture, 'eu');
    $blend = [...$soap, 'manufacturing_mode' => 'blend_only'];

    expect($listState($soap)['is_outdated'])->toBeTrue()
        ->and($listState($blend)['is_outdated'])->toBeFalse();
});

it('uses canonical INCI names rather than EU declaration names when no market is checked', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    IngredientMarketLabel::factory()->create([
        'ingredient_id' => $fixture['fragrance']->id,
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'LAVANDULA ANGUSTIFOLIA HERB OIL',
    ]);
    RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);

    $labels = fn (string $market): array => app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, $market),
    )['labeling']['final_labels'];

    expect($labels('eu'))->toContain('LAVANDULA ANGUSTIFOLIA HERB OIL')
        ->and($labels(RegulatoryRegime::NONE_CODE))->toContain('LAVANDULA ANGUSTIFOLIA OIL')
        ->not->toContain('LAVANDULA ANGUSTIFOLIA HERB OIL')
        ->and($labels('retired_market'))->toContain('LAVANDULA ANGUSTIFOLIA OIL');
});

it('treats a market outside its effective dates as unavailable', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    RegulatoryRegime::factory()->create(['code' => 'future_market', 'market_code' => 'xx', 'status' => 'active', 'effective_from' => now()->addMonth()]);
    RegulatoryRegime::factory()->create(['code' => 'ended_market', 'market_code' => 'yy', 'status' => 'active', 'effective_until' => now()->subDay()]);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'future_market'),
    );

    expect(RegulatoryRegime::normalizeCode('future_market'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('ended_market'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(array_keys(RegulatoryRegime::selectableMarkets()))->toBe([RegulatoryRegime::NONE_CODE])
        ->and($snapshot['labeling']['market_check']['state'])->toBe('unavailable')
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked');
});

it('keeps composition but applies no market rule when no market is selected', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => $regime->id,
        'allergen_id' => $fixture['allergen']->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'threshold_operator' => 'greater_than_or_equal',
        'is_active' => true,
    ]);
    marketCheckStarterRule($regime, $fixture['fragrance']);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, RegulatoryRegime::NONE_CODE),
    );
    $row = collect($snapshot['labeling']['declaration_rows'])->firstWhere('label', 'LINALOOL');

    expect($snapshot['labeling']['market_check']['state'])->toBe('not_selected')
        ->and($row)->not->toBeNull()
        ->and($row['threshold_percent'])->toBeNull()
        ->and($row['exceeds_threshold'])->toBeFalse()
        ->and($row['included_in_inci'])->toBeFalse()
        ->and($row['status_label'])->toBe(__('workbench.output.market_check.not_checked'))
        ->and($snapshot['labeling']['final_labels'])->not->toContain('LINALOOL')
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked')
        ->and($snapshot['restrictions']['rows'])->toBe([])
        ->and($snapshot['restrictions']['warnings'])->toBe([]);
});

it('fails closed instead of applying EU rules when the stored market is unavailable', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'retired_market'),
    );
    $unavailable = __('workbench.output.market_check.unavailable', ['market' => 'retired_market']);

    expect($snapshot['labeling']['market_check']['state'])->toBe('unavailable')
        ->and($snapshot['labeling']['final_labels'])->not->toContain('LINALOOL')
        ->and($snapshot['labeling']['warnings'])->toContain($unavailable)
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked')
        ->and($snapshot['restrictions']['warnings'])->toBe([$unavailable]);
});

it('does not treat EU as a built-in market when no EU regime is available', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'eu'),
    );

    expect(RegulatoryRegime::normalizeCode('eu'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and($snapshot['labeling']['market_check']['state'])->toBe('unavailable')
        ->and($snapshot['labeling']['final_labels'])->not->toContain('LINALOOL')
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked');
});

it('reports a saved formula as unavailable when its market regime was removed', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    $regime = RegulatoryRegime::factory()->create(['code' => 'canada_2026', 'market_code' => 'ca', 'status' => 'active']);
    $version = app(RecipeWorkbenchService::class)->save(
        User::factory()->create(),
        ProductFamily::query()->where('slug', 'soap')->firstOrFail(),
        marketCheckSavePayload($fixture, 'canada_2026'),
    );

    $regime->delete();

    $snapshot = app(RecipeWorkbenchService::class)->currentVersionSnapshot(
        Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id),
    );
    $unavailable = __('workbench.output.market_check.unavailable', ['market' => 'canada_2026']);

    expect($snapshot['draft']['regulatoryRegime'])->toBe('canada_2026')
        ->and($snapshot['labeling']['market_check']['state'])->toBe('unavailable')
        ->and($snapshot['labeling']['warnings'])->toContain($unavailable)
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked');
});

it('treats a draft or payload without a market as not checked rather than EU', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => $regime->id,
        'allergen_id' => $fixture['allergen']->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'threshold_operator' => 'greater_than_or_equal',
        'is_active' => true,
    ]);
    $draft = marketCheckDraft($fixture, 'eu');
    unset($draft['regulatoryRegime']);
    $payload = marketCheckSavePayload($fixture, 'eu');
    unset($payload['regulatory_regime']);

    $snapshot = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft($draft);
    $version = app(RecipeWorkbenchService::class)->save(
        User::factory()->create(),
        ProductFamily::query()->where('slug', 'soap')->firstOrFail(),
        $payload,
    );

    expect($snapshot['labeling']['market_check']['state'])->toBe('not_selected')
        ->and($snapshot['labeling']['final_labels'])->not->toContain('LINALOOL')
        ->and($snapshot['restrictions']['summary']['status'])->toBe('not_checked')
        ->and($version->regulatory_regime)->toBe(RegulatoryRegime::NONE_CODE);
});

it('normalizes blank, unknown and retired market codes to no market', function (): void {
    RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    RegulatoryRegime::factory()->create(['code' => 'canada_2026', 'market_code' => 'ca', 'status' => 'active']);
    RegulatoryRegime::factory()->create(['code' => 'old_market', 'market_code' => 'xx', 'status' => 'retired']);

    expect(RegulatoryRegime::normalizeCode(null))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('  '))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('none'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('retired_market'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('old_market'))->toBe(RegulatoryRegime::NONE_CODE)
        ->and(RegulatoryRegime::normalizeCode('EU'))->toBe('eu')
        ->and(RegulatoryRegime::normalizeCode('canada_2026'))->toBe('canada_2026');
});

it('shows starter substance rules as unverified rather than needing review', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    marketCheckStarterRule($regime, $fixture['fragrance']);

    $restrictions = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'eu'),
    )['restrictions'];
    $row = $restrictions['rows'][0];

    expect($restrictions['summary']['status'])->toBe('unverified')
        ->and($restrictions['summary']['warning_count'])->toBe(0)
        ->and($row['status'])->toBe('starter_rule')
        ->and($row['requires_review'])->toBeFalse();
});

it('reports a market without substance rules as having no rules rather than passing', function (): void {
    $fixture = marketCheckFormula(allergenConcentration: 50);
    RegulatoryRegime::factory()->create(['code' => 'us_mocra_preview', 'market_code' => 'us', 'status' => 'preview']);

    $restrictions = app(RecipeWorkbenchService::class)->snapshotFromWorkbenchDraft(
        marketCheckDraft($fixture, 'us_mocra_preview'),
    )['restrictions'];

    expect($restrictions['summary']['status'])->toBe('no_rules')
        ->and($restrictions['regime']['market_check'])->toBe('checked')
        ->and($restrictions['rows'])->toBe([])
        ->and($restrictions['warnings'])->toBe([__('workbench.output.restrictions.no_active_rule_set')]);
});

it('treats only rules with limits or an official source as specific', function (): void {
    $starter = RegulatoryRegimeSubstanceRule::factory()->make([
        'rule_type' => 'watch',
        'source_reference' => 'Platform starter rule. Replace with official source before relying on it.',
    ]);
    $sourcedWatch = RegulatoryRegimeSubstanceRule::factory()->make([
        'rule_type' => 'watch',
        'source_reference' => 'Regulation (EC) No 1223/2009, Annex III',
    ]);
    $restricted = RegulatoryRegimeSubstanceRule::factory()->make([
        'rule_type' => 'restricted',
        'rinse_off_max_percent' => 0.1,
    ]);

    expect($starter->hasSpecificComplianceData())->toBeFalse()
        ->and($sourcedWatch->hasSpecificComplianceData())->toBeTrue()
        ->and($restricted->hasSpecificComplianceData())->toBeTrue();
});

it('shares and imports a formula that is not checked against a market', function (): void {
    config(['workspaces.formula_sharing.enabled' => true]);
    extract(FormulaSharingFixtures::offer('cosmetic', regulatoryRegime: RegulatoryRegime::NONE_CODE));
    $actor = $recipient->owner;

    $accepted = app(AcceptFormulaShare::class)->handle(
        $actor,
        $share,
        [],
        app(FormulaSharePreview::class)->build($actor, $share, [])['expected_hash'],
    );

    expect(data_get($share->fresh()->snapshot, 'formula.regulatory_regime'))->toBe([
        'id' => null,
        'code' => RegulatoryRegime::NONE_CODE,
        'status' => null,
    ])
        ->and(RecipeVersion::withoutGlobalScopes()->where('recipe_id', $accepted->id)->pluck('regulatory_regime')->unique()->all())
        ->toBe([RegulatoryRegime::NONE_CODE]);
});

it('saves a workspace default market and starts new workbench formulas on it', function (): void {
    RegulatoryRegime::factory()->create(['code' => 'canada_2026', 'market_code' => 'ca', 'name' => 'Canada', 'status' => 'active']);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create(['name' => 'Soap Studio']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertSet('workspaceDefaultRegulatoryRegime', RegulatoryRegime::NONE_CODE)
        ->set('workspaceDefaultRegulatoryRegime', 'retired_market')
        ->call('saveWorkspace')
        ->assertHasErrors(['workspaceDefaultRegulatoryRegime'])
        ->set('workspaceDefaultRegulatoryRegime', 'canada_2026')
        ->call('saveWorkspace')
        ->assertHasNoErrors()
        ->assertSet('workspaceStatus', 'success');

    $workbench = app(RecipeWorkbenchViewDataBuilder::class)->build(ProductFamily::query()->where('slug', 'soap')->firstOrFail(), null, $user->fresh());

    expect($workspace->refresh()->default_regulatory_regime)->toBe('canada_2026')
        ->and($workbench['defaultRegulatoryRegime'])->toBe('canada_2026');
});

it('fails closed to no market when the workspace default is no longer available', function (): void {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['default_regulatory_regime' => 'retired_market']);

    $workbench = app(RecipeWorkbenchViewDataBuilder::class)->build(ProductFamily::query()->where('slug', 'soap')->firstOrFail(), null, $user);

    expect($workbench['defaultRegulatoryRegime'])->toBe(RegulatoryRegime::NONE_CODE);
});

it('shows a retired workspace default market instead of resetting it silently', function (): void {
    $user = User::factory()->create();
    Workspace::factory()->for($user, 'owner')->create(['default_regulatory_regime' => 'retired_market']);

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertSet('workspaceDefaultRegulatoryRegime', RegulatoryRegime::NONE_CODE)
        ->assertSet('unavailableDefaultRegulatoryRegime', 'retired_market')
        ->set('activeTab', 'workspace')
        ->assertSee(__('settings.workspace.default_market_unavailable', ['market' => 'retired_market']))
        ->call('saveWorkspace')
        ->assertHasNoErrors()
        ->assertSet('unavailableDefaultRegulatoryRegime', null);
});

it('reserves the none code so no regime can replace the no market option', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateRegulatoryRegime::class)
        ->fillForm([
            'code' => ' None ',
            'market_code' => 'XX',
            'name' => 'Not a market',
            'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreateRegulatoryRegime::class)
        ->fillForm([
            'code' => ' UK_2027 ',
            'market_code' => 'UK',
            'name' => 'United Kingdom',
            'status' => 'preview',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateRegulatoryRegime::class)
        ->fillForm([
            'code' => ' Uk_2027',
            'market_code' => 'UK',
            'name' => 'United Kingdom again',
            'status' => 'preview',
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(RegulatoryRegime::query()->where('code', 'uk_2027')->count())->toBe(1)
        ->and(RegulatoryRegime::query()->where('code', 'none')->exists())->toBeFalse();
});

it('labels a shared formula without a market as none', function (): void {
    expect(__('sharing.values.'.RegulatoryRegime::NONE_CODE))->toBe('None');
});

/**
 * Mirrors the service's key ordering so a test can build the hash a list was
 * saved with before the cured threshold basis existed.
 *
 * @param  array<mixed>  $value
 * @return array<mixed>
 */
function marketCheckSortedForHash(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = marketCheckSortedForHash($item);
        }
    }

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

/**
 * @return array{oil: Ingredient, fragrance: Ingredient, allergen: Allergen}
 */
function marketCheckFormula(float $allergenConcentration): array
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.188]);

    $fragrance = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $allergen = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $fragrance->id,
        'allergen_id' => $allergen->id,
        'concentration_percent' => $allergenConcentration,
        'source_notes' => null,
    ]);

    return compact('oil', 'fragrance', 'allergen');
}

function marketCheckStarterRule(RegulatoryRegime $regime, Ingredient $fragrance): void
{
    $linalool = Substance::factory()->create(['name' => 'Linalool', 'entity_type' => 'constituent']);
    IngredientSubstanceEntry::factory()->for($fragrance, 'ingredient')->for($linalool, 'substance')->create([
        'concentration_percent' => 50,
        'concentration_source' => 'supplier',
    ]);
    RegulatoryRegimeSubstanceRule::factory()->for($regime, 'regulatoryRegime')->for($linalool, 'substance')->create([
        'rule_type' => 'watch',
        'rinse_off_max_percent' => null,
        'leave_on_max_percent' => null,
        'source_reference' => 'Platform starter rule. Replace with official source before relying on it.',
        'is_active' => true,
    ]);
}

/**
 * @param  array{oil: Ingredient, fragrance: Ingredient, allergen: Allergen}  $fixture
 * @return array<string, mixed>
 */
function marketCheckSavePayload(array $fixture, string $regulatoryRegime): array
{
    $soapFamily = ProductFamily::query()->where('slug', 'soap')->firstOrFail();
    $row = fn (Ingredient $ingredient, float $percentage, float $weight): array => [
        'ingredient_id' => $ingredient->id,
        'percentage' => $percentage,
        'weight' => $weight,
        'note' => null,
    ];

    return [
        'name' => 'Market check formula',
        'product_type_id' => ProductType::factory()->create(['product_family_id' => $soapFamily->id])->id,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => $regulatoryRegime,
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [$row($fixture['oil'], 100, 1000)],
            'additives' => [],
            'fragrance' => [$row($fixture['fragrance'], 2, 20)],
        ],
    ];
}

/**
 * @param  array{oil: Ingredient, fragrance: Ingredient, allergen: Allergen}  $fixture
 * @return array<string, mixed>
 */
function marketCheckDraft(array $fixture, string $regulatoryRegime): array
{
    $row = fn (Ingredient $ingredient, float $percentage, float $weight): array => [
        'id' => 'row-'.$ingredient->id,
        'ingredient_id' => $ingredient->id,
        'name' => $ingredient->display_name,
        'inci_name' => $ingredient->inci_name,
        'category' => $ingredient->category?->value,
        'percentage' => $percentage,
        'weight' => $weight,
        'note' => null,
    ];

    return [
        'formulaName' => 'Market check preview',
        'oilUnit' => 'g',
        'oilWeight' => 1000,
        'manufacturingMode' => 'saponify_in_formula',
        'exposureMode' => 'rinse_off',
        'regulatoryRegime' => $regulatoryRegime,
        'editMode' => 'percentage',
        'lyeType' => 'naoh',
        'kohPurity' => 90,
        'dualKohPercentage' => 40,
        'waterMode' => 'percent_of_oils',
        'waterValue' => 38,
        'superfat' => 5,
        'selectedIfraProductCategoryId' => null,
        'phaseItems' => [
            'saponified_oils' => [$row($fixture['oil'], 100, 1000)],
            'additives' => [],
            'fragrance' => [$row($fixture['fragrance'], 2, 20)],
        ],
    ];
}
