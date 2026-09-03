<?php

use App\Actions\IngredientEnrichment\ApplyApprovedIngredientGuidanceRefresh;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientTranslationOrigin;
use App\Enums\OwnerType;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ViewIngredientEnrichmentBatch;
use App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientTranslation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\IngredientEnrichment\IngredientEnrichmentReviewPresenter;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceChangePlanner;
use App\Services\IngredientEnrichment\IngredientGuidanceEvidencePolicy;
use App\Services\IngredientTranslationSourceFingerprint;
use Database\Seeders\SupportedLocaleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    config()->set('ingredient-enrichment.direct_ai.enabled', true);
    config()->set('ingredient-enrichment.openai.api_key', 'test-only');
    Bus::fake();
});

it('offers a guidance-only bulk action and queues a guidance batch', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredients = Ingredient::factory()->count(2)->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    $this->actingAs($admin);

    Livewire::test(ListIngredients::class)
        ->loadTable()
        ->assertActionExists(TestAction::make('runGuidanceRefresh')->table()->bulk())
        ->selectTableRecords($ingredients->modelKeys())
        ->mountAction(TestAction::make('runGuidanceRefresh')->table()->bulk())
        ->assertMountedActionModalSee('Identity fields are not included.')
        ->callMountedAction();

    $batch = IngredientEnrichmentBatch::query()->latest('id')->firstOrFail();

    expect($batch->mode)->toBe(IngredientEnrichmentBatchMode::GuidanceRefresh)
        ->and($batch->items()->count())->toBe(2);
});

it('exposes update translations in the guidance section for saved English guidance', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => '## Overview\n\nSECRET CANONICAL BODY.',
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionExists(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'))
        ->assertActionVisible(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'))
        ->mountAction(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'))
        ->assertMountedActionModalSee('Missing locales:')
        ->assertMountedActionModalDontSee('SECRET CANONICAL BODY')
        ->callMountedAction();

    $batch = IngredientEnrichmentBatch::query()->latest('id')->firstOrFail();

    expect($batch->mode)->toBe(IngredientEnrichmentBatchMode::GuidanceLocalization)
        ->and($batch->model)->toBe('gpt-5.6-luna')
        ->and($batch->reasoning_effort)->toBe('xhigh')
        ->and($batch->items()->whereBelongsTo($ingredient)->exists())->toBeTrue();

    $currentIngredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => '## Overview\n\nEnglish guidance.',
    ]);

    Livewire::test(EditIngredient::class, ['record' => $currentIngredient->public_id])
        ->assertActionVisible(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'));
});

it('hides update translations without saved English and keeps workspace ingredients outside the admin editor', function (): void {
    $admin = User::factory()->admin()->create();
    $withoutEnglish = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => null,
    ]);
    $workspace = Workspace::factory()->for($admin, 'owner')->create();
    $workspaceIngredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'info_markdown' => '## Overview\n\nWorkspace guidance.',
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $withoutEnglish->public_id])
        ->assertActionDoesNotExist(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'));

    expect(fn () => Livewire::test(EditIngredient::class, ['record' => $workspaceIngredient->public_id]))
        ->toThrow(ModelNotFoundException::class);
});

it('offers update translations for a current AI locale with missing localized names', function (): void {
    config()->set('interface-translations.catalogue_locales', ['fr']);
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'display_name' => 'Palm Kernel Oil',
        'saponification_name' => 'Palm Kernel Oil Soap',
        'info_markdown' => "## Overview\n\nEnglish guidance.\n\n## Formulation use\n\nFormulation guidance.",
    ]);
    $ingredient->translations()->create([
        'locale' => 'fr',
        'display_name' => null,
        'saponification_name' => null,
        'info_markdown' => "## Vue d’ensemble\n\nConseils.\n\n## Utilisation en formulation\n\nUtilisation.",
        'source_fingerprint' => app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient),
        'origin' => IngredientTranslationOrigin::AiGenerated,
    ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionVisible(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'))
        ->mountAction(TestAction::make('updateTranslations')->schemaComponent('guidance-media::section'))
        ->assertMountedActionModalSee('Incomplete AI locales: fr');
});

