<?php

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceProposalReviewService;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshResultValidator;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    config()->set('ingredient-enrichment.guidance.minimum_words', 1);
    config()->set('ingredient-enrichment.guidance.maximum_words', 500);
});

it('audits edited guidance fields and persists a recomputed plan', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem();
    $originalResult = $item->result;

    $edited = app(IngredientGuidanceProposalReviewService::class)->edit($actor, $item, [
        'info_markdown' => reviewServiceEnglish('Edited'),
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => reviewServiceFrench('Révisé'),
        ]],
    ]);

    expect($edited->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($edited->result['info_markdown'])->toBe(reviewServiceEnglish('Edited'))
        ->and($edited->result['translations'])->toBe([[
            'locale' => 'fr',
            'info_markdown' => reviewServiceFrench('Révisé'),
        ]])
        ->and($edited->original_result)->toBe($originalResult)
        ->and($edited->edited_fields)->toBe([
            'proposal.info_markdown',
            'proposal.translations.fr.info_markdown',
        ])
        ->and($edited->edited_by_user_id)->toBe($actor->id)
        ->and($edited->edited_at)->not->toBeNull()
        ->and($edited->approved_by_user_id)->toBeNull()
        ->and($edited->approved_at)->toBeNull()
        ->and(collect($edited->plan['decisions'])->pluck('field')->all())
        ->toContain('proposal.info_markdown', 'proposal.translations.fr.info_markdown')
        ->and(data_get($edited->validation_report, 'valid'))->toBeTrue();
});

it('rejects forbidden identity and unknown proposal fields before writing', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem();
    $originalResult = $item->result;

    foreach ([
        ['inci_name' => 'Forbidden identity'],
        ['unexpected' => 'Unknown field'],
    ] as $proposal) {
        try {
            app(IngredientGuidanceProposalReviewService::class)->edit($actor, $item, $proposal);
            test()->fail('Expected the forbidden guidance field to be rejected.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['proposal'][0])
                ->toBe(__('ingredient_enrichment_admin.validation.guidance_proposal_fields'));
        }
    }

    expect($item->fresh()->result)->toBe($originalResult)
        ->and($item->fresh()->edited_by_user_id)->toBeNull();
});

it('rejects malformed guidance proposal arrays and text with translated messages', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem();

    $cases = [
        'english text' => [
            ['info_markdown' => ['not text']],
            'proposal.info_markdown',
            'ingredient_enrichment_admin.validation.guidance_english_text',
        ],
        'translations array' => [
            ['translations' => 'not an array'],
            'proposal.translations',
            'ingredient_enrichment_admin.validation.guidance_translations_array',
        ],
        'translation row' => [
            ['translations' => ['not a row']],
            'proposal.translations.0',
            'ingredient_enrichment_admin.validation.guidance_translation_row',
        ],
        'translation locale' => [
            ['translations' => [['locale' => [], 'info_markdown' => reviewServiceFrench('Edited')]]],
            'proposal.translations.0.locale',
            'ingredient_enrichment_admin.validation.guidance_translation_locale',
        ],
        'translation text' => [
            ['translations' => [['locale' => 'fr', 'info_markdown' => []]]],
            'proposal.translations.0.info_markdown',
            'ingredient_enrichment_admin.validation.guidance_translation_text',
        ],
    ];

    foreach ($cases as [$proposal, $path, $translationKey]) {
        try {
            app(IngredientGuidanceProposalReviewService::class)->edit($actor, $item, $proposal);
            test()->fail("Expected {$path} to be rejected.");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($path)
                ->and($exception->errors()[$path][0])->toBe(__($translationKey));
        }
    }
});

it('forbids English edits in localization-only batches', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem(IngredientEnrichmentBatchMode::GuidanceLocalization);

    try {
        app(IngredientGuidanceProposalReviewService::class)->edit($actor, $item, [
            'info_markdown' => reviewServiceEnglish('Edited'),
        ]);
        test()->fail('Expected the English guidance edit to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['proposal.info_markdown'][0])
            ->toBe(__('ingredient_enrichment_admin.validation.guidance_english_edit_forbidden'));
    }
});

it('marks stale items before returning a translated stale validation error', function (): void {
    $actor = User::factory()->admin()->create();
    [$ingredient, $batch, $item] = reviewServiceItem();
    $ingredient->update(['display_name' => 'Changed after generation']);

    try {
        app(IngredientGuidanceProposalReviewService::class)->approve($actor, $item);
        test()->fail('Expected stale guidance approval to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['item'][0])->toBe(__('ingredient_enrichment_admin.validation.stale'));
    }

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Stale)
        ->and($batch->fresh()->stale_count)->toBe(1);
});

