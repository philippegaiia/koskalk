<?php

use App\Enums\AssessmentReviewState;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\CreateMaterialAssessmentCoverage;
use App\Filament\Resources\MaterialAssessmentCoverages\Pages\EditMaterialAssessmentCoverage;
use App\Models\Ingredient;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('records platform coverage through the coverage service', function (): void {
    $admin = User::factory()->admin()->create();
    $olive = Ingredient::factory()->create();
    $profiled = Substance::factory()->create();
    ToxicologyProfile::factory()->for($profiled)->create();
    $this->actingAs($admin);

    Livewire::test(CreateMaterialAssessmentCoverage::class)
        ->fillForm([
            'coverable_type' => Ingredient::class,
            'coverable_id' => $olive->id,
            'review_state' => 'sufficient',
            'covers_whole_material' => true,
            'covers_constituents' => false,
            'supporting_substance_ids' => [$profiled->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coverage = MaterialAssessmentCoverage::query()->sole();

    expect($coverage->review_state)->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->reviewed_by_user_id)->toBe($admin->id);
});

it('surfaces the service validation on the form', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $coverage = MaterialAssessmentCoverage::factory()->create();

    Livewire::test(EditMaterialAssessmentCoverage::class, ['record' => $coverage->getKey()])
        ->fillForm(['review_state' => 'insufficient', 'insufficient_reason' => ''])
        ->call('save')
        ->assertHasFormErrors(['insufficient_reason']);
});
