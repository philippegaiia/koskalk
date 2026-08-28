<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientGuidanceProposal;
use App\Actions\IngredientEnrichment\EditIngredientGuidanceProposal;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceProposalReviewService;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
});

it('edits and applies guidance without changing approved identity fields', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'info_markdown' => guidanceApplyText('Original'),
        'category' => 'lipids',
    ]);
    $ingredient->translations()->create([
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => guidanceApplyTranslationText('Original'),
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => guidanceResult($ingredient, $sourceFingerprint),
    ]);

    app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'info_markdown' => guidanceApplyText('Edited'),
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => guidanceApplyTranslationText('Révisé'),
        ]],
    ]);
    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item->fresh());
    $beforeIdentity = $ingredient->only(['display_name', 'inci_name', 'category', 'subcategory', 'saponification_name']);

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());
    $ingredient->refresh();

    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($ingredient->only(['display_name', 'inci_name', 'category', 'subcategory', 'saponification_name']))->toBe($beforeIdentity)
        ->and($ingredient->info_markdown)->toBe(trim(guidanceApplyText('Edited')))
        ->and($ingredient->translations()->where('locale', 'fr')->value('info_markdown'))->toBe(trim(guidanceApplyTranslationText('Révisé')))
        ->and($ingredient->translations()->where('locale', 'fr')->value('origin'))->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.evidence.0.source_name'))->toBe('COSMILE Europe');
});

it('rejects identity fields in a guidance proposal and marks stale items before approval', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['info_markdown' => guidanceApplyText('Original')]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => guidanceResult($ingredient, $sourceFingerprint),
    ]);

    expect(fn () => app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'info_markdown' => guidanceApplyText('Edited'),
        'inci_name' => 'FORBIDDEN',
    ]))->toThrow(ValidationException::class);

    $ingredient->update(['display_name' => 'Changed after generation']);
    expect(fn () => app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item->fresh()))
        ->toThrow(ValidationException::class);
    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Stale);
});

it('delegates guidance edits from the Action to the review service', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create(['info_markdown' => guidanceApplyText('Original')]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
    ]);
    $proposal = ['info_markdown' => guidanceApplyText('Edited')];
    $review = Mockery::mock(IngredientGuidanceProposalReviewService::class);
    $review->shouldReceive('edit')->once()->with($admin, $item, $proposal)->andReturn($item);
    app()->instance(IngredientGuidanceProposalReviewService::class, $review);

    expect(app(EditIngredientGuidanceProposal::class)->handle($admin, $item, $proposal))->toBe($item);
});

it('delegates guidance approvals from the Action to the review service', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create(['info_markdown' => guidanceApplyText('Original')]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
    ]);
    $review = Mockery::mock(IngredientGuidanceProposalReviewService::class);
    $review->shouldReceive('approve')->once()->with($admin, $item)->andReturn($item);
    app()->instance(IngredientGuidanceProposalReviewService::class, $review);

    expect(app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item))->toBe($item);
});

/** @return array<string,mixed> */
function guidanceResult(Ingredient $ingredient, string $sourceFingerprint): array
{
    return [
        'format' => 'soapkraft-ingredient-guidance-refresh-result',
        'schema_version' => 1,
        'mode' => 'guidance_refresh',
        'subject_public_id' => (string) $ingredient->public_id,
        'source_fingerprint' => $sourceFingerprint,
        'info_markdown' => guidanceApplyText('Generated'),
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => guidanceApplyTranslationText('Généré'),
        ]],
        'guidance_evidence' => [[
            'source_name' => 'COSMILE Europe',
            'source_url' => 'https://cosmileeurope.eu/example',
            'summary' => 'A supported practical formulation fact.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-28T00:00:00+00:00',
        ]],
        'prompt_versions' => [
            'guidance' => 'ingredient-guidance-v1',
            'localization' => 'ingredient-guidance-localization-v1',
        ],
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}

function guidanceApplyText(string $label): string
{
    return "## Overview\n{$label} olive oil guidance.\n\n## Formulation use\nThis material-specific profile supports a fluid oil phase selection and a measured emollient contribution. ".str_repeat('Review the complete formula and material grade. ', 11);
}

function guidanceApplyTranslationText(string $label): string
{
    return "## Vue d’ensemble\n{$label} conseils sur l’huile d’olive.\n\n## Utilisation en formulation\nCe profil aide à sélectionner une phase huileuse fluide et une contribution émolliente mesurée. ".str_repeat('Évaluer la formule complète et la qualité du lot. ', 11);
}
