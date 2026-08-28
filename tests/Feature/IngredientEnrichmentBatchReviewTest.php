<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientEnrichmentItem;
use App\Actions\IngredientEnrichment\CancelIngredientEnrichmentBatch;
use App\Actions\IngredientEnrichment\RejectIngredientEnrichmentItem;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\SupportedLocale;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentBatchService;
use App\Services\IngredientEnrichment\IngredientEnrichmentPlanner;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps approval write free then explicitly applies the approved proposal', function (): void {
    config()->set('ingredient-enrichment.guidance.minimum_words', 1);

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
    $result['proposal']['soap_inci_naoh_name'] = 'SODIUM REVIEWATE';
    $result['proposal']['soap_inci_koh_name'] = 'POTASSIUM REVIEWATE';
    foreach (['soap_inci_naoh_name', 'soap_inci_koh_name'] as $field) {
        $result['field_confidence'][] = ['field' => "proposal.{$field}", 'confidence' => 'verified'];
        $result['evidence'][] = [
            'field' => "proposal.{$field}",
            'source_name' => 'EUR-Lex',
            ...reviewEvidenceSource('verified', 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175', '32025D1175'),
        ];
    }
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
        ->and($ingredient->fresh()->inci_name)->toBe('Review oil')
        ->and($ingredient->fresh()->soap_inci_naoh_name)->toBe('Sodium reviewate')
        ->and($ingredient->fresh()->soap_inci_koh_name)->toBe('Potassium reviewate')
        ->and($approved->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($approved->fresh()->applied_by_user_id)->toBe($admin->id)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
});

it('lets the reviewer approve unresolved proposals individually', function (): void {
    config()->set('ingredient-enrichment.guidance.minimum_words', 1);

    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }

    $admin = User::factory()->create(['is_admin' => true]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'total_count' => 2,
    ]);

    $safeIngredient = Ingredient::factory()->create([
        'catalog_key' => 'safe_review_oil',
        'category' => IngredientCategory::Other,
    ]);
    $safeFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($safeIngredient);
    $safeResult = reviewResult($safeIngredient, $safeFingerprint);
    $safeItem = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $safeIngredient->id,
        'catalog_key' => $safeIngredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['source_fingerprint' => $safeFingerprint],
        'source_fingerprint' => $safeFingerprint,
        'result' => $safeResult,
        'warnings' => [],
        'unresolved_questions' => [],
    ]);

    $unresolvedIngredient = Ingredient::factory()->create([
        'catalog_key' => 'unresolved_review_oil',
        'category' => IngredientCategory::Other,
    ]);
    $unresolvedFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($unresolvedIngredient);
    $unresolvedResult = reviewResult($unresolvedIngredient, $unresolvedFingerprint);
    $unresolvedResult['field_confidence'][0]['confidence'] = 'unresolved';
    $unresolvedItem = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $unresolvedIngredient->id,
        'catalog_key' => $unresolvedIngredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['source_fingerprint' => $unresolvedFingerprint],
        'source_fingerprint' => $unresolvedFingerprint,
        'result' => $unresolvedResult,
        'warnings' => [],
        'unresolved_questions' => [],
    ]);

    $safeApproved = app(ApproveIngredientEnrichmentItem::class)->handle($admin, $safeItem);
    $manuallyApproved = app(ApproveIngredientEnrichmentItem::class)->handle($admin, $unresolvedItem);

    expect($safeApproved->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and($safeApproved->approved_by_user_id)->toBe($admin->id)
        ->and($manuallyApproved->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and($manuallyApproved->approved_by_user_id)->toBe($admin->id);
});