it('uses focused guidance fields for guidance batch review actions', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => 'Existing guidance',
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Ready,
            'result' => [
                'info_markdown' => '## Overview\n\nGenerated guidance.',
                'translations' => [[
                    'locale' => 'fr',
                    'info_markdown' => '## Vue d’ensemble\n\nConseils générés.',
                ]],
            ],
            'plan' => [
                'decisions' => [[
                    'field' => 'proposal.info_markdown',
                    'decision' => 'replace',
                    'current' => 'Existing guidance',
                    'proposed' => 'Generated guidance',
                ]],
            ],
        ]);
    $this->actingAs($admin);

    expect(collect(app(IngredientEnrichmentReviewPresenter::class)->rows($item))->pluck('path')->all())
        ->toBe(['proposal.info_markdown']);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('editProposal')->table($item))
        ->assertFormFieldExists('info_markdown')
        ->assertFormFieldExists('translations')
        ->assertFormFieldDoesNotExist('display_name')
        ->assertMountedActionModalDontSee('Identity and guidance');

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('approve')->table($item))
        ->assertMountedActionModalSee('Identity, taxonomy, identifiers, names, and declarations are not included.')
        ->assertFormFieldDoesNotExist('replace_fields');
});

it('shows the ingredient name alongside its catalog code in enrichment items', function (): void {
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Apricot kernel oil',
        'catalog_key' => 'OB13',
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create(['catalog_key' => 'OB13']);

    expect(app(IngredientEnrichmentReviewPresenter::class)->subjectLabel($item))
        ->toBe('Apricot kernel oil (OB13)');
});

it('renders guidance evidence as translated read-only review evidence', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => 'Existing guidance',
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
    ]);
    $evidence = [
        [
            'source_name' => 'Safe source',
            'source_url' => 'https://example.test/safe',
            'summary' => 'Safe evidence.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-28T00:00:00+00:00',
        ],
        [
            'source_name' => 'Unsafe source',
            'source_url' => 'javascript:alert(1)',
            'summary' => 'Unsafe evidence.',
            'source_tier' => 'editorial',
            'retrieved_at' => '2026-08-28T00:00:00+00:00',
        ],
    ];
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Ready,
            'result' => ['guidance_evidence' => $evidence],
            'plan' => [
                'decisions' => [[
                    'field' => 'guidance.evidence',
                    'decision' => 'replace',
                    'current' => [],
                    'proposed' => $evidence,
                ]],
            ],
        ]);

    $rows = app(IngredientEnrichmentReviewPresenter::class)->rows($item);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'path' => 'guidance.evidence',
            'label' => 'Evidence',
            'decision' => 'replace',
        ])
        ->and($rows[0]['evidence'])->toHaveCount(1)
        ->and($rows[0]['evidence'][0])->toMatchArray([
            'title' => 'Safe source',
            'url' => 'https://example.test/safe',
        ]);

    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make(ViewAction::class)->table($item))
        ->assertMountedActionModalSee('Evidence')
        ->assertMountedActionModalSee('Safe source')
        ->assertMountedActionModalDontSee('href="javascript:alert(1)"');

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('editProposal')->table($item))
        ->assertFormFieldDoesNotExist('guidance_evidence')
        ->assertFormFieldDoesNotExist('evidence');
});

