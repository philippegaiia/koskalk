<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Actions\IngredientEnrichment\EditIngredientEnrichmentProposal;
use App\Actions\IngredientEnrichment\RetryIngredientEnrichmentFailures;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceProposalReviewService;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshProcessor;
use App\Services\IngredientEnrichment\ResearchIngredientEnrichmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('records a claimed subject as a non retryable failure', function (string $operation): void {
    Http::preventStrayRequests();
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create();
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => str_starts_with($operation, 'guidance') ? IngredientEnrichmentBatchMode::GuidanceRefresh : IngredientEnrichmentBatchMode::FillMissing,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => $operation === 'guidance_apply'
            ? IngredientEnrichmentItemStatus::Approved
            : (in_array($operation, ['approve', 'edit', 'guidance_approve', 'guidance_edit'], true) ? IngredientEnrichmentItemStatus::Ready : IngredientEnrichmentItemStatus::Pending),
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
    ]);
    $ingredient->update(['owner_type' => 'workspace', 'owner_id' => $admin->current_workspace_id]);

    if ($operation === 'research') {
        app(ResearchIngredientEnrichmentItem::class)->handle($item->id);
    } elseif ($operation === 'guidance') {
        app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);
    } elseif ($operation === 'guidance_apply') {
        expect(app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch))
            ->toBe(['applied' => 0, 'unchanged' => 0, 'stale' => 0, 'failed' => 1]);
    } else {
        expect(fn () => match ($operation) {
            'approve' => app(ApproveIngredientEnrichmentItem::class)->handle($admin, $item),
            'edit' => app(EditIngredientEnrichmentProposal::class)->handle($admin, $item, []),
            'guidance_approve' => app(IngredientGuidanceProposalReviewService::class)->approve($admin, $item),
            'guidance_edit' => app(IngredientGuidanceProposalReviewService::class)->edit($admin, $item, []),
        })
            ->toThrow(ValidationException::class, __('ingredient_enrichment.validation.platform_only_apply'));
    }

    expect($item->fresh())->status->toBe(IngredientEnrichmentItemStatus::Failed)
        ->failure_code->toBe('subject_unavailable')
        ->failure_message->toBe(__('ingredient_enrichment.validation.platform_only_apply'));
    expect($item->fresh()->retryableFromStage())->toBeNull();

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    Bus::assertNothingBatched();
})->with(['research', 'guidance', 'approve', 'edit', 'guidance_approve', 'guidance_edit', 'guidance_apply']);

it('does not dispatch a claimed subject from a legacy retryable status', function (IngredientEnrichmentItemStatus $status): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create();
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create(['status' => IngredientEnrichmentBatchStatus::ReadyForReview]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => $status,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
    ]);
    $ingredient->update(['owner_type' => 'workspace', 'owner_id' => $admin->current_workspace_id]);

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh())->status->toBe(IngredientEnrichmentItemStatus::Failed)
        ->failure_code->toBe('subject_unavailable');
    expect($batch->fresh())->status->toBe(IngredientEnrichmentBatchStatus::PartiallyFailed)->failed_count->toBe(1);
    Bus::assertNothingBatched();
})->with([IngredientEnrichmentItemStatus::Stale, IngredientEnrichmentItemStatus::Failed, IngredientEnrichmentItemStatus::Warning]);
