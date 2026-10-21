<?php

use App\Filament\Resources\AssessmentPolicies\Pages\CreateAssessmentPolicy;
use App\Filament\Resources\ExposurePresets\Pages\CreateExposurePreset;
use App\Filament\Resources\Qra2Parameters\Pages\CreateQra2Parameter;
use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\ProductType;
use App\Models\Qra2Parameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

it('publishes an assessment policy version from the admin', function (): void {
    Livewire::test(CreateAssessmentPolicy::class)
        ->fillForm([
            'default_dermal_absorption_percent' => '100',
            'default_oral_bioavailability_percent' => '50',
            'mos_threshold' => '100',
            'body_weight_kg' => '60',
            'source_reference' => 'SCCS Notes of Guidance',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AssessmentPolicy::query()->current()->sole()->version)->toBe(1);
});

it('requires a source for an exposure preset', function (): void {
    Livewire::test(CreateExposurePreset::class)
        ->fillForm([
            'product_type_id' => ProductType::factory()->create()->id,
            'amount_per_use_g' => '1',
            'uses_per_day' => '10',
            'retention_factor' => '0.01',
            'is_rinse_off' => true,
            'application_sites' => ['body'],
            'direct_routes' => ['dermal'],
            'secondary_routes' => [],
            'target_population' => 'adults',
            'source_reference' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['source_reference' => 'required']);

    expect(ExposurePreset::query()->count())->toBe(0);
});

it('publishes a QRA2 preset for a category and amendment', function (): void {
    Livewire::test(CreateQra2Parameter::class)
        ->fillForm([
            'preset_label' => 'Bar soap — IFRA category 9 — amendment 51',
            'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
            'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
            'sensitization_assessment_factor' => '300',
            'consumer_exposure_level_mg_cm2_day' => '0.2',
            'qra2_factor' => '0.5',
            'source_reference' => 'IFRA 51st Amendment — Guidance for the use of IFRA Standards, Table 6, p. 28',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Qra2Parameter::query()->sole())
        ->version->toBe(1)
        ->applies_qra2_factor->toBeTrue();
});

it('asks for a reason when the adjustment factor is switched off', function (): void {
    Livewire::test(CreateQra2Parameter::class)
        ->fillForm([
            'preset_label' => 'Bar soap — IFRA category 9 — amendment 51',
            'ifra_product_category_id' => IfraProductCategory::factory()->create()->id,
            'ifra_amendment_id' => IfraAmendment::factory()->create()->id,
            'sensitization_assessment_factor' => '300',
            'consumer_exposure_level_mg_cm2_day' => '0.2',
            'qra2_factor' => '0.5',
            'applies_qra2_factor' => false,
            'factor_deviation_reason' => '',
            'source_reference' => 'IFRA 51st guidance',
        ])
        ->call('create')
        ->assertHasFormErrors(['factor_deviation_reason']);

    expect(Qra2Parameter::query()->count())->toBe(0);
});

it('shows range errors on the policy form', function (): void {
    Livewire::test(CreateAssessmentPolicy::class)
        ->fillForm([
            'default_dermal_absorption_percent' => '101',
            'default_oral_bioavailability_percent' => '-1',
            'mos_threshold' => '100',
            'body_weight_kg' => '0',
            'source_reference' => 'SCCS Notes of Guidance',
        ])
        ->call('create')
        ->assertHasFormErrors(['default_dermal_absorption_percent', 'default_oral_bioavailability_percent', 'body_weight_kg']);

    expect(AssessmentPolicy::query()->count())->toBe(0);
});