it('reviews and applies an approved stale-locale revalidation through the guidance UI', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $french = "## Vue d’ensemble\n\nConseils français existants.\n\n## Utilisation en formulation\n\nConseils de formulation français existants.";
    $oldEvidence = [[
        'source_name' => 'Previous guidance source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Previously reviewed evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $newEvidence = [[
        'source_name' => 'Stale inherited guidance source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Previously reviewed evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ], [
        'source_name' => 'Stale distinct guidance source',
        'source_url' => 'https://example.test/stale-distinct',
        'summary' => 'Stale distinct evidence from the locale result.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'display_name' => 'Olive oil',
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $oldEvidence]]],
    ]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'saponification_name' => null,
        'info_markdown' => $french,
        'source_fingerprint' => 'stale-locale-fingerprint',
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = [
        'format' => 'soapkraft-ingredient-guidance-refresh-result',
        'schema_version' => 1,
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization->value,
        'subject_public_id' => (string) $ingredient->public_id,
        'source_fingerprint' => $sourceFingerprint,
        'info_markdown' => $english,
        'translations' => [[
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'saponification_name' => null,
            'info_markdown' => $french,
        ]],
        'guidance_evidence' => $newEvidence,
        'prompt_versions' => [
            'guidance' => 'ingredient-guidance-v1',
            'localization' => 'ingredient-guidance-localization-v1',
        ],
        'warnings' => [],
        'unresolved_questions' => [],
    ];
    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        $result,
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Ready,
            'source_fingerprint' => $sourceFingerprint,
            'result' => $result,
            'plan' => $plan,
        ]);
    $this->actingAs($admin);

    $rows = app(IngredientEnrichmentReviewPresenter::class)->rows($item);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'path' => 'proposal.translations.fr.info_markdown',
            'decision' => 'revalidate',
        ])
        ->and(collect($rows)->firstWhere('path', 'guidance.evidence'))->toBeNull();

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make(ViewAction::class)->table($item))
        ->assertMountedActionModalDontSee('Stale inherited guidance source')
        ->assertMountedActionModalDontSee('Stale distinct guidance source')
        ->assertMountedActionModalSee('Conseils français existants.')
        ->assertMountedActionModalDontSee('INCI name')
        ->assertMountedActionModalDontSee('Identity and guidance')
        ->unmountAction()
        ->mountAction(TestAction::make('editProposal')->table($item))
        ->assertFormFieldExists('translations')
        ->assertFormFieldDoesNotExist('display_name')
        ->assertFormFieldDoesNotExist('guidance_evidence')
        ->assertFormFieldDoesNotExist('evidence')
        ->unmountAction()
        ->mountAction(TestAction::make('approve')->table($item))
        ->assertMountedActionModalSee('Identity, taxonomy, identifiers, names, and declarations are not included.')
        ->assertFormFieldDoesNotExist('replace_fields')
        ->callMountedAction();

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Approved);

    Livewire::test(ViewIngredientEnrichmentBatch::class, ['record' => $batch->public_id])
        ->callAction('applyApproved')
        ->assertNotified();

    $item = $item->fresh();
    $batch = $batch->fresh();
    $expectedEvidence = app(IngredientGuidanceEvidencePolicy::class)->normalizePersisted($oldEvidence);
    expect($item->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($batch->status)->toBe(IngredientEnrichmentBatchStatus::Applied)
        ->and($batch->items()->where('status', IngredientEnrichmentItemStatus::Approved)->count())->toBe(0)
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))
        ->toBe($expectedEvidence)
        ->and($item->result['guidance_evidence'])->toBe($expectedEvidence)
        ->and(data_get($item->validation_report, 'normalized.guidance_evidence'))->toBe($expectedEvidence)
        ->and(data_get($item->plan, 'effective.guidance_evidence'))->toBe($expectedEvidence)
        ->and(collect($item->plan['decisions'] ?? [])->firstWhere('field', 'guidance.evidence'))->toBeNull();
});

