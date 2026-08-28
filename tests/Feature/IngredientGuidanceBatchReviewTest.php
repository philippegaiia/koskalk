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
use App\Services\IngredientEnrichment\IngredientGuidanceChangePlanner;
use App\Services\IngredientEnrichment\IngredientGuidanceProposalReviewService;
use App\Services\IngredientTranslationService;
use App\Services\IngredientTranslationSourceFingerprint;
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
        ->and($ingredient->translations()->where('locale', 'fr')->value('origin'))->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.evidence.0.source_name'))->toBe('COSMILE Europe');
});

it('leaves unedited locales unchanged and outdated when English guidance is edited', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => guidanceApplyText('Original'),
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [
        ['locale' => 'fr', 'info_markdown' => guidanceApplyTranslationText('Stored French')],
        ['locale' => 'de', 'info_markdown' => guidanceApplyLocalizedTranslationText('Stored German', 'de')],
    ], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $before = $ingredient->translations()->get()->keyBy('locale');
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => guidanceResultWithLocales($ingredient, $sourceFingerprint, [
            ['locale' => 'fr', 'info_markdown' => guidanceApplyTranslationText('Generated French')],
            ['locale' => 'de', 'info_markdown' => guidanceApplyLocalizedTranslationText('Generated German', 'de')],
        ]),
    ]);

    app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'info_markdown' => guidanceApplyText('Edited'),
    ]);
    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item->fresh());

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $translations = $ingredient->translations()->get()->keyBy('locale');
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($ingredient->info_markdown)->toBe(trim(guidanceApplyText('Edited')))
        ->and($translations['fr']->info_markdown)->toBe($before['fr']->info_markdown)
        ->and($translations['fr']->source_fingerprint)->toBe($before['fr']->source_fingerprint)
        ->and($translations['de']->info_markdown)->toBe($before['de']->info_markdown)
        ->and($translations['de']->source_fingerprint)->toBe($before['de']->source_fingerprint)
        ->and($translations['fr']->source_fingerprint)->not->toBe(
            app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient),
        )
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
});

it('applies English and reviewer-edited French while leaving German outdated', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => guidanceApplyText('Original'),
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [
        ['locale' => 'fr', 'info_markdown' => guidanceApplyTranslationText('Stored French')],
        ['locale' => 'de', 'info_markdown' => guidanceApplyLocalizedTranslationText('Stored German', 'de')],
    ], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $oldTranslationFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => guidanceResultWithLocales($ingredient, $sourceFingerprint, [
            ['locale' => 'fr', 'info_markdown' => guidanceApplyTranslationText('Generated French')],
            ['locale' => 'de', 'info_markdown' => guidanceApplyLocalizedTranslationText('Generated German', 'de')],
        ]),
    ]);

    app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'info_markdown' => guidanceApplyText('Edited'),
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => guidanceApplyTranslationText('Reviewer French'),
        ]],
    ]);
    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item->fresh());

    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $translations = $ingredient->translations()->get()->keyBy('locale');
    $currentFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($translations['fr']->info_markdown)->toBe(trim(guidanceApplyTranslationText('Reviewer French')))
        ->and($translations['fr']->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and($translations['fr']->prompt_version)->toBeNull()
        ->and($translations['fr']->source_fingerprint)->toBe($currentFingerprint)
        ->and($translations['de']->info_markdown)->toBe(guidanceApplyLocalizedTranslationText('Stored German', 'de'))
        ->and($translations['de']->source_fingerprint)->toBe($oldTranslationFingerprint)
        ->and($translations['de']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
});

it('applies a French-only reviewer edit and generated German with truthful provenance', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [
        [
            'locale' => 'fr',
            'display_name' => 'Huile d’olive conservée',
            'saponification_name' => 'Savon d’huile conservé',
            'info_markdown' => guidanceApplyTranslationText('Stored French'),
        ],
        [
            'locale' => 'de',
            'display_name' => 'Gespeichertes Olivenöl',
            'saponification_name' => 'Gespeicherte Olivenölseife',
            'info_markdown' => guidanceApplyLocalizedTranslationText('Stored German', 'de'),
        ],
    ], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['info_markdown'] = guidanceApplyText('Original');
    $result['translations'][] = [
        'locale' => 'de',
        'info_markdown' => guidanceApplyLocalizedTranslationText('Generated German', 'de'),
    ];
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);
    $beforeGerman = $ingredient->translations()->where('locale', 'de')->firstOrFail()->fresh();

    app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => guidanceApplyTranslationText('Reviewer French'),
        ]],
    ]);
    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item->fresh());

    app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $translations = $ingredient->translations()->get()->keyBy('locale');
    $currentFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    expect($translations['fr']->info_markdown)->toBe(trim(guidanceApplyTranslationText('Reviewer French')))
        ->and($translations['fr']->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and($translations['fr']->prompt_version)->toBeNull()
        ->and($translations['fr']->source_fingerprint)->toBe($currentFingerprint)
        ->and($translations['de']->display_name)->toBe($beforeGerman->display_name)
        ->and($translations['de']->saponification_name)->toBe($beforeGerman->saponification_name)
        ->and($translations['de']->info_markdown)->toBe(trim(guidanceApplyLocalizedTranslationText('Generated German', 'de')))
        ->and($translations['de']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($translations['de']->prompt_version)->toBe('ingredient-guidance-localization-v1')
        ->and($translations['de']->source_fingerprint)->toBe($currentFingerprint);
});

