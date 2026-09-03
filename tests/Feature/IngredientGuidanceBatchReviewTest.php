<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientEnrichment;
use App\Actions\IngredientEnrichment\ApproveIngredientGuidanceProposal;
use App\Actions\IngredientEnrichment\EditIngredientGuidanceProposal;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientFunctionSource;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientTranslationOrigin;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientFunction;
use App\Models\IngredientIdentifier;
use App\Models\IngredientIdentifierEvidence;
use App\Models\IngredientMarketLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceChangePlanner;
use App\Services\IngredientEnrichment\IngredientGuidanceEvidencePolicy;
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
        ->and($ingredient->translations()->where('locale', 'fr')->value('info_markdown'))->toBe(trim(guidanceApplyTranslationText('Original')))
        ->and($ingredient->translations()->where('locale', 'fr')->value('origin'))->toBe(IngredientTranslationOrigin::Legacy)
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.research_prompt_version'))->toBe('ingredient-guidance-research-v2')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.evidence.0.source_name'))->toBe('COSMILE Europe');
});

it('preserves locale metadata and localization provenance when a guidance refresh has no translations', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'Prior guidance source',
                        'source_url' => 'https://example.test/prior-guidance',
                        'summary' => 'Previously reviewed guidance.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-01T00:00:00+00:00',
                    ]],
                    'guidance_prompt_version' => 'stored-guidance-v1',
                    'research_prompt_version' => 'stored-research-v1',
                    'localization_prompt_version' => 'stored-localization-v1',
                ],
            ],
        ],
    ]);
    $translation = $ingredient->translations()->create([
        'locale' => 'fr',
        'display_name' => 'Nom français relu',
        'saponification_name' => 'Nom de saponification relu',
        'info_markdown' => guidanceApplyTranslationText('Reviewer French'),
        'source_fingerprint' => 'reviewer-guidance-fingerprint',
        'origin' => IngredientTranslationOrigin::ReviewerEdited,
        'prompt_version' => null,
    ]);
    $beforeTranslation = $translation->fresh()->only([
        'display_name',
        'saponification_name',
        'info_markdown',
        'source_fingerprint',
        'origin',
        'prompt_version',
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['info_markdown'] = guidanceApplyText('Refreshed');
    $result['translations'] = [];
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($ingredient->info_markdown)->toBe(trim($result['info_markdown']))
        ->and(collect(data_get($ingredient->source_data, 'enrichment.guidance.evidence', []))
            ->pluck('source_name')->all())->toContain('COSMILE Europe')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.localization_prompt_version'))->toBe('stored-localization-v1')
        ->and($ingredient->translations()->where('locale', 'fr')->firstOrFail()->only([
            'display_name',
            'saponification_name',
            'info_markdown',
            'source_fingerprint',
            'origin',
            'prompt_version',
        ]))->toBe($beforeTranslation);
});

it('never overwrites an existing reviewer-owned locale during localization apply', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'saponification_name' => 'Olive oil soap',
        'info_markdown' => guidanceApplyText('Current English'),
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Nom français relu',
        'saponification_name' => 'Savon d’huile relu',
        'info_markdown' => guidanceApplyTranslationText('Reviewer French'),
    ]], IngredientTranslationOrigin::ReviewerEdited);
    $beforeFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['mode'] = IngredientEnrichmentBatchMode::GuidanceLocalization->value;
    $result['info_markdown'] = $ingredient->info_markdown;
    $result['translations'] = [[
        'locale' => 'fr',
        'display_name' => 'Nom français généré',
        'saponification_name' => 'Savon d’huile généré',
        'info_markdown' => guidanceApplyTranslationText('AI replacement'),
    ]];
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);

    $editedItem = app(EditIngredientGuidanceProposal::class)->handle($admin, $item, [
        'translations' => [[
            'locale' => 'fr',
            'info_markdown' => guidanceApplyTranslationText('Reviewer update'),
        ]],
    ]);
    expect(data_get($editedItem->result, 'translations.0.display_name'))->toBe('Nom français généré')
        ->and(data_get($editedItem->result, 'translations.0.saponification_name'))->toBe('Savon d’huile généré');
    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $editedItem);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $afterFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($afterFrench->only([
            'display_name',
            'saponification_name',
            'info_markdown',
            'source_fingerprint',
            'origin',
            'prompt_version',
        ]))->toBe($beforeFrench->only([
            'display_name',
            'saponification_name',
            'info_markdown',
            'source_fingerprint',
            'origin',
            'prompt_version',
        ]));
});