it('quarantines an all-malformed historical bank during localization apply', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $malformedEvidence = [
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
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $malformedEvidence]]],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'requested_by_user_id' => $admin->id,
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'result' => [
                'format' => 'soapkraft-ingredient-guidance-refresh-result',
                'schema_version' => 1,
                'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization->value,
                'subject_public_id' => (string) $ingredient->public_id,
                'source_fingerprint' => $sourceFingerprint,
                'info_markdown' => $english,
                'translations' => [],
                'guidance_evidence' => [],
                'prompt_versions' => [
                    'guidance' => 'ingredient-guidance-v1',
                    'localization' => 'ingredient-guidance-localization-v1',
                ],
                'warnings' => [],
                'unresolved_questions' => [],
            ],
        ]);

    $totals = app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $batch);
    $applied = $item->fresh();

    expect($totals['applied'])->toBe(1)
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))->toBe([])
        ->and($applied->result['guidance_evidence'])->toBe([])
        ->and(data_get($applied->validation_report, 'normalized.guidance_evidence'))->toBe([])
        ->and(data_get($applied->plan, 'effective.guidance_evidence'))->toBe([])
        ->and(data_get(collect($applied->plan['decisions'] ?? [])->firstWhere('field', 'guidance.evidence'), 'proposed', []))
        ->toBe([]);
});

it('derives a legacy refresh contribution as a logical delta from its snapshot', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $currentEvidence = [[
        'source_name' => 'Current guidance source',
        'source_url' => 'https://example.test/shared',
        'summary' => 'Shared evidence from the current bank.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-20T00:00:00+00:00',
    ]];
    $staleInheritedEvidence = [[
        'source_name' => 'Stale inherited guidance source',
        'source_url' => 'https://example.test/shared',
        'summary' => 'Shared evidence from the current bank.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-21T00:00:00+00:00',
    ]];
    $distinctEvidence = [[
        'source_name' => 'New legacy guidance source',
        'source_url' => 'https://example.test/legacy-new',
        'summary' => 'A new row from a legacy refresh result.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-21T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $currentEvidence]]],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'requested_by_user_id' => $admin->id,
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'snapshot' => ['prior_guidance_evidence' => $currentEvidence],
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'result' => [
                'format' => 'soapkraft-ingredient-guidance-refresh-result',
                'schema_version' => 1,
                'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh->value,
                'subject_public_id' => (string) $ingredient->public_id,
                'source_fingerprint' => $sourceFingerprint,
                'info_markdown' => $english,
                'translations' => [],
                'guidance_evidence' => [...$staleInheritedEvidence, ...$distinctEvidence],
                'prompt_versions' => [
                    'research' => 'ingredient-guidance-research-v2',
                    'guidance' => 'ingredient-guidance-v3',
                    'localization' => 'ingredient-guidance-localization-v1',
                ],
                'warnings' => [],
                'unresolved_questions' => [],
            ],
        ]);

    $totals = app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $batch);
    $expectedEvidence = app(IngredientGuidanceEvidencePolicy::class)->reconcilePersisted(
        $currentEvidence,
        $distinctEvidence,
    );

    expect($totals['applied'])->toBe(1)
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))->toBe($expectedEvidence)
        ->and($item->fresh()->result['guidance_evidence'])->toBe($expectedEvidence);
});

it('retains prior evidence when an approved fresh guidance result has no accepted sources', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $oldEvidence = [[
        'source_name' => 'Previous guidance source',
        'source_url' => 'https://example.test/previous',
        'summary' => 'Previously reviewed evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $oldEvidence]]],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'requested_by_user_id' => $admin->id,
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'result' => [
                'format' => 'soapkraft-ingredient-guidance-refresh-result',
                'schema_version' => 1,
                'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh->value,
                'subject_public_id' => (string) $ingredient->public_id,
                'source_fingerprint' => $sourceFingerprint,
                'info_markdown' => $english,
                'translations' => [],
                'guidance_evidence' => [],
                'prompt_versions' => [
                    'research' => 'ingredient-guidance-research-v2',
                    'guidance' => 'ingredient-guidance-v3',
                    'localization' => 'ingredient-guidance-localization-v1',
                ],
                'warnings' => [],
                'unresolved_questions' => [],
            ],
        ]);

    $totals = app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $batch);
    $expectedEvidence = app(IngredientGuidanceEvidencePolicy::class)->normalizePersisted($oldEvidence);

    expect($totals['applied'])->toBe(1)
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))->toBe($expectedEvidence)
        ->and($item->fresh()->result['guidance_evidence'])->toBe($expectedEvidence)
        ->and(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.research_prompt_version'))
        ->toBe('ingredient-guidance-research-v2');
});

