<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Exceptions\DossierCalculationException;
use App\Models\ProductFamily;
use App\Services\Dossiers\Calculation\DossierCompositionBuilder;
use App\Services\FormulaDocumentBuilder;
use App\Services\RecipeWorkbenchVersionDataService;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $composition
 * @return array<string, mixed>
 */
function compositionFinal(array $composition, string $key): array
{
    return collect($composition['final'])->firstWhere('key', $key) ?? throw new RuntimeException("No final row [{$key}].");
}

/**
 * @param  array<string, mixed>  $row
 * @return array{0: string, 1: string}
 */
function gramsAndPercent(array $row): array
{
    return [DecimalMath::round($row['grams'], 6), DecimalMath::round($row['percent'], 6)];
}

/**
 * @param  Closure(): mixed  $build
 */
function expectCalculationRefused(Closure $build, string $reason): void
{
    expect($build)->toThrow(function (DossierCalculationException $exception) use ($reason): void {
        expect($exception->reason)->toBe($reason);
    });
}

it('builds the initial and final composition of a simple olive soap', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $initial = collect($composition['initial'])->keyBy('key');

    expect(DecimalMath::round($initial['item:saponified_oils:0']['grams'], 4))->toBe('1000.0000')
        ->and(DecimalMath::round($initial['naoh']['grams'], 4))->toBe('127.3418')
        ->and(DecimalMath::round($initial['water']['grams'], 4))->toBe('380.0000')
        ->and(DecimalMath::round($composition['basis']['initial_mass_g'], 4))->toBe('1507.3418')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1266.676180')
        ->and($composition['basis']['residual_source'])->toBe('formula_estimate')
        ->and(gramsAndPercent(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')))->toBe(['979.607174', '77.336828'])
        ->and(gramsAndPercent(compositionFinal($composition, 'glycerin')))->toBe(['97.734626', '7.715834'])
        ->and(gramsAndPercent(compositionFinal($composition, 'unsaponified:ingredient:'.$soap['olive']->id)))->toBe(['50.000000', '3.947339'])
        ->and(gramsAndPercent(compositionFinal($composition, 'residual_water')))->toBe(['139.334380', '11.000000'])
        ->and(DecimalMath::round(collect($composition['losses'])->firstWhere('from', 'water')['grams'], 6))->toBe('240.665620')
        ->and(DecimalMath::round($composition['reactions'][0]['naoh_g'], 4))->toBe('127.3418')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('carries a fragrance at unchanged mass and agrees with the label cured output', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::oliveSoap(['fragrance' => [Fixtures::row($lavender, 3, 30)]]);

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $snapshot = app(RecipeWorkbenchVersionDataService::class)->versionSnapshot($soap['recipe'], $soap['version']->id);
    $label = collect(app(FormulaDocumentBuilder::class)->build($snapshot, ['calculation_basis' => 'oil_weight'])['soap_output']['rows'])
        ->mapWithKeys(fn (array $row): array => [$row['name'] => number_format($row['weight'], 4, '.', '')]);

    expect(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1300.384045')
        ->and(gramsAndPercent(compositionFinal($composition, 'carried:ingredient:'.$lavender->id)))->toBe(['30.000000', '2.307011'])
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['percent'], 6))->toBe('75.332143')
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams'], 4))->toBe($label['SODIUM OLIVATE'])
        ->and(DecimalMath::round(compositionFinal($composition, 'glycerin')['grams'], 4))->toBe($label['GLYCERIN'])
        ->and(DecimalMath::round(compositionFinal($composition, 'residual_water')['grams'], 4))->toBe($label['AQUA'])
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('uses the saved weights, not the independently rounded percentages', function (): void {
    // In weight-editing mode the workbench stores 1.2345 g with 0.0123 % and 0.001 g with 0.0000 % on a 10 kg basis.
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $mint = Fixtures::ingredient('Peppermint Oil', 'MENTHA PIPERITA OIL', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::oliveSoap(
        ['fragrance' => [Fixtures::row($lavender, 0.0123, 1.2345), Fixtures::row($mint, 0, 0.001)]],
        ['editing_mode' => 'weight'],
        oilWeight: 10000,
    );

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);

    expect(DecimalMath::round(compositionFinal($composition, 'carried:ingredient:'.$lavender->id)['grams'], 4))->toBe('1.2345')
        ->and(DecimalMath::round(compositionFinal($composition, 'carried:ingredient:'.$mint->id)['grams'], 4))->toBe('0.0010')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('takes a measured cure loss from the water only', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version'], measuredCureLossPercent: '15');

    expect($composition['basis']['residual_source'])->toBe('measured')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1281.240530')
        ->and(gramsAndPercent(compositionFinal($composition, 'residual_water')))->toBe(['153.898730', '12.011697'])
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams'], 6))->toBe('979.607174')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('uses a justified residual water percentage', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version'], residualWaterPercent: '9');

    expect($composition['basis']['residual_source'])->toBe('override')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1238.837143')
        ->and(DecimalMath::round(compositionFinal($composition, 'residual_water')['grams'], 6))->toBe('111.495343');
});

it('traces a dual-lye soap with KOH 100 % into both soap salts', function (): void {
    $soap = Fixtures::oliveSoap(payloadOverrides: ['lye_type' => 'dual', 'dual_lye_koh_percentage' => 40, 'koh_purity_percentage' => 100]);

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $initial = collect($composition['initial'])->keyBy('key');

    expect($composition['tracing']['passes'])->toBeTrue()
        ->and(DecimalMath::isPositive(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams']))->toBeTrue()
        ->and(DecimalMath::isPositive(compositionFinal($composition, 'soap_salt:POTASSIUM OLIVATE')['grams']))->toBeTrue()
        ->and(DecimalMath::round($composition['reactions'][0]['koh_g'], 4))->toBe(DecimalMath::round($initial['koh']['grams'], 4));
});

it('refuses formulas and materials the pilot engine does not support', function (string $reason): void {
    $builder = app(DossierCompositionBuilder::class);

    // Pest calls closures placed in a dataset, so each case is selected by its expected reason.
    match ($reason) {
        'working_copy' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap()['workingCopy']), $reason),
        'unsupported_family' => expectCalculationRefused(function () use ($builder) {
            $soap = Fixtures::oliveSoap();
            ProductFamily::query()->whereKey($soap['recipe']->product_family_id)->update(['calculation_basis' => 'total_formula']);

            return $builder->build($soap['version']);
        }, $reason),
        'measured_loss_out_of_range' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap()['version'], measuredCureLossPercent: '30'), $reason),
        'unsupported_reacting_material' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap(oil: Fixtures::oil(
            'Fractionated Coconut Oil', 'CAPRYLIC/CAPRIC TRIGLYCERIDE', 'SODIUM CAPRYLATE', 'POTASSIUM CAPRYLATE', 0.33, IngredientSubcategory::FractionatedModifiedLipids,
        ))['version']), $reason),
        'lye_substitute_unsupported' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap([
            'lye_water' => [Fixtures::row(Fixtures::ingredient('Glycerin', 'GLYCERIN', IngredientCategory::HumectantsPolyols), 25, 95)],
        ])['version']), $reason),
        'koh_non_active_share_unsupported' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap(
            payloadOverrides: ['lye_type' => 'koh', 'koh_purity_percentage' => 90],
        )['version']), $reason),
    };
})->with([
    'working_copy',
    'unsupported_family',
    'measured_loss_out_of_range',
    'unsupported_reacting_material',
    'lye_substitute_unsupported',
    'koh_non_active_share_unsupported',
]);

it('names the lye settings it cannot assess', function (array $lye, ?string $reason): void {
    expect(app(DossierCompositionBuilder::class)->unsupportedLyeReason($lye))->toBe($reason);
})->with([
    'supported' => [['superfat_percentage' => 5, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => ['koh_weight' => 0, 'koh_to_weigh' => 0]], null],
    'negative superfat' => [['superfat_percentage' => -1, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => []], 'negative_superfat'],
    'excess NaOH' => [['superfat_percentage' => 0, 'excess' => ['naoh_weight' => 0.5, 'koh_weight' => 0], 'selected' => []], 'excess_alkali'],
    'excess KOH' => [['superfat_percentage' => 0, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0.5], 'selected' => []], 'excess_alkali'],
    'KOH 90 %' => [['superfat_percentage' => 5, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => ['koh_weight' => 90, 'koh_to_weigh' => 100]], 'koh_non_active_share_unsupported'],
]);
