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
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
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

it('defines guidance stage order from the persisted batch mode', function (): void {
    expect(IngredientEnrichmentBatchMode::GuidanceRefresh->guidanceStages())->toBe([
        IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::GuidanceLocalization->guidanceStages())->toBe([
        IngredientEnrichmentResearchStage::AiGuidanceLocalization,
        IngredientEnrichmentResearchStage::Validation,
    ])->and(IngredientEnrichmentBatchMode::FillMissing->guidanceStages())->toBe([]);
});
