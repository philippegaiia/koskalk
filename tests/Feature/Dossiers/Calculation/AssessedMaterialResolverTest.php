<?php

use App\Enums\IngredientCategory;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\Calculation\AssessedMaterialResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @return array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, percent: string}
 */
function identityFinalRow(string $key, string $kind, ?Ingredient $ingredient, ?string $inci, string $percent): array
{
    return ['key' => $key, 'kind' => $kind, 'ingredient_id' => $ingredient?->id, 'name' => $ingredient?->display_name ?? (string) $inci, 'inci_name' => $inci, 'percent' => $percent];
}

it('identifies a soap salt by its INCI name', function (): void {
    $olivate = Fixtures::substance('Sodium olivate', 'Sodium Olivate');

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow('soap_salt:SODIUM OLIVATE', 'soap_salt', null, 'SODIUM OLIVATE', '75.3'));

    expect($contributions)->toHaveCount(1)
        ->and($contributions[0]['substance']->is($olivate))->toBeTrue()
        ->and($contributions[0]['ingredient'])->toBeNull()
        ->and($contributions[0]['unidentified_reason'])->toBeNull();
});

it('reports a final material missing from the catalogue or ambiguous', function (int $matches, string $reason): void {
    Substance::factory()->count($matches)->create(['inci_name' => 'SODIUM COCOATE']);

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow('soap_salt:SODIUM COCOATE', 'soap_salt', null, 'SODIUM COCOATE', '10'));

    expect($contributions[0]['substance'])->toBeNull()
        ->and($contributions[0]['unidentified_reason'])->toBe($reason);
})->with([
    'not in the catalogue' => [0, 'not_in_catalogue'],
    'two catalogue rows' => [2, 'ambiguous_inci'],
]);

it('identifies a carried ingredient by its INCI and keeps the ingredient for its coverage', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $oil = Fixtures::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL');

    $contribution = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow("carried:ingredient:{$lavender->id}", 'carried', $lavender, 'LAVANDULA ANGUSTIFOLIA OIL', '2.3'))[0];

    expect($contribution['substance']->is($oil))->toBeTrue()
        ->and($contribution['ingredient']->is($lavender))->toBeTrue();
});

it('reads a blend through its components', function (): void {
    $blend = Fixtures::ingredient('Blend', 'PARFUM', IngredientCategory::AromaticMaterials);
    $component = Fixtures::ingredient('Orange Oil', 'CITRUS AURANTIUM DULCIS PEEL OIL', IngredientCategory::AromaticMaterials);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $component->id, 'percentage_in_parent' => 40]);

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow("carried:ingredient:{$blend->id}", 'carried', $blend, 'PARFUM', '2'));

    // The 60 % the component does not account for stays as an unidentified share (see the next test).
    expect($contributions)->toHaveCount(2)
        ->and($contributions[0]['source_key'])->toBe("carried:ingredient:{$blend->id}|component:{$component->id}")
        ->and(DecimalMath::round($contributions[0]['concentration_percent'], 6))->toBe('0.800000')
        ->and($contributions[0]['ingredient']->is($component))->toBeTrue();
});

it('uses the identity profile first and a single coverage reference as recorded read-across', function (): void {
    $olivate = Fixtures::substance('Sodium olivate', 'SODIUM OLIVATE');
    $reference = Fixtures::substance('Olive fatty acid soaps', null);
    Fixtures::cover($olivate, $reference);
    $resolver = app(AssessedMaterialResolver::class);

    $own = $resolver->profileFor($olivate, [$olivate]);
    Fixtures::supersedeProfiles($olivate);
    $readAcross = $resolver->profileFor($olivate, [$olivate]);

    expect($own['basis'])->toBe('own')
        ->and($own['profile']->substance_id)->toBe($olivate->id)
        ->and($readAcross['basis'])->toBe('reference')
        ->and($readAcross['reference_substance_id'])->toBe($reference->id)
        ->and($readAcross['profile']->substance_id)->toBe($reference->id);
});

it('refuses to choose between several references and ignores tracked constituents', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $first = Fixtures::substance('Lavender oil, profile A', null);
    $second = Fixtures::substance('Lavender oil, profile B', null);
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => Allergen::factory()->create()->id]);
    Fixtures::cover($lavender, $first, $limonene);
    $resolver = app(AssessedMaterialResolver::class);

    expect($resolver->profileFor(null, [$lavender])['reference_substance_id'])->toBe($first->id);

    $otherLavender = Fixtures::ingredient('Lavender Oil, second supplier grade', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    Fixtures::cover($otherLavender, $second);
    $both = $resolver->profileFor(null, [$lavender, $otherLavender]);

    expect($both['profile'])->toBeNull()
        ->and($both['reason'])->toBe('ambiguous_reference');
});

it('flags a review that relied on a superseded profile and finds an allergen through its catalogue link', function (): void {
    $allergen = Allergen::factory()->create();
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => $allergen->id]);
    Fixtures::cover($limonene, $limonene);
    Fixtures::supersedeProfiles($limonene);
    ToxicologyProfile::factory()->create(['substance_id' => $limonene->id, 'version' => 2]);
    $resolver = app(AssessedMaterialResolver::class);

    expect($resolver->coverageSummary($limonene)['relies_on_superseded_profiles'])->toBeTrue()
        ->and($resolver->substanceForOverlay(['allergen_id' => $allergen->id, 'substance_id' => null])->is($limonene))->toBeTrue()
        ->and($resolver->profileFor($limonene, [$limonene])['profile']->version)->toBe(2);
});

it('keeps the unidentified share of a blend instead of dropping it', function (string $case): void {
    $blend = Fixtures::ingredient('Blend', 'PARFUM', IngredientCategory::AromaticMaterials);
    $orange = Fixtures::ingredient('Orange Oil', 'CITRUS AURANTIUM DULCIS PEEL OIL', IngredientCategory::AromaticMaterials);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $orange->id, 'percentage_in_parent' => 40]);

    if ($case === 'unlinked component') {
        IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => null, 'percentage_in_parent' => 60, 'sort_order' => 2]);
    }

    $contributions = collect(app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow("carried:ingredient:{$blend->id}", 'carried', $blend, 'PARFUM', '2')));
    $unresolved = $contributions->firstWhere('unidentified_reason', 'unresolved_blend_component');

    expect($contributions)->toHaveCount(2)
        ->and($unresolved['source_key'])->toBe("carried:ingredient:{$blend->id}|unresolved")
        ->and(DecimalMath::round($unresolved['concentration_percent'], 6))->toBe('1.200000')
        ->and($unresolved['substance'])->toBeNull();
})->with(['unlinked component', 'components below 100 %']);