it('persists generated localized names and guidance together', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Palm Kernel Oil',
        'saponification_name' => 'Palm Kernel Oil Soap',
        'info_markdown' => guidanceApplyText('Current English'),
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['mode'] = IngredientEnrichmentBatchMode::GuidanceLocalization->value;
    $result['info_markdown'] = $ingredient->info_markdown;
    $result['translations'] = [[
        'locale' => 'fr',
        'display_name' => 'Huile de palmiste',
        'saponification_name' => 'Savon à l’huile de palmiste',
        'info_markdown' => guidanceApplyTranslationText('Conseils localisés'),
    ]];
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $french = $ingredient->translations()->where('locale', 'fr')->firstOrFail();
    expect($totals['applied'])->toBe(1)
        ->and($french->display_name)->toBe('Huile de palmiste')
        ->and($french->saponification_name)->toBe('Savon à l’huile de palmiste')
        ->and($french->info_markdown)->toBe(trim(guidanceApplyTranslationText('Conseils localisés')))
        ->and($french->origin)->toBe(IngredientTranslationOrigin::AiGenerated);
});

it('applies guidance without changing identity records or their provenance', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'IDENTITY-STABILITY',
        'category' => IngredientCategory::Lipids,
        'subcategory' => 'vegetable_oils',
        'taxonomy_source' => 'admin_reviewed_enrichment',
        'taxonomy_reviewed_at' => '2026-08-01 10:00:00',
        'taxonomy_reviewed_by_user_id' => $admin->id,
        'cosing_reference' => '54495',
        'display_name' => 'Olive oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'saponification_name' => 'Olive oil',
        'soap_inci_naoh_name' => 'Sodium olivate',
        'soap_inci_koh_name' => 'Potassium olivate',
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => false,
        'requires_admin_review' => false,
        'is_active' => true,
        'is_manufactured' => true,
        'source_data' => [
            'enrichment' => [
                'core' => [
                    'schema_version' => 1,
                    'field_confidence' => [[
                        'field' => 'proposal.inci_name',
                        'confidence' => 'supported',
                    ]],
                    'value_provenance' => [[
                        'field' => 'proposal.inci_name',
                        'kind' => 'source_confirmed',
                        'reasoning' => 'Exact registry identity match.',
                        'source_urls' => ['https://registry.example/olive-oil'],
                    ]],
                    'source_fingerprint' => 'identity-source-fingerprint',
                    'result_fingerprint' => 'identity-result-fingerprint',
                ],
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'Prior guidance source',
                        'source_url' => 'https://example.test/prior-guidance',
                        'summary' => 'Previously reviewed guidance.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-01T00:00:00+00:00',
                    ]],
                ],
            ],
        ],
    ]);
    $identifier = $ingredient->identifiers()->create([
        'scheme' => IngredientIdentifierScheme::Cas,
        'value' => '8001-25-0',
        'normalized_value' => '8001-25-0',
        'is_primary' => true,
    ]);
    $identifier->evidence()->create([
        'source_name' => 'Registry identity source',
        'source_url' => 'https://registry.example/olive-oil',
        'source_tier' => IngredientSourceTier::Official,
        'confidence' => IngredientEvidenceConfidence::Verified,
        'source_version' => 'registry-2026',
        'source_updated_at' => '2026-07-31',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]);
    $function = IngredientFunction::factory()->create([
        'key' => 'identity_stability_emollient',
        'name' => 'Identity stability emollient',
    ]);
    $ingredient->functions()->attach($function->id, [
        'source' => IngredientFunctionSource::CosIng->value,
        'source_reference' => 'https://cosing.example/olive-oil',
        'source_checked_at' => '2026-08-01 00:00:00',
        'source_tier' => IngredientSourceTier::Official->value,
        'confidence' => IngredientEvidenceConfidence::Verified->value,
        'source_version' => 'cosing-2026',
        'source_updated_at' => '2026-08-01',
        'assigned_by_user_id' => $admin->id,
    ]);
    IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Eu,
        'declaration_name' => 'OLEA EUROPAEA FRUIT OIL',
        'source_name' => 'EU declaration source',
        'source_url' => 'https://eu.example/olive-oil',
        'source_tier' => IngredientSourceTier::Official,
        'confidence' => IngredientEvidenceConfidence::Verified,
        'source_version' => 'eu-2026',
        'source_updated_at' => '2026-08-01',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
        'reviewed_by_user_id' => $admin->id,
    ]);
    IngredientMarketLabel::factory()->for($ingredient)->create([
        'market_code' => IngredientLabelMarket::Us,
        'declaration_name' => 'Olive Oil',
        'source_name' => 'US declaration source',
        'source_url' => 'https://us.example/olive-oil',
        'source_tier' => IngredientSourceTier::Official,
        'confidence' => IngredientEvidenceConfidence::Supported,
        'source_version' => 'us-2026',
        'source_updated_at' => '2026-08-02',
        'retrieved_at' => '2026-08-02T00:00:00+00:00',
        'reviewed_by_user_id' => $admin->id,
    ]);

    $identityState = function (Ingredient $subject): array {
        $subject->refresh();

        return [
            'canonical' => [
                'catalog_key' => $subject->catalog_key,
                'category' => $subject->category?->value,
                'subcategory' => $subject->subcategory?->value,
                'taxonomy_source' => $subject->taxonomy_source,
                'taxonomy_reviewed_at' => $subject->taxonomy_reviewed_at?->toIso8601String(),
                'taxonomy_reviewed_by_user_id' => $subject->taxonomy_reviewed_by_user_id,
                'cosing_reference' => $subject->cosing_reference,
                'display_name' => $subject->display_name,
                'inci_name' => $subject->inci_name,
                'saponification_name' => $subject->saponification_name,
                'soap_inci_naoh_name' => $subject->soap_inci_naoh_name,
                'soap_inci_koh_name' => $subject->soap_inci_koh_name,
                'is_soap_saponification_trusted' => $subject->is_soap_saponification_trusted,
                'requires_aromatic_compliance' => $subject->requires_aromatic_compliance,
                'requires_admin_review' => $subject->requires_admin_review,
                'is_active' => $subject->is_active,
                'is_manufactured' => $subject->is_manufactured,
            ],
            'core_provenance' => data_get($subject->source_data, 'enrichment.core'),
            'identifiers' => $subject->identifiers()
                ->with('evidence')
                ->orderBy('id')
                ->get()
                ->map(fn (IngredientIdentifier $row): array => [
                    'id' => $row->id,
                    'scheme' => $row->scheme?->value,
                    'value' => $row->value,
                    'normalized_value' => $row->normalized_value,
                    'is_primary' => $row->is_primary,
                    'evidence' => $row->evidence
                        ->sortBy('id')
                        ->map(fn (IngredientIdentifierEvidence $evidence): array => $evidence->toArray())
                        ->values()
                        ->all(),
                ])
                ->all(),
            'functions' => $subject->functions()
                ->orderBy('ingredient_functions.id')
                ->get()
                ->map(fn (IngredientFunction $row): array => [
                    'id' => $row->id,
                    'key' => $row->key,
                    'pivot' => $row->pivot?->toArray(),
                ])
                ->all(),
            'market_labels' => $subject->marketLabels()
                ->orderBy('id')
                ->get()
                ->map(fn (IngredientMarketLabel $row): array => $row->toArray())
                ->all(),
        ];
    };
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
    $beforeIdentity = $identityState($ingredient);

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($identityState($ingredient))->toBe($beforeIdentity)
        ->and($ingredient->fresh()->info_markdown)->toBe(trim(guidanceApplyText('Generated')));
});

