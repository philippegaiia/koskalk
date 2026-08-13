<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Actions\IngredientEnrichment\CancelIngredientEnrichmentBatch;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps approval write free then explicitly applies the approved proposal', function (): void {
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'review_oil',
        'category' => IngredientCategory::Other,
        'display_name' => 'Review Oil',
        'inci_name' => null,
        'info_markdown' => null,
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = reviewResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create(['status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'total_count' => 1]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['source_fingerprint' => $fingerprint],
        'source_fingerprint' => $fingerprint,
        'result' => $result,
        'plan' => app(IngredientEnrichmentPlanner::class)->plan($ingredient, $result),
    ]);

    $approved = app(ApproveIngredientEnrichmentItem::class)->handle($admin, $item);

    expect($approved->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and($approved->approved_by_user_id)->toBe($admin->id)
        ->and($ingredient->fresh()->inci_name)->toBeNull();

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch);

    expect($totals)->toBe(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($ingredient->fresh()->inci_name)->toBe('REVIEW OIL')
        ->and($approved->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($approved->fresh()->applied_by_user_id)->toBe($admin->id);
});

it('cancels only pending items and preserves completed proposals', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $batch = IngredientEnrichmentBatch::factory()->create(['status' => IngredientEnrichmentBatchStatus::Processing, 'laravel_batch_id' => null]);
    $pending = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create(['status' => IngredientEnrichmentItemStatus::Pending]);
    $ready = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create(['status' => IngredientEnrichmentItemStatus::Ready]);

    app(CancelIngredientEnrichmentBatch::class)->handle($admin, $batch);

    expect($pending->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Cancelled)
        ->and($ready->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Cancelled);
});

/** @return array<string, mixed> */
function reviewResult(Ingredient $ingredient, string $fingerprint): array
{
    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result', 'schema_version' => 1,
        'catalog_key' => $ingredient->catalog_key, 'source_fingerprint' => $fingerprint,
        'proposal' => [
            'display_name' => 'Review Oil', 'inci_name' => 'REVIEW OIL', 'category' => 'other', 'subcategory' => null,
            'saponification_name' => null,
            'info_markdown' => "## Overview\nA useful cosmetic ingredient for simple products.\n\n## Formulation use\nUsed as an emollient ingredient in cosmetic formulations.",
            'soapmaking_relevant' => false, 'identifiers' => [], 'cosing_functions' => [],
            'translations' => collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])->map(fn (string $locale): array => [
                'locale' => $locale, 'display_name' => "Review Oil {$locale}", 'saponification_name' => null,
                'info_markdown' => "## Overview\nA translated useful cosmetic ingredient.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.",
            ])->all(),
            'market_labels' => [],
        ],
        'evidence' => [[
            'field' => 'proposal.inci_name', 'source_name' => 'European Commission CosIng',
            'source_url' => 'https://ec.europa.eu/growth/tools-databases/cosing/', 'checked_at' => now()->toDateString(),
        ]],
        'confidence' => 'high', 'warnings' => [], 'unresolved_questions' => [],
    ];
}
