<?php

use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\Qra2Parameter;
use App\Models\User;
use App\Services\Dossiers\AppendOnlyVersionPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('versions the global assessment policy as one series', function (): void {
    $admin = User::factory()->admin()->create();
    $publisher = app(AppendOnlyVersionPublisher::class);
    $policy = [
        'default_dermal_absorption_percent' => '100',
        'default_oral_bioavailability_percent' => '50',
        'mos_threshold' => '100',
        'body_weight_kg' => '60',
        'source_reference' => 'SCCS Notes of Guidance; 100% DAp conservative policy pending assessor confirmation',
    ];

    $publisher->publish(AssessmentPolicy::class, $policy, $admin);
    $second = $publisher->publish(AssessmentPolicy::class, [...$policy, 'default_dermal_absorption_percent' => '50'], $admin);

    expect(AssessmentPolicy::query()->current()->sole()->is($second))->toBeTrue()
        ->and($second->version)->toBe(2);
});

it('versions exposure presets per product type', function (): void {
    $admin = User::factory()->admin()->create();
    $soap = ProductType::factory()->create();
    $balm = ProductType::factory()->create();
    $preset = [
        'amount_per_use_g' => '1',
        'uses_per_day' => '10',
        'retention_factor' => '0.01',
        'is_rinse_off' => true,
        'application_sites' => ['body', 'face'],
        'direct_routes' => ['dermal'],
        'secondary_routes' => ['ocular'],
        'target_population' => 'adults',
        'source_reference' => 'SCCS Notes of Guidance, table of daily exposure',
    ];
    $publisher = app(AppendOnlyVersionPublisher::class);

    $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $soap->id], $admin);
    $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $soap->id, 'uses_per_day' => '6'], $admin);
    $balmPreset = $publisher->publish(ExposurePreset::class, [...$preset, 'product_type_id' => $balm->id, 'is_rinse_off' => false, 'retention_factor' => '1'], $admin);

    expect(ExposurePreset::query()->where('product_type_id', $soap->id)->current()->sole()->version)->toBe(2)
        ->and($balmPreset->version)->toBe(1)
        ->and($balmPreset->application_sites)->toBe(['body', 'face']);
});

it('versions QRA2 parameters per IFRA category and amendment and keeps the factor decision explicit', function (): void {
    $admin = User::factory()->admin()->create();
    $parameters = app(AppendOnlyVersionPublisher::class)->publish(Qra2Parameter::class, [
        'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
        'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
        'sensitization_assessment_factor' => '300',
        'consumer_exposure_level_mg_cm2_day' => '0.2',
        'qra2_factor' => '0.5',
        'applies_qra2_factor' => false,
        'source_reference' => 'IFRA 49th amendment guidance',
    ], $admin);

    expect($parameters->version)->toBe(1)
        ->and($parameters->applies_qra2_factor)->toBeFalse()
        ->and($parameters->qra2_factor)->toBe('0.5000');
});

it('refuses to edit a published parameter version', function (): void {
    $policy = AssessmentPolicy::factory()->create();

    expect(fn () => $policy->update(['mos_threshold' => '50']))->toThrow(LogicException::class);
});

it('retries when a concurrent publication takes the same version number', function (): void {
    $productType = ProductType::factory()->create();
    $raced = false;

    ExposurePreset::creating(function (ExposurePreset $preset) use (&$raced, $productType): void {
        if ($raced) {
            return;
        }

        $raced = true;
        ExposurePreset::factory()->create(['product_type_id' => $productType->id, 'version' => $preset->version]);
    });

    $published = app(AppendOnlyVersionPublisher::class)->publish(ExposurePreset::class, [
        ...ExposurePreset::factory()->raw(['product_type_id' => $productType->id]),
    ], User::factory()->admin()->create());

    // The simulated competitor shares the publisher's transaction, so it rolls back with the failed attempt;
    // what matters is that the collision is retried instead of escaping as an error.
    expect($raced)->toBeTrue()
        ->and(ExposurePreset::query()->where('product_type_id', $productType->id)->current()->sole()->is($published))->toBeTrue();
});

it('rejects out-of-range scientific inputs before publication', function (string $modelClass, array $attributes, string $errorKey): void {
    $base = match ($modelClass) {
        AssessmentPolicy::class => AssessmentPolicy::factory()->raw(),
        ExposurePreset::class => ExposurePreset::factory()->raw(['product_type_id' => ProductType::factory()->create()->id]),
        Qra2Parameter::class => Qra2Parameter::factory()->raw([
            'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
            'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
        ]),
    };

    try {
        app(AppendOnlyVersionPublisher::class)->publish($modelClass, [...$base, ...$attributes], User::factory()->admin()->create());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey)
            ->and($modelClass::query()->count())->toBe(0);
    }
})->with([
    'zero body weight' => [AssessmentPolicy::class, ['body_weight_kg' => '0'], 'body_weight_kg'],
    'dermal absorption above 100%' => [AssessmentPolicy::class, ['default_dermal_absorption_percent' => '101'], 'default_dermal_absorption_percent'],
    'negative oral bioavailability' => [AssessmentPolicy::class, ['default_oral_bioavailability_percent' => '-1'], 'default_oral_bioavailability_percent'],
    'zero MoS threshold' => [AssessmentPolicy::class, ['mos_threshold' => '0'], 'mos_threshold'],
    'zero amount per use' => [ExposurePreset::class, ['amount_per_use_g' => '0'], 'amount_per_use_g'],
    'zero uses per day' => [ExposurePreset::class, ['uses_per_day' => '0'], 'uses_per_day'],
    'retention above 1' => [ExposurePreset::class, ['retention_factor' => '1.5'], 'retention_factor'],
    'no application site' => [ExposurePreset::class, ['application_sites' => []], 'application_sites'],
    'zero SAF' => [Qra2Parameter::class, ['sensitization_assessment_factor' => '0'], 'sensitization_assessment_factor'],
    'zero CEL' => [Qra2Parameter::class, ['consumer_exposure_level_mg_cm2_day' => '0'], 'consumer_exposure_level_mg_cm2_day'],
    'factor applied without a factor' => [Qra2Parameter::class, ['applies_qra2_factor' => true, 'qra2_factor' => null], 'qra2_factor'],
    'factor above 1' => [Qra2Parameter::class, ['qra2_factor' => '2'], 'qra2_factor'],
]);