it('keeps a workspace override unchanged while applying reviewed platform guidance', function (): void {
    $admin = User::factory()->admin()->create();
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
    $workspace = Workspace::factory()->create();
    $override = WorkspaceIngredientGuidance::factory()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'guidance_html' => '<p>Workspace-authored guidance</p>',
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
    app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    expect($ingredient->fresh()->info_markdown)->toBe(trim(guidanceApplyText('Edited')))
        ->and($ingredient->translations()->where('locale', 'fr')->value('info_markdown'))
        ->toBe(trim(guidanceApplyTranslationText('Original')))
        ->and($override->fresh()->guidance_html)->toBe('<p>Workspace-authored guidance</p>');
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
        ->and($translations['fr']->info_markdown)->toBe(trim(guidanceApplyTranslationText('Stored French')))
        ->and($translations['fr']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($translations['fr']->prompt_version)->toBe('ingredient-guidance-localization-v1')
        ->and($translations['fr']->source_fingerprint)->toBe($oldTranslationFingerprint)
        ->and($translations['de']->info_markdown)->toBe(guidanceApplyLocalizedTranslationText('Stored German', 'de'))
        ->and($translations['de']->source_fingerprint)->toBe($oldTranslationFingerprint)
        ->and($translations['de']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
});

it('applies a French-only reviewer edit and generated German with truthful provenance', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'saponification_name' => 'Olive oil soap',
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
        'display_name' => 'Generiertes Olivenöl',
        'saponification_name' => 'Generierte Olivenölseife',
        'info_markdown' => guidanceApplyLocalizedTranslationText('Generated German', 'de'),
    ];
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
    ]);
    $beforeFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
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
    expect($translations['fr']->info_markdown)->toBe($beforeFrench->info_markdown)
        ->and($translations['fr']->origin)->toBe($beforeFrench->origin)
        ->and($translations['fr']->prompt_version)->toBe($beforeFrench->prompt_version)
        ->and($translations['fr']->source_fingerprint)->toBe($beforeFrench->source_fingerprint)
        ->and($translations['de']->display_name)->toBe($beforeGerman->display_name)
        ->and($translations['de']->saponification_name)->toBe($beforeGerman->saponification_name)
        ->and($translations['de']->info_markdown)->toBe($beforeGerman->info_markdown)
        ->and($translations['de']->origin)->toBe(IngredientTranslationOrigin::AiGenerated)
        ->and($translations['de']->prompt_version)->toBe('ingredient-guidance-localization-v1')
        ->and($translations['de']->source_fingerprint)->toBe($beforeGerman->source_fingerprint);
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
    $beforeFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();

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
        ->and($french->origin)->toBe($beforeFrench->origin)
        ->and($french->prompt_version)->toBe($beforeFrench->prompt_version)
        ->and($french->source_fingerprint)->toBe($beforeFrench->source_fingerprint)
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
        'display_name' => 'Huile d’olive',
        'saponification_name' => null,
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

