<?php

use App\Actions\IngredientIntake\ResolveIngredientIntakeDuplicate;
use App\Actions\IngredientIntake\UpdateIngredientIntakeRow;
use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use App\Services\IngredientIntake\IngredientDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('pauses only exact duplicate rows and does not choose a resolution automatically', function (): void {
    $existing = Ingredient::factory()->create([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'Cocos Nucifera Oil',
    ]);
    $batch = IngredientIntakeBatch::factory()->create();
    $exact = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'row_number' => 2,
        'original_current_name' => ' coconut oil ',
        'normalized_current_name' => 'coconut oil',
    ]);
    $new = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'row_number' => 3,
        'original_current_name' => 'Baobab oil',
        'normalized_current_name' => 'baobab oil',
    ]);

    $detector = app(IngredientDuplicateDetector::class);
    $exactResult = $detector->refresh($exact);
    $newResult = $detector->refresh($new);

    expect($exactResult->status)->toBe(IngredientIntakeItemStatus::NeedsResolution)
        ->and($exactResult->duplicate_resolution)->toBeNull()
        ->and($exactResult->duplicate_candidates)->toHaveCount(1)
        ->and($exactResult->duplicate_candidates[0]['match_type'])->toBe('exact')
        ->and($exactResult->duplicate_candidates[0]['ingredient_id'])->toBe($existing->id)
        ->and($newResult->status)->toBe(IngredientIntakeItemStatus::Draft);
});

it('matches exact aliases and identifiers without requiring the supplied field to be inci', function (): void {
    $existing = Ingredient::factory()->create([
        'display_name' => 'Argan Kernel Oil',
        'inci_name' => 'Argania Spinosa Kernel Oil',
    ]);
    IngredientAlias::factory()->create([
        'ingredient_id' => $existing->id,
        'name' => 'Argan oil',
        'normalized_name' => 'argan oil',
    ]);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_current_name' => 'Argan oil',
        'normalized_current_name' => 'argan oil',
    ]);

    $result = app(IngredientDuplicateDetector::class)->detect($item);

    expect($result['exact'])->toHaveCount(1)
        ->and($result['exact'][0]['matched_field'])->toBe('alias')
        ->and($result['exact'][0]['ingredient_id'])->toBe($existing->id);
});

it('stores a possible match as a non-blocking warning', function (): void {
    $existing = Ingredient::factory()->create([
        'display_name' => 'Coconut Kernel Oil',
        'inci_name' => 'Cocos Nucifera Kernel Oil',
    ]);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_current_name' => 'Coconut Oil',
        'normalized_current_name' => 'coconut oil',
    ]);

    $result = app(IngredientDuplicateDetector::class)->refresh($item);

    expect($result->status)->toBe(IngredientIntakeItemStatus::Draft)
        ->and($result->duplicate_candidates)->toHaveCount(1)
        ->and($result->duplicate_candidates[0]['match_type'])->toBe('possible')
        ->and($result->duplicate_candidates[0]['ingredient_id'])->toBe($existing->id)
        ->and($result->duplicate_candidates[0]['score'])->toBeGreaterThan(0);
});

it('flags exact duplicates within the same intake batch', function (): void {
    $batch = IngredientIntakeBatch::factory()->create();
    $first = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'row_number' => 2,
        'normalized_current_name' => 'red pigment',
    ]);
    $second = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'row_number' => 3,
        'normalized_current_name' => 'red pigment',
    ]);

    $result = app(IngredientDuplicateDetector::class)->refresh($first);

    expect($result->status)->toBe(IngredientIntakeItemStatus::NeedsResolution)
        ->and($result->duplicate_candidates[0]['candidate_type'])->toBe('intake')
        ->and($result->duplicate_candidates[0]['intake_item_id'])->toBe($second->id);
});

it('does not treat identity-bearing parenthetical, plant-part, salt, extraction, or colour-index text as exact', function (
    string $existingInci,
    string $submittedInci,
): void {
    $existing = Ingredient::factory()->create(['inci_name' => $existingInci]);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'original_inci_name' => $submittedInci,
        'normalized_inci_name' => mb_strtolower($submittedInci),
    ]);

    $result = app(IngredientDuplicateDetector::class)->detect($item);

    expect($result['exact'])->toBeEmpty();
})->with([
    ['Cocos Nucifera (Coconut) Oil', 'Cocos Nucifera Oil'],
    ['Prunus Armeniaca Kernel Oil', 'Prunus Armeniaca Oil'],
    ['Sodium Cocoate', 'Cocos Nucifera Oil'],
    ['Cocos Nucifera Oil Extract', 'Cocos Nucifera Oil'],
    ['CI 77491', 'CI 77492'],
]);

