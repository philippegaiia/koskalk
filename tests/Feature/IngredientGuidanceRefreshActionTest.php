<?php

use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ViewIngredientEnrichmentBatch;
use App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientTranslation;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentReviewPresenter;
use App\Services\IngredientTranslationSourceFingerprint;
use Database\Seeders\SupportedLocaleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
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

it('exposes outdated translation regeneration only when a locale is stale', function (): void {
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
        'info_markdown' => '## Overview\n\nEnglish guidance.',
    ]);
    IngredientTranslation::factory()
        ->for($ingredient)
        ->create([
            'locale' => 'fr',
            'source_fingerprint' => null,
            'info_markdown' => '## Aperçu\n\nConseils existants.',
        ]);
    $this->actingAs($admin);

    Livewire::test(EditIngredient::class, ['record' => $ingredient->public_id])
        ->assertActionVisible('regenerateOutdatedTranslations')
        ->mountAction('regenerateOutdatedTranslations')
        ->assertMountedActionModalSee('fr')
        ->callMountedAction();

    $batch = IngredientEnrichmentBatch::query()->latest('id')->firstOrFail();

    expect($batch->mode)->toBe(IngredientEnrichmentBatchMode::GuidanceLocalization)
        ->and($batch->items()->whereBelongsTo($ingredient)->exists())->toBeTrue();

    $currentIngredient = Ingredient::factory()->create([
        'owner_type' => null,
        'owner_id' => null,
    ]);
    IngredientTranslation::factory()
        ->for($currentIngredient)
        ->create([
            'locale' => 'fr',
            'source_fingerprint' => app(IngredientTranslationSourceFingerprint::class)
                ->forIngredient($currentIngredient),
        ]);

    Livewire::test(EditIngredient::class, ['record' => $currentIngredient->public_id])
        ->assertActionHidden('regenerateOutdatedTranslations');
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