it('revalidates an identical stale locale in guidance refresh batches', function (): void {
    $admin = User::factory()->admin()->create();
    $storedFrench = guidanceApplyTranslationText('Stored French');
    $ingredient = Ingredient::factory()->create([
        'info_markdown' => guidanceApplyText('Original'),
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/example',
                        'summary' => 'A supported practical formulation fact.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-28T00:00:00+00:00',
                    ]],
                    'guidance_prompt_version' => 'stored-guidance-v1',
                    'localization_prompt_version' => 'stored-localization-v1',
                ],
            ],
        ],
    ]);
    app(IngredientTranslationService::class)->sync($ingredient, [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => $storedFrench,
    ]], IngredientTranslationOrigin::Legacy, 'stored-localization-v1');
    $ingredient->update(['info_markdown' => guidanceApplyText('Updated')]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = guidanceResult($ingredient, $sourceFingerprint);
    $result['info_markdown'] = guidanceApplyText('Updated');
    $result['translations'] = [[
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'saponification_name' => null,
        'info_markdown' => $storedFrench,
    ]];
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        $result,
        IngredientEnrichmentBatchMode::GuidanceRefresh,
    );

    expect($plan['changed'])->toBeTrue()
        ->and($plan['decisions'])->toHaveCount(1)
        ->and($plan['decisions'][0])->toMatchArray([
            'field' => 'proposal.translations.fr.info_markdown',
            'decision' => 'revalidate',
            'current' => trim($storedFrench),
            'proposed' => trim($storedFrench),
        ]);

    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'source_fingerprint' => $sourceFingerprint,
        'result' => $result,
        'plan' => $plan,
    ]);
    $beforeFrench = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();

    app(ApproveIngredientGuidanceProposal::class)->handle($admin, $item);
    $totals = app(ApplyApprovedIngredientEnrichment::class)->handle($admin, $batch->fresh());

    $ingredient->refresh();
    $french = $ingredient->translations()->where('locale', 'fr')->firstOrFail()->fresh();
    $currentFingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and($french->info_markdown)->toBe($beforeFrench->info_markdown)
        ->and($french->display_name)->toBe($beforeFrench->display_name)
        ->and($french->source_fingerprint)->toBe($beforeFrench->source_fingerprint)
        ->and($french->origin)->toBe($beforeFrench->origin)
        ->and($french->prompt_version)->toBe($beforeFrench->prompt_version)
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.guidance_prompt_version'))->toBe('ingredient-guidance-v1')
        ->and(data_get($ingredient->source_data, 'enrichment.guidance.localization_prompt_version'))->toBe('stored-localization-v1')
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied);
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
    $expectedEvidence = app(IngredientGuidanceEvidencePolicy::class)->reconcilePersisted(
        $firstEvidence,
        $secondEvidence,
    );

    expect($totals)->toMatchArray(['applied' => 1, 'unchanged' => 0, 'stale' => 0, 'failed' => 0])
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))
        ->toBe($expectedEvidence)
        ->and($item->fresh()->result['guidance_evidence'])->toBe($expectedEvidence)
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
            'display_name' => 'Huile d’olive',
            'saponification_name' => $ingredient->saponification_name === null ? null : 'Savon d’huile',
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
            'research' => 'ingredient-guidance-research-v2',
        ],
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}

/**
 * @param  list<array{locale: string, info_markdown: string, display_name?: string, saponification_name?: string|null}>  $translations
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
        'translations' => collect($translations)->map(function (array $translation) use ($ingredient): array {
            $locale = (string) ($translation['locale'] ?? '');

            return [
                'locale' => $locale,
                'display_name' => is_string($translation['display_name'] ?? null)
                    ? $translation['display_name']
                    : "Localized {$locale}",
                'saponification_name' => array_key_exists('saponification_name', $translation)
                    ? $translation['saponification_name']
                    : ($ingredient->saponification_name === null ? null : 'Localized soap name'),
                'info_markdown' => (string) ($translation['info_markdown'] ?? ''),
            ];
        })->values()->all(),
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
            'research' => 'ingredient-guidance-research-v2',
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