it('rejects a proposal with reviewer audit and does not mutate the ingredient', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'rejected_review_oil',
        'category' => IngredientCategory::Other,
        'display_name' => 'Rejected Review Oil',
    ]);
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = reviewResult($ingredient, $fingerprint);
    $batch = IngredientEnrichmentBatch::factory()->create(['status' => IngredientEnrichmentBatchStatus::ReadyForReview]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Ready,
        'snapshot' => ['source_fingerprint' => $fingerprint],
        'source_fingerprint' => $fingerprint,
        'result' => $result,
    ]);

    $rejected = app(RejectIngredientEnrichmentItem::class)->handle($admin, $item, 'The supplied identity does not match our material.');

    expect($rejected->status)->toBe(IngredientEnrichmentItemStatus::Rejected)
        ->and($rejected->rejected_by_user_id)->toBe($admin->id)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->rejection_reason)->toBe('The supplied identity does not match our material.')
        ->and($ingredient->fresh()->display_name)->toBe('Rejected Review Oil');

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch);

    expect($totals)->toBe(['applied' => 0, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($rejected->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Rejected)
        ->and($ingredient->fresh()->display_name)->toBe('Rejected Review Oil');
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

it('does not mark incomplete or cancelled batches as applied', function (): void {
    $cases = [
        ['batch_status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'item_status' => null],
        ['batch_status' => IngredientEnrichmentBatchStatus::Processing, 'item_status' => IngredientEnrichmentItemStatus::Researching],
        ['batch_status' => IngredientEnrichmentBatchStatus::PartiallyFailed, 'item_status' => IngredientEnrichmentItemStatus::Failed],
        ['batch_status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'item_status' => IngredientEnrichmentItemStatus::Stale],
        ['batch_status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'item_status' => IngredientEnrichmentItemStatus::Ready],
        ['batch_status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'item_status' => IngredientEnrichmentItemStatus::Warning],
        ['batch_status' => IngredientEnrichmentBatchStatus::ReadyForReview, 'item_status' => IngredientEnrichmentItemStatus::Approved],
        ['batch_status' => IngredientEnrichmentBatchStatus::Cancelled, 'item_status' => IngredientEnrichmentItemStatus::Applied],
    ];

    foreach ($cases as $case) {
        $batch = IngredientEnrichmentBatch::factory()->create(['status' => $case['batch_status']]);
        if ($case['item_status'] instanceof IngredientEnrichmentItemStatus) {
            IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create(['status' => $case['item_status']]);
        }

        app(IngredientEnrichmentBatchService::class)->markAppliedWhenComplete($batch->id);

        expect($batch->fresh()->status)->toBe($case['batch_status']);
    }
});

it('does not revive a cancelled full enrichment batch during apply completion', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Cancelled,
    ]);
    IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Applied,
    ]);

    app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    expect($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Cancelled);
});

/** @return array<string, mixed> */
function reviewResult(Ingredient $ingredient, string $fingerprint): array
{
    $eu = reviewEvidenceSource('verified', 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175', '32025D1175');
    $us = reviewEvidenceSource('supported', 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names', '21 CFR 701.3');
    $translations = collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])->map(function (string $locale): array {
        $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

        return [
            'locale' => $locale, 'display_name' => "Review Oil {$locale}", 'saponification_name' => null,
            'info_markdown' => "## {$headings['overview']}\nA translated useful cosmetic ingredient.\n\n## {$headings['formulation_use']}\nUsed as an emollient in cosmetic formulations.",
        ];
    })->all();

    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result', 'schema_version' => 2,
        'catalog_key' => $ingredient->catalog_key, 'source_fingerprint' => $fingerprint,
        'proposal' => [
            'display_name' => 'Review Oil', 'inci_name' => 'REVIEW OIL', 'category' => 'other', 'subcategory' => null,
            'saponification_name' => null,
            'soap_inci_naoh_name' => null, 'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nA useful cosmetic ingredient for simple products.\n\n## Formulation use\nUsed as an emollient ingredient in cosmetic formulations.",
            'soapmaking_relevant' => false, 'identifiers' => [], 'cosing_functions' => [],
            'translations' => $translations,
            'market_labels' => [
                ['market_code' => 'eu', 'declaration_name' => 'REVIEW OIL', 'reviewed_at' => null, 'effective_from' => null, 'effective_until' => null, 'source_name' => 'EUR-Lex', ...$eu],
                ['market_code' => 'us', 'declaration_name' => 'Review Oil', 'reviewed_at' => null, 'effective_from' => null, 'effective_until' => null, 'source_name' => 'FDA', ...$us],
            ],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
            ['field' => 'proposal.display_name', 'confidence' => 'supported'],
            ['field' => 'proposal.saponification_name', 'confidence' => 'supported'],
            ['field' => 'proposal.info_markdown', 'confidence' => 'supported'],
            ['field' => 'proposal.soapmaking_relevant', 'confidence' => 'supported'],
            ['field' => 'proposal.market_labels.0', 'confidence' => 'verified'],
            ['field' => 'proposal.market_labels.1', 'confidence' => 'supported'],
            ...collect($translations)->keys()->flatMap(fn (int $index): array => [
                ['field' => "proposal.translations.{$index}.display_name", 'confidence' => 'supported'],
                ['field' => "proposal.translations.{$index}.saponification_name", 'confidence' => 'supported'],
                ['field' => "proposal.translations.{$index}.info_markdown", 'confidence' => 'supported'],
            ])->all(),
        ],
        'evidence' => [[
            'field' => 'proposal.inci_name', 'source_name' => 'EUR-Lex', ...$eu,
        ], ['field' => 'proposal.market_labels.0', 'source_name' => 'EUR-Lex', ...$eu],
            ['field' => 'proposal.market_labels.1', 'source_name' => 'FDA', ...$us]],
        'regulatory_findings' => [],
        'confidence' => 'high', 'warnings' => [], 'unresolved_questions' => [],
    ];
}

/** @return array<string, mixed> */
function reviewEvidenceSource(string $confidence, string $url, string $version): array
{
    return [
        'source_url' => $url,
        'source_tier' => 'official',
        'confidence' => $confidence,
        'source_version' => $version,
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-14T12:00:00+00:00',
    ];
}