it('refreshes reviewer provenance when a locale is edited back to its stored text', function (): void {
    $admin = User::factory()->admin()->create();
    $storedFrench = guidanceApplyTranslationText('Stored French');
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [[
        'locale' => 'fr',
        'info_markdown' => $storedFrench,
    ]], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['translations'][0]['info_markdown'] = guidanceApplyTranslationText('Generated French');
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);

    app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => $storedFrench,
        ]],
    ]);
    $edited = $item->fresh();
    expect($edited->edited_fields)->toContain('proposal.translations.fr.info_markdown');

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $edited);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $french = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    $currentFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    $appliedItem = $item->fresh();
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($french->info_markdown)->toBe(trim($storedFrench))
        ->and($french->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
        ->and($french->prompt_version)->toBeNull()
        ->and($french->source_fingerprint)->toBe($currentFingerprint)
        ->and($appliedItem->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($appliedItem->applied_by_user_id)->toBe($admin->id)
        ->and($appliedItem->applied_at)->not->toBeNull()
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
});

it('does not revive a cancelled guidance batch during apply completion', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::Cancelled,
    ]);
    IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Applied,
    ]);

    app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    expect($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Cancelled);
});

it('revalidates an identical stale locale without changing its text or unrelated metadata', function (): void {
    $admin = User::factory()->admin()->create();
    $evidence = [[
        'source_name' => 'COSMILE Europe',
        'source_url' => 'https://cosmileeurope.eu/example',
        'summary' => 'A supported practical formulation fact.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => $evidence,
                    'guidance_prompt_version' => 'stored-guidance-v1',
                    'localization_prompt_version' => 'stored-localization-v1',
                ],
            ],
        ],
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [
        ['locale' => 'fr', 'display_name' => 'Huile d’olive', 'info_markdown' => guidanceApplyTranslationText('Stored French')],
        ['locale' => 'de', 'display_name' => 'Olivenöl', 'info_markdown' => guidanceApplyTranslationText('Stored German')],
    ], IngredientTranslationOrigin::AiGenerated, 'stored-localization-v1');
    $ingredient->update(['info_markdown' => guidanceApplyText('Updated')]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['mode'] = IngredientEnrichmentBatchMode::GuidanceLocalization->value;
    $result['info_markdown'] = guidanceApplyText('Updated');
    $result['translations'] = [[
        'locale' => 'fr',
        'info_markdown' => guidanceApplyTranslationText('Stored French'),
    ]];
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        $result,
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
        'plan' => $plan,
    ]);
    $beforeFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    $beforeGerman = $ingredient->translations()->where('locale', 'de')->firstOrFail()->fresh();

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $french = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    $german = $ingredient->translations()->where('locale', 'de')->firstOrFail()->fresh();
    $currentFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($french->info_markdown)->toBe($beforeFrench->info_markdown)
        ->and($french->display_name)->toBe($beforeFrench->display_name)
        ->and($french->source_fingerprint)->toBe($currentFingerprint)
        ->and($french->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($french->prompt_version)->toBe('ingredient-guidance-localization-v1')
        ->and($german->info_markdown)->toBe($beforeGerman->info_markdown)
        ->and($german->source_fingerprint)->toBe($beforeGerman->source_fingerprint)
        ->and($german->origin)->toBe($beforeGerman->origin)
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.guidance_prompt_version'))->toBe('stored-guidance-v1');
});

it('persists approved guidance evidence when prose is identical', function (): void {
    $admin = User::factory()->admin()->create();
    $firstEvidence = [[
        'source_name' => 'First source',
        'source_url' => 'https://example.test/first',
        'summary' => 'First evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $secondEvidence = [[
        'source_name' => 'Second source',
        'source_url' => 'https://example.test/second',
        'summary' => 'Second evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $firstEvidence]]],
    ]);
    $service = app(IngredientTranslationService::class);
    $service->sync($ingredient, [[
        'locale' => 'fr',
        'info_markdown' => guidanceApplyTranslationText('Original'),
    ]], IngredientTranslationOrigin::AiGenerated, 'ingredient-guidance-localization-v1');
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['info_markdown'] = guidanceApplyText('Original');
    $result['translations'][0]['info_markdown'] = guidanceApplyTranslationText('Original');
    $result['guidance_evidence'] = $secondEvidence;
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
        'plan' => app(IngredientGuidanceChangePlanner::class)->plan(
            $ingredient,
            $result,
            IngredientEnrichmentBatchMode::GuidanceRefresh,
        ),
    ]);

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))->toBe($secondEvidence)
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied)
        ->and($item->fresh()->applied_by_user_id)->toBe($admin->id)
        ->and($item->fresh()->applied_at)->not->toBeNull();
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

/**
 * @param  list<array{locale: string, info_markdown: string}>  $translations
 * @return array<string, mixed>
 */
function guidanceResultWithLocales(
    Ingredient $ingredient,
    string $sourceFingerprint,
    array $translations,
): array {
    return [
        'format' => 'soapkraft-ingredient-guidance-refresh-result',
        'schema_version' => 1,
        'mode' => 'guidance_refresh',
        'subject_public_id' => (string) $ingredient->public_id,
        'source_fingerprint' => $sourceFingerprint,
        'info_markdown' => guidanceApplyText('Generated'),
        'translations' => $translations,
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
    return rtrim("## Overview\n{$label} olive oil guidance.\n\n## Formulation use\nThis material-specific profile supports a fluid oil phase selection and a measured emollient contribution. ".str_repeat('Review the complete formula and material grade. ', 11));
}

function guidanceApplyTranslationText(string $label): string
{
    return guidanceApplyLocalizedTranslationText($label, 'fr');
}

function guidanceApplyLocalizedTranslationText(string $label, string $locale): string
{
    $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

    return rtrim("## {$headings['overview']}\n{$label} conseils sur l’huile d’olive.\n\n## {$headings['formulation_use']}\nCe profil aide à sélectionner une phase huileuse fluide et une contribution émolliente mesurée. ".str_repeat('Évaluer la formule complète et la qualité du lot. ', 11));
}
