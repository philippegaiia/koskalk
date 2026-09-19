<?php

use App\Actions\IngredientEnrichment\RetryIngredientEnrichmentFailures;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceContextBuilder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('retries an unresolved warning from its stage boundary and enables web gap research only when requested', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'argan_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Warning,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'identity_preparation' => ['status' => 'completed'],
            'eu_structured' => ['status' => 'completed'],
            'eu_official' => ['status' => 'completed'],
            'us_identity' => ['status' => 'completed'],
            'us_declaration' => [
                'status' => 'completed',
                'unresolved_questions' => ['An exact FDA name is needed.'],
            ],
            'conflict_evaluation' => ['status' => 'completed'],
            'ai_editorial' => ['status' => 'completed'],
            'validation' => ['status' => 'completed'],
        ],
    ]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch, allowGapResearch: true);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and(array_keys($item->fresh()->research_stages))->toBe([
            'identity_preparation',
            'us_identity',
            'eu_structured',
            'eu_official',
        ]);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment && $job->allowGapResearch,
        );
    });
});

it('restarts a post-pipeline validation failure from the structured source boundary', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'babassu_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $completedStages = collect(IngredientEnrichmentResearchStage::ordered())
        ->mapWithKeys(fn (IngredientEnrichmentResearchStage $stage): array => [
            $stage->value => ['stage' => $stage->value, 'status' => 'completed'],
        ])
        ->all();
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Failed,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'failure_code' => 'ValidationException',
        'research_stages' => $completedStages,
    ]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and(array_keys($item->fresh()->research_stages))->toBe(['identity_preparation', 'us_identity']);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        );
    });
});

it('retries guidance failures with the guidance job without reopening identity research', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'olive_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Failed,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'ai_guidance_authoring' => ['status' => 'failed'],
        ],
    ]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending);
    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh
                && ! property_exists($job, 'localizationOnly'),
        ) && collect($pending->jobs)->doesntContain(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        );
    });
});

it('routes retry jobs from the persisted batch mode instead of a stale model', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'olive_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Failed,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'identity_preparation' => ['status' => 'failed'],
        ],
    ]);
    $staleBatch = $batch->fresh();
    $batch->update(['mode' => IngredientEnrichmentBatchMode::FillMissing]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $staleBatch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending);
    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        ) && collect($pending->jobs)->doesntContain(
            fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh,
        );
    });
});

it('routes direct dispatch from the persisted batch mode instead of a stale model', function (): void {
    Bus::fake();
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'olive_oil']);
    $snapshot = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::Pending,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Pending,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
    ]);
    $staleBatch = $batch->fresh();
    $batch->update(['mode' => IngredientEnrichmentBatchMode::FillMissing]);

    app(IngredientEnrichmentBatchService::class)->dispatch($staleBatch);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        ) && collect($pending->jobs)->doesntContain(
            fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh,
        );
    });
});

it('uses nested guidance unresolved questions as the guidance retry boundary', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'olive_oil']);
    $snapshot = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Warning,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'ai_guidance_research' => ['status' => 'completed'],
            'ai_guidance_authoring' => [
                'stage' => 'ai_guidance_authoring',
                'status' => 'completed',
                'data' => [
                    'guidance' => [
                        'info_markdown' => 'Persisted guidance.',
                        'warnings' => [],
                        'unresolved_questions' => ['Confirm the exact material grade.'],
                    ],
                ],
            ],
            'ai_guidance_localization' => ['status' => 'completed'],
            'validation' => ['status' => 'completed'],
        ],
    ]);

    expect($item->retryableFromStage(IngredientEnrichmentBatchMode::GuidanceRefresh))
        ->toBe(IngredientEnrichmentResearchStage::AiGuidanceAuthoring)
        ->and($item->retryableFromStage())
        ->toBe(IngredientEnrichmentResearchStage::AiGuidanceAuthoring);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and(array_keys($item->fresh()->research_stages))->toBe(['ai_guidance_research']);
    Bus::assertBatched(fn (PendingBatch $pending): bool => collect($pending->jobs)
        ->contains(fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh));
});

it('uses nested validation result unresolved questions as the guidance retry boundary', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'olive_oil']);
    $snapshot = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Warning,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'ai_guidance_research' => ['status' => 'completed'],
            'ai_guidance_authoring' => ['status' => 'completed'],
            'ai_guidance_localization' => ['status' => 'completed'],
            'validation' => [
                'stage' => 'validation',
                'status' => 'completed',
                'data' => [
                    'result' => [
                        'unresolved_questions' => ['Confirm the exact material grade.'],
                    ],
                ],
            ],
        ],
    ]);

    expect($item->retryableFromStage(IngredientEnrichmentBatchMode::GuidanceRefresh))
        ->toBe(IngredientEnrichmentResearchStage::Validation)
        ->and($item->retryableFromStage())
        ->toBe(IngredientEnrichmentResearchStage::Validation);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and(array_keys($item->fresh()->research_stages))->toBe([
            'ai_guidance_research',
            'ai_guidance_authoring',
            'ai_guidance_localization',
        ]);
    Bus::assertBatched(fn (PendingBatch $pending): bool => collect($pending->jobs)
        ->contains(fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh));
});

