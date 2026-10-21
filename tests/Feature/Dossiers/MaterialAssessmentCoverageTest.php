<?php

use App\Enums\AssessmentReviewState;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Models\Ingredient;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use App\Services\Dossiers\ToxicologyProfilePublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function profiledSubstance(string $name): Substance
{
    $substance = Substance::factory()->create(['name' => $name, 'entity_type' => 'whole_ingredient']);
    ToxicologyProfile::factory()->for($substance)->create();

    return $substance;
}

function privateIngredientFor(Workspace $workspace): Ingredient
{
    return Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);
}

it('treats a material without coverage as not reviewed', function (): void {
    expect(app(MaterialAssessmentCoverageService::class)->stateFor(Ingredient::factory()->create()))
        ->toBe(AssessmentReviewState::NotReviewed);
});

it('accepts whole-material coverage for an ingredient with an empty constituent list', function (): void {
    $olive = Ingredient::factory()->create(['inci_name' => 'OLEA EUROPAEA FRUIT OIL']);
    $profiled = profiledSubstance('Olea europaea fruit oil');

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $olive, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => false,
        'supporting_substance_ids' => [$profiled->id],
    ]);

    expect($olive->substanceEntries()->count())->toBe(0)
        ->and($coverage->review_state)->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->workspace_id)->toBeNull()
        ->and($coverage->supportingSubstances->pluck('id')->all())->toBe([$profiled->id]);
});

it('accepts whole-material plus constituent coverage when every constituent is referenced', function (): void {
    $rosemary = Ingredient::factory()->create();
    $cineole = profiledSubstance('1,8-Cineole');
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $rosemary->id, 'substance_id' => $cineole->id]);

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $rosemary, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => true,
        'supporting_substance_ids' => [profiledSubstance('Rosmarinus officinalis leaf oil')->id, $cineole->id],
    ]);

    expect($coverage->covers_whole_material)->toBeTrue()
        ->and($coverage->covers_constituents)->toBeTrue();
});

it('refuses constituent coverage without evidence for each tracked constituent', function (array $supporting, string $errorKey): void {
    $ingredient = Ingredient::factory()->create();
    $tracked = profiledSubstance('Limonene');
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $ingredient->id, 'substance_id' => $tracked->id]);
    $other = profiledSubstance('Linalool');

    try {
        app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $ingredient, [
            'review_state' => 'sufficient',
            'covers_constituents' => true,
            'supporting_substance_ids' => array_map(fn (string $key): int => ['other' => $other->id, 'tracked_unprofiled' => Substance::factory()->create()->id][$key], $supporting),
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'no references at all' => [[], 'supporting_substance_ids'],
    'references that miss the tracked constituent' => [['other'], 'covers_constituents'],
    'an unprofiled reference' => [['tracked_unprofiled'], 'supporting_substance_ids'],
]);

it('records the profile version reviewed and detects when it is superseded', function (): void {
    $admin = User::factory()->admin()->create();
    $olive = Ingredient::factory()->create();
    $profiled = profiledSubstance('Olea europaea fruit oil');
    $reviewedProfile = $profiled->currentToxicologyProfile()->sole();

    $coverage = app(MaterialAssessmentCoverageService::class)->review($admin, $olive, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'supporting_substance_ids' => [$profiled->id],
    ]);

    expect($coverage->supportingProfiles->pluck('id')->all())->toBe([$reviewedProfile->id])
        ->and($coverage->reliesOnSupersededProfiles())->toBeFalse();

    app(ToxicologyProfilePublisher::class)->publish($profiled, [
        'noael_mg_kg_bw_day' => '200',
        'noael_route' => 'oral',
        'references' => [['number' => 1, 'citation' => 'Newer study', 'url' => null]],
        'value_references' => ['noael_mg_kg_bw_day' => '1'],
    ], $admin);

    expect($coverage->refresh()->supportingProfiles->pluck('id')->all())->toBe([$reviewedProfile->id])
        ->and($coverage->reliesOnSupersededProfiles())->toBeTrue();
});

it('covers a final soap material such as sodium olivate', function (): void {
    $sodiumOlivate = Substance::factory()->create(['name' => 'Sodium olivate']);

    $coverage = app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $sodiumOlivate, [
        'review_state' => 'sufficient',
        'covers_whole_material' => true,
        'covers_constituents' => false,
        'supporting_substance_ids' => [profiledSubstance('Sodium olivate (profile)')->id],
    ]);

    expect(app(MaterialAssessmentCoverageService::class)->stateFor($sodiumOlivate))->toBe(AssessmentReviewState::Sufficient)
        ->and($coverage->coverable->is($sodiumOlivate))->toBeTrue();
});

it('rejects inconsistent coverage', function (array $review, bool $citeUnprofiledSubstance, string $errorKey): void {
    $ingredient = Ingredient::factory()->create();
    $supporting = $citeUnprofiledSubstance ? [Substance::factory()->create()->id] : [];

    try {
        app(MaterialAssessmentCoverageService::class)->review(User::factory()->admin()->create(), $ingredient, [
            'review_state' => 'sufficient',
            'covers_whole_material' => false,
            'covers_constituents' => false,
            'supporting_substance_ids' => $supporting,
            ...$review,
        ]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
    }
})->with([
    'sufficient without any coverage' => [[], false, 'covers_whole_material'],
    'whole material without a profiled reference' => [['covers_whole_material' => true], true, 'supporting_substance_ids'],
    'constituents when none are tracked' => [['covers_constituents' => true], false, 'covers_constituents'],
    'insufficient without a reason' => [['review_state' => 'insufficient'], false, 'insufficient_reason'],
]);

it('lets owners of a granted workspace review their private ingredient but not platform materials', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceDossierGrant::factory()->for($workspace)->create();
    $private = privateIngredientFor($workspace);
    $service = app(MaterialAssessmentCoverageService::class);

    $coverage = $service->review($owner, $private, [
        'review_state' => 'insufficient',
        'insufficient_reason' => 'No NESIL for citral in this blend.',
    ]);

    expect($coverage->workspace_id)->toBe($workspace->id);

    expect(fn () => $service->review($owner, Ingredient::factory()->create(), ['review_state' => 'not_reviewed']))
        ->toThrow(AuthorizationException::class);
});

it('refuses private-ingredient review without the module grant', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    app(MaterialAssessmentCoverageService::class)->review($owner, privateIngredientFor($workspace), ['review_state' => 'not_reviewed']);
})->throws(AuthorizationException::class);