it('rejects non-guidance batches with a localized mode validation error', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem(IngredientEnrichmentBatchMode::FillMissing);

    try {
        app(IngredientGuidanceProposalReviewService::class)->approve($actor, $item);
        test()->fail('Expected the non-guidance batch to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['batch'][0])
            ->toBe(__('ingredient_enrichment_admin.validation.guidance_batch_mode'));
    }
});

it('approves a valid proposal with audit metadata while preserving its plan', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem();
    $plan = [
        'changed' => true,
        'decisions' => [[
            'field' => 'proposal.info_markdown',
            'decision' => 'replace',
        ]],
        'effective' => ['info_markdown' => reviewServiceEnglish('Generated')],
    ];
    $item->update([
        'plan' => $plan,
        'rejected_by_user_id' => $actor->id,
        'rejected_at' => now(),
        'rejection_reason' => 'Needs another review',
    ]);

    $approved = app(IngredientGuidanceProposalReviewService::class)->approve($actor, $item->fresh());

    expect($approved->status)->toBe(IngredientEnrichmentItemStatus::Approved)
        ->and($approved->approved_by_user_id)->toBe($actor->id)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->rejected_by_user_id)->toBeNull()
        ->and($approved->rejected_at)->toBeNull()
        ->and($approved->rejection_reason)->toBeNull()
        ->and($approved->plan)->toBe($plan)
        ->and(data_get($approved->validation_report, 'valid'))->toBeTrue();
});

it('reports validator failures and absent evidence through localized validation messages', function (): void {
    $actor = User::factory()->admin()->create();
    [, , $item] = reviewServiceItem();
    $result = $item->result;
    unset($result['guidance_evidence']);
    $result['info_markdown'] = 'No approved headings';
    $item->update(['result' => $result]);

    try {
        app(IngredientGuidanceProposalReviewService::class)->approve($actor, $item->fresh());
        test()->fail('Expected the invalid result to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['info_markdown'][0])
            ->toBe(__('ingredient_enrichment.validation.guidance_headings'))
            ->and($exception->errors()['guidance_evidence'][0])
            ->toBe(__('ingredient_enrichment.validation.guidance_evidence_array'));
    }

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready);
});

it('quarantines malformed inherited evidence before validating a guidance result', function (): void {
    $ingredient = Ingredient::factory()->create();
    $fingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = reviewServiceResult($ingredient, $fingerprint);
    $result['translations'] = [];
    $result['guidance_evidence'] = [
        [
            'source_name' => '',
            'source_url' => 'https://malformed.example/first',
            'summary' => 'Missing source name.',
            'source_tier' => 'editorial',
        ],
        [
            'source_name' => 'Partial source',
            'source_url' => 'https://malformed.example/second',
            'summary' => 'Partially classified evidence.',
            'source_tier' => 'editorial',
            'claim_type' => 'usage',
        ],
    ];

    $report = app(IngredientGuidanceRefreshResultValidator::class)->validate(
        $result,
        $ingredient,
        IngredientEnrichmentBatchMode::GuidanceRefresh,
        [],
    );

    expect($report['valid'])->toBeTrue()
        ->and($report['errors'])->toBe([])
        ->and(data_get($report, 'normalized.guidance_evidence'))->toBe([]);
});

/** @return array{0: Ingredient, 1: IngredientEnrichmentBatch, 2: IngredientEnrichmentBatchItem} */
function reviewServiceItem(
    IngredientEnrichmentBatchMode $mode = IngredientEnrichmentBatchMode::GuidanceRefresh,
    IngredientEnrichmentItemStatus $status = IngredientEnrichmentItemStatus::Ready,
): array {
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => reviewServiceEnglish('Current'),
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => $mode,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => $status,
            'source_fingerprint' => $sourceFingerprint,
            'result' => reviewServiceResult($ingredient, $sourceFingerprint, $mode),
        ]);

    return [$ingredient, $batch, $item];
}

/** @return array<string, mixed> */
function reviewServiceResult(
    Ingredient $ingredient,
    string $sourceFingerprint,
    IngredientEnrichmentBatchMode $mode = IngredientEnrichmentBatchMode::GuidanceRefresh,
): array {
    return [
        'format' => 'soapkraft-ingredient-guidance-refresh-result',
        'schema_version' => 1,
        'mode' => $mode->value,
        'subject_public_id' => (string) $ingredient->public_id,
        'source_fingerprint' => $sourceFingerprint,
        'info_markdown' => reviewServiceEnglish('Generated'),
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => reviewServiceFrench('Généré'),
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

function reviewServiceEnglish(string $label): string
{
    return "## Overview\n{$label} olive oil guidance.\n\n## Formulation use\nUse this material in a suitable formulation.";
}

function reviewServiceFrench(string $label): string
{
    return "## Vue d’ensemble\n{$label} conseils sur l’huile d’olive.\n\n## Utilisation en formulation\nUtiliser ce matériau dans une formule adaptée.";
}