it('preserves evidence applied by an earlier refresh when applying a stale concurrent result', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $priorEvidence = [[
        'source_name' => 'Prior guidance source',
        'source_url' => 'https://example.test/prior',
        'summary' => 'Previously reviewed evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
        'claim_type' => 'formulation_role',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $firstFreshEvidence = [[
        'source_name' => 'First fresh guidance source',
        'source_url' => 'https://example.test/first',
        'summary' => 'First fresh evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-09-01T00:00:00+00:00',
        'claim_type' => 'formulation_role',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $secondFreshEvidence = [[
        'source_name' => 'Second fresh guidance source',
        'source_url' => 'https://example.test/second',
        'summary' => 'Second fresh evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-09-02T00:00:00+00:00',
        'claim_type' => 'formulation_role',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $priorEvidence]]],
    ]);
    $createBatch = fn (): IngredientEnrichmentBatch => IngredientEnrichmentBatch::factory()->create([
        'requested_by_user_id' => $admin->id,
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $firstBatch = $createBatch();
    $secondBatch = $createBatch();
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = function (array $evidence) use ($ingredient, $sourceFingerprint, $english): array {
        return [
            'format' => 'soapkraft-ingredient-guidance-refresh-result',
            'schema_version' => 1,
            'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh->value,
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => $sourceFingerprint,
            'info_markdown' => $english,
            'translations' => [],
            'guidance_evidence' => $evidence,
            'prompt_versions' => [
                'research' => 'ingredient-guidance-research-v2',
                'guidance' => 'ingredient-guidance-v3',
                'localization' => 'ingredient-guidance-localization-v1',
            ],
            'warnings' => [],
            'unresolved_questions' => [],
        ];
    };
    $firstItem = IngredientEnrichmentBatchItem::factory()
        ->for($firstBatch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'result' => $result($firstFreshEvidence),
        ]);
    $secondItem = IngredientEnrichmentBatchItem::factory()
        ->for($secondBatch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'result' => $result($secondFreshEvidence),
        ]);

    $firstTotals = app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $firstBatch);
    $secondTotals = app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $secondBatch);

    $persistedUrls = collect(data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence'))
        ->pluck('source_url')->all();
    expect($firstTotals['applied'])->toBe(1)
        ->and($secondTotals['applied'])->toBe(1)
        ->and($persistedUrls)->toBe([
            'https://example.test/prior',
            'https://example.test/first',
            'https://example.test/second',
        ])
        ->and($firstItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and($secondItem->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Applied)
        ->and(collect($firstItem->fresh()->result['guidance_evidence'])->pluck('source_url')->all())
        ->toBe(['https://example.test/prior', 'https://example.test/first'])
        ->and(collect($secondItem->fresh()->result['guidance_evidence'])->pluck('source_url')->all())
        ->toBe($persistedUrls);
});