it('defines guidance stage order from the persisted batch mode', function (): void {
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    expect(IngredientEnrichmentBatchMode::GuidanceRefresh->guidanceStages())->toBe([
        IngredientEnrichmentResearchStage::AiGuidanceResearch,
        IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::GuidanceLocalization->guidanceStages())->toBe([
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::FillMissing->guidanceStages())->toBe([]);
});

it('retries a stale item from the first stage against a refreshed snapshot', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'stale_retry_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $completedStages = collect(IngredientEnrichmentResearchStage::ordered())
        ->mapWithKeys(fn (IngredientEnrichmentResearchStage $stage): array => [
            $stage->value => ['stage' => $stage->value, 'status' => 'completed'],
        ])
        ->all();
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Stale,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'failure_message' => 'The ingredient changed after research.',
        'research_stages' => $completedStages,
    ]);
    $ingredient->update(['display_name' => 'Renamed After Research']);
    $currentFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient->fresh());

    expect($currentFingerprint)->not->toBe($snapshot['source_fingerprint']);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and($item->fresh()->source_fingerprint)->toBe($currentFingerprint)
        ->and($item->fresh()->failure_message)->toBeNull()
        ->and($item->fresh()->research_stages)->toBe([]);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        );
    });
});

it('retries a stale guidance item without losing its guidance context', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'stale_guidance_oil']);
    $snapshot = app(IngredientGuidanceContextBuilder::class)->build($ingredient, freshResearch: true);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
        'fresh_research' => true,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Stale,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'research_stages' => [
            'ai_guidance_research' => ['status' => 'completed'],
            'ai_guidance_authoring' => ['status' => 'completed'],
            'validation' => ['status' => 'completed'],
        ],
    ]);
    $ingredient->update(['info_markdown' => "## Overview\n\nHand edited guidance body."]);
    $currentFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient->fresh());

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and($item->fresh()->source_fingerprint)->toBe($currentFingerprint)
        ->and($item->fresh()->snapshot)->toHaveKey('guidance_evidence')
        ->and($item->fresh()->snapshot['fresh_research'])->toBeTrue()
        ->and($item->fresh()->snapshot['subject_public_id'])->toBe((string) $ingredient->public_id)
        ->and($item->fresh()->research_stages)->toBe([]);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh,
        ) && collect($pending->jobs)->doesntContain(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        );
    });
});

it('retries a stale intake row against its refreshed subject', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $intakeItem = IngredientIntakeItem::factory()->create([
        'original_current_name' => 'Stale intake oil',
        'normalized_current_name' => 'stale intake oil',
    ]);
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($intakeItem->fresh());
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->buildForSubject($subject);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::Intake,
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $intakeItem->id,
        'catalog_key' => null,
        'status' => IngredientEnrichmentItemStatus::Stale,
        'snapshot' => $snapshot,
        'source_fingerprint' => $subject->fingerprint,
        'research_stages' => collect(IngredientEnrichmentResearchStage::ordered())
            ->mapWithKeys(fn (IngredientEnrichmentResearchStage $stage): array => [
                $stage->value => ['stage' => $stage->value, 'status' => 'completed'],
            ])
            ->all(),
    ]);
    $intakeItem->update(['normalized_current_name' => 'renamed intake oil']);
    $currentFingerprint = app(IngredientEnrichmentSubjectBuilder::class)
        ->forIntake($intakeItem->fresh())
        ->fingerprint;

    expect($currentFingerprint)->not->toBe($subject->fingerprint);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and($item->fresh()->source_fingerprint)->toBe($currentFingerprint)
        ->and($item->fresh()->snapshot['subject_public_id'])->toBe((string) $intakeItem->public_id)
        ->and($item->fresh()->research_stages)->toBe([]);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        );
    });
});

it('leaves an apply rejection unretried because re-researching cannot repair it', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'claimed_retry_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Failed,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'failure_code' => IngredientEnrichmentBatchItem::APPLY_REJECTED,
        'failure_message' => 'Only platform ingredients can be enriched.',
        'research_stages' => [
            'identity_preparation' => ['status' => 'completed'],
            'eu_structured' => ['status' => 'completed'],
        ],
    ]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Failed)
        ->and($item->fresh()->failure_code)->toBe(IngredientEnrichmentBatchItem::APPLY_REJECTED)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::PartiallyFailed);

    Bus::assertNothingBatched();
});

it('reopens identity research when retrying an identity unresolved item', function (): void {
    Bus::fake();
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'marula_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
    ]);
    $completedIdentity = collect([
        IngredientEnrichmentResearchStage::IdentityPreparation,
        IngredientEnrichmentResearchStage::UsIdentity,
        IngredientEnrichmentResearchStage::EuStructured,
        IngredientEnrichmentResearchStage::EuOfficial,
        IngredientEnrichmentResearchStage::UsDeclaration,
        IngredientEnrichmentResearchStage::ConflictEvaluation,
    ])->mapWithKeys(fn (IngredientEnrichmentResearchStage $stage): array => [
        $stage->value => ['stage' => $stage->value, 'status' => 'completed'],
    ])->all();
    $skippedGuidance = collect([
        IngredientEnrichmentResearchStage::AiGuidanceResearch,
        IngredientEnrichmentResearchStage::AiEditorial,
        IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->mapWithKeys(fn (IngredientEnrichmentResearchStage $stage): array => [
        $stage->value => [
            'stage' => $stage->value,
            'status' => 'skipped',
            'data' => ['reason' => 'identity_unresolved'],
        ],
    ])->all();
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Failed,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
        'failure_code' => 'identity_unresolved',
        'research_stages' => [...$completedIdentity, ...$skippedGuidance],
    ]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and($item->fresh()->failure_code)->toBeNull()
        ->and($item->fresh()->research_stages)->toBe([]);

    Bus::assertBatched(function (PendingBatch $pending): bool {
        return collect($pending->jobs)->contains(
            fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment,
        ) && collect($pending->jobs)->doesntContain(
            fn (mixed $job): bool => $job instanceof GenerateIngredientGuidanceRefresh,
        );
    });
});
