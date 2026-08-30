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
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
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
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::GuidanceLocalization->guidanceStages())->toBe([
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::FillMissing->guidanceStages())->toBe([]);
});