it('applies only the fresh research contribution and aligns all applied audit evidence', function (): void {
    $admin = User::factory()->admin()->create();
    $english = "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.";
    $priorEvidence = [[
        'source_name' => 'Stale inherited source',
        'source_url' => 'https://example.test/logical-row',
        'summary' => 'The same logical evidence row.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
        'claim_type' => 'formulation_role',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $firstFreshEvidence = [[
        ...$priorEvidence[0],
        'source_name' => 'First refresh source',
        'retrieved_at' => '2026-09-01T00:00:00+00:00',
    ]];
    $secondFreshEvidence = [[
        'source_name' => 'Second refresh source',
        'source_url' => 'https://example.test/distinct-row',
        'summary' => 'A distinct second refresh row.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-09-02T00:00:00+00:00',
        'claim_type' => 'formulation_role',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => $english,
        'source_data' => ['enrichment' => ['guidance' => ['evidence' => $priorEvidence]]],
    ]);
    $createBatch = fn (): IngredientEnrichmentBatch => IngredientEnrichmentBatch::factory()->create([
        'requested_by_user_id' => $admin->id,
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $firstBatch = $createBatch();
    $secondBatch = $createBatch();
    $sourceFingerprint = app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient);
    $result = function (array $evidence) use ($ingredient, $sourceFingerprint, $english): array {
        return [
            'format' => 'soapkraft-ingredient-guidance-refresh-result',
            'schema_version' => 1,
            'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh->value,
            'subject_public_id' => (string) $ingredient->public_id,
            'source_fingerprint' => $sourceFingerprint,
            'info_markdown' => $english,
            'translations' => [],
            'guidance_evidence' => $evidence,
            'prompt_versions' => [
                'research' => 'ingredient-guidance-research-v2',
                'guidance' => 'ingredient-guidance-v3',
                'localization' => 'ingredient-guidance-localization-v1',
            ],
            'warnings' => [],
            'unresolved_questions' => [],
        ];
    };
    $firstItem = IngredientEnrichmentBatchItem::factory()
        ->for($firstBatch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'research_stages' => [
                'ai_guidance_research' => [
                    'status' => 'completed',
                    'data' => ['guidance_evidence' => $firstFreshEvidence],
                ],
            ],
            'result' => $result($firstFreshEvidence),
        ]);
    $secondItem = IngredientEnrichmentBatchItem::factory()
        ->for($secondBatch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Approved,
            'source_fingerprint' => $sourceFingerprint,
            'approved_by_user_id' => $admin->id,
            'approved_at' => now(),
            'research_stages' => [
                'ai_guidance_research' => [
                    'status' => 'completed',
                    'data' => ['guidance_evidence' => $secondFreshEvidence],
                ],
            ],
            'result' => $result([...$priorEvidence, ...$secondFreshEvidence]),
        ]);

    app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $firstBatch);
    app(ApplyApprovedIngredientGuidanceRefresh::class)->handle($admin, $secondBatch);

    $persistedEvidence = data_get($ingredient->fresh()->source_data, 'enrichment.guidance.evidence');
    $expectedEvidence = app(IngredientGuidanceEvidencePolicy::class)->reconcilePersisted(
        $firstFreshEvidence,
        $secondFreshEvidence,
    );
    $secondApplied = $secondItem->fresh();
    $evidenceDecision = collect($secondApplied->plan['decisions'] ?? [])
        ->first(fn (mixed $decision): bool => is_array($decision) && ($decision['field'] ?? null) === 'guidance.evidence');

    expect($persistedEvidence)->toBe($expectedEvidence)
        ->and($secondApplied->result['guidance_evidence'])->toBe($expectedEvidence)
        ->and(data_get($secondApplied->validation_report, 'normalized.guidance_evidence'))->toBe($expectedEvidence)
        ->and(data_get($secondApplied->plan, 'effective.guidance_evidence'))->toBe($expectedEvidence)
        ->and($evidenceDecision['proposed'] ?? null)->toBe($expectedEvidence)
        ->and($persistedEvidence[0]['source_name'])->toBe('First refresh source')
        ->and($firstItem->fresh()->result['guidance_evidence'][0]['source_name'])->toBe('First refresh source');
});

it('keeps English guidance read-only in localization-only review batches', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => "## Overview\n\nExisting English guidance.\n\n## Formulation use\n\nExisting formulation guidance.",
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()
        ->for($batch, 'batch')
        ->for($ingredient)
        ->create([
            'status' => IngredientEnrichmentItemStatus::Ready,
            'result' => [
                'info_markdown' => $ingredient->info_markdown,
                'translations' => [[
                    'locale' => 'fr',
                    'info_markdown' => "## Vue d’ensemble\n\nConseils existants.\n\n## Utilisation en formulation\n\nConseils de formulation existants.",
                ]],
            ],
        ]);
    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('editProposal')->table($item))
        ->assertFormFieldDisabled('info_markdown');
});