it('resolves an exact duplicate to an existing ingredient or confirms a distinct material', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = Ingredient::factory()->create(['display_name' => 'Coconut Oil']);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'normalized_current_name' => 'coconut oil',
        'duplicate_candidates' => [[
            'candidate_type' => 'ingredient',
            'ingredient_id' => $existing->id,
            'match_type' => 'exact',
        ]],
        'status' => IngredientIntakeItemStatus::NeedsResolution,
    ]);

    $resolved = app(ResolveIngredientIntakeDuplicate::class)->handle(
        $admin,
        $item,
        IngredientDuplicateResolution::ExistingIngredient,
        $existing,
    );

    expect($resolved->existing_ingredient_id)->toBe($existing->id)
        ->and($resolved->duplicate_resolution)->toBe(IngredientDuplicateResolution::ExistingIngredient)
        ->and($resolved->status)->toBe(IngredientIntakeItemStatus::Draft);

    $distinct = app(ResolveIngredientIntakeDuplicate::class)->handle(
        $admin,
        $resolved,
        IngredientDuplicateResolution::DistinctMaterial,
    );

    expect($distinct->existing_ingredient_id)->toBeNull()
        ->and($distinct->duplicate_resolution)->toBe(IngredientDuplicateResolution::DistinctMaterial);
});

it('invalidates old research when a duplicate resolution changes', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = Ingredient::factory()->create(['display_name' => 'Coconut Oil']);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'normalized_current_name' => 'coconut oil',
        'duplicate_candidates' => [[
            'candidate_type' => 'ingredient',
            'ingredient_id' => $existing->id,
            'match_type' => 'exact',
        ]],
        'status' => IngredientIntakeItemStatus::Ready,
    ]);
    $enrichmentBatch = IngredientEnrichmentBatch::factory()->create();
    $enrichmentItem = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $enrichmentBatch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $item->id,
        'catalog_key' => null,
        'snapshot' => ['subject_type' => 'intake', 'subject_public_id' => $item->public_id],
        'result' => ['proposal' => ['display_name' => 'Old result']],
        'validation_report' => ['valid' => true],
        'plan' => ['changed' => true],
        'research_stages' => ['eu_structured' => ['status' => 'completed']],
        'status' => IngredientEnrichmentItemStatus::Ready,
        'approved_by_user_id' => $admin->id,
        'approved_at' => now(),
    ]);
    $oldFingerprint = $enrichmentItem->source_fingerprint;

    $updated = app(ResolveIngredientIntakeDuplicate::class)->handle(
        $admin,
        $item,
        IngredientDuplicateResolution::DistinctMaterial,
    );

    $enrichmentItem->refresh();

    expect($updated->duplicate_resolution)->toBe(IngredientDuplicateResolution::DistinctMaterial)
        ->and($enrichmentItem->status)->toBe(IngredientEnrichmentItemStatus::Stale)
        ->and($enrichmentItem->result)->toBeNull()
        ->and($enrichmentItem->plan)->toBeNull()
        ->and($enrichmentItem->research_stages)->toBe([])
        ->and($enrichmentItem->approved_by_user_id)->toBeNull()
        ->and($enrichmentItem->approved_at)->toBeNull()
        ->and($enrichmentItem->source_fingerprint)->not->toBe($oldFingerprint)
        ->and($enrichmentItem->source_fingerprint)->toBe(
            app(IngredientEnrichmentSubjectBuilder::class)
                ->forIntake($updated->load(['batch', 'existingIngredient']))
                ->fingerprint,
        );
});

it('rejects linking an ingredient that is not a detected candidate', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientIntakeItemStatus::NeedsResolution,
        'duplicate_candidates' => [],
    ]);

    expect(fn () => app(ResolveIngredientIntakeDuplicate::class)->handle(
        $admin,
        $item,
        IngredientDuplicateResolution::ExistingIngredient,
        Ingredient::factory()->create(),
    ))->toThrow(ValidationException::class);
});

it('re-evaluates duplicate candidates after a reviewer edits a row', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = Ingredient::factory()->create(['display_name' => 'Coconut Oil']);
    $batch = IngredientIntakeBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'normalized_current_name' => 'unknown oil',
    ]);

    $updated = app(UpdateIngredientIntakeRow::class)->handle(
        $admin,
        $item,
        'Coconut Oil',
        null,
    );

    expect($updated->status)->toBe(IngredientIntakeItemStatus::NeedsResolution)
        ->and($updated->duplicate_candidates[0]['ingredient_id'])->toBe($existing->id)
        ->and($updated->duplicate_resolution)->toBeNull();
});
