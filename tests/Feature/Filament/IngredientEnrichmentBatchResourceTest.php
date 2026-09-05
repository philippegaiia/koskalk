<?php

use App\Actions\IngredientEnrichment\DeleteIngredientEnrichmentBatch;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ListIngredientEnrichmentBatches;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ViewIngredientEnrichmentBatch;
use App\Filament\Resources\IngredientEnrichmentBatches\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentReviewPresenter;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('offers a new batch shortcut from the batch index', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ListIngredientEnrichmentBatches::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'New batch')
        ->assertActionHasUrl('create', IngredientResource::getUrl('index'));
});

it('shows whether a batch started with fresh research', function (): void {
    $admin = User::factory()->admin()->create();
    $freshBatch = IngredientEnrichmentBatch::factory()->create(['fresh_research' => true]);
    $reusedBatch = IngredientEnrichmentBatch::factory()->create(['fresh_research' => false]);
    $this->actingAs($admin);

    Livewire::test(ViewIngredientEnrichmentBatch::class, ['record' => $freshBatch->public_id])
        ->assertSchemaComponentStateSet('fresh_research', true)
        ->assertSee('Fresh research');

    Livewire::test(ViewIngredientEnrichmentBatch::class, ['record' => $reusedBatch->public_id])
        ->assertSchemaComponentStateSet('fresh_research', false);
});

it('presents current and proposed values with field-level evidence', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'plan' => [
            'decisions' => [[
                'field' => 'proposal.inci_name',
                'decision' => 'new',
                'current' => null,
                'proposed' => 'PRUNUS ARMENIACA KERNEL OIL',
            ]],
        ],
        'result' => [
            'field_confidence' => [[
                'field' => 'proposal.inci_name',
                'confidence' => 'verified',
            ]],
            'evidence' => [[
                'field' => 'proposal.inci_name',
                'source_name' => 'EU Common Ingredient Glossary',
                'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32025D1175',
                'source_tier' => 'official',
                'confidence' => 'verified',
                'source_version' => '32025D1175',
                'source_updated_at' => '2025-06-16',
                'retrieved_at' => '2026-08-14T12:00:00+00:00',
            ]],
            'regulatory_findings' => [],
        ],
    ]);

    $rows = app(IngredientEnrichmentReviewPresenter::class)->rows($item);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['path'])->toBe('proposal.inci_name')
        ->and($rows[0]['label'])->toBe('INCI name')
        ->and($rows[0]['current'])->toBeNull()
        ->and($rows[0]['proposed'])->toBe('PRUNUS ARMENIACA KERNEL OIL')
        ->and($rows[0]['decision'])->toBe('new')
        ->and($rows[0]['confidence'])->toBe('verified')
        ->and($rows[0]['evidence'][0])->toMatchArray([
            'title' => 'EU Common Ingredient Glossary',
            'url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32025D1175',
            'source_tier' => 'official',
            'version' => '32025D1175',
            'retrieved_at' => '2026-08-14T12:00:00+00:00',
        ])
        ->and($rows[0]['conflict_explanation'])->toBeNull();

    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make(ViewAction::class)->table($item))
        ->assertMountedActionModalSee('PRUNUS ARMENIACA KERNEL OIL')
        ->assertMountedActionModalSee('Verified')
        ->assertMountedActionModalSee('EU Common Ingredient Glossary');

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('editProposal')->table($item))
        ->assertMountedActionModalSee('Identity and guidance')
        ->assertFormFieldExists(
            'info_markdown',
            checkFieldUsing: fn (MarkdownEditor $field): bool => ! $field->isRequired(),
        );
});

it('shows only genuine replacement conflicts when approving an enrichment item', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'plan' => [
            'effective' => [
                'canonical' => [
                    'display_name' => 'Cocoa Butter',
                    'inci_name' => 'Theobroma Cacao Seed Butter',
                ],
                'cosing_functions' => [
                    ['key' => 'skin_conditioning'],
                    ['key' => 'skin_protecting'],
                    ['key' => 'emollient'],
                ],
            ],
            'decisions' => [
                [
                    'field' => 'proposal.display_name',
                    'decision' => 'preserved',
                    'current' => 'Cocoa Butter',
                    'proposed' => 'Cacao Butter',
                ],
                [
                    'field' => 'proposal.inci_name',
                    'decision' => 'new',
                    'current' => null,
                    'proposed' => 'Theobroma Cacao Seed Butter',
                ],
                [
                    'field' => 'proposal.cosing_functions',
                    'decision' => 'new',
                    'current' => [
                        ['key' => 'skin_conditioning'],
                        ['key' => 'skin_protecting'],
                    ],
                    'proposed' => [
                        ['key' => 'emollient'],
                    ],
                ],
            ],
        ],
    ]);
    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('approve')->table($item))
        ->assertMountedActionModalSee('Only fields with existing data that would be overwritten are listed.')
        ->assertMountedActionModalSee('Replace English display name')
        ->assertMountedActionModalSee('Current: Cocoa Butter')
        ->assertMountedActionModalSee('Proposed: Cacao Butter')
        ->assertMountedActionModalSee('Replace COSING functions')
        ->assertMountedActionModalSee('skin_conditioning')
        ->assertMountedActionModalSee('emollient')
        ->assertMountedActionModalDontSee('Replace INCI name')
        ->assertFormFieldExists(
            'replace_fields',
            checkFieldUsing: fn (CheckboxList $field): bool => $field->getOptions() === [
                'display_name' => 'Replace English display name',
                'cosing_functions' => 'Replace COSING functions',
            ],
        );
});

it('confirms approval without replacement choices when every proposed value is additive', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'plan' => [
            'effective' => [
                'canonical' => [
                    'display_name' => 'Cocoa Butter',
                    'inci_name' => 'Theobroma Cacao Seed Butter',
                ],
                'cosing_functions' => [
                    ['key' => 'emollient'],
                ],
            ],
            'decisions' => [
                [
                    'field' => 'proposal.display_name',
                    'decision' => 'unchanged',
                    'current' => 'Cocoa Butter',
                    'proposed' => 'Cocoa Butter',
                ],
                [
                    'field' => 'proposal.inci_name',
                    'decision' => 'new',
                    'current' => null,
                    'proposed' => 'Theobroma Cacao Seed Butter',
                ],
                [
                    'field' => 'proposal.cosing_functions',
                    'decision' => 'new',
                    'current' => [],
                    'proposed' => [
                        ['key' => 'emollient'],
                    ],
                ],
            ],
        ],
    ]);
    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->mountAction(TestAction::make('approve')->table($item))
        ->assertMountedActionModalSee('No existing ingredient values will be overwritten.')
        ->assertMountedActionModalDontSee('Replace English display name')
        ->assertMountedActionModalDontSee('Replace INCI name')
        ->assertMountedActionModalDontSee('Replace COSING functions');
});

it('lets an admin list and view durable research batches and their items', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
        'total_count' => 1,
        'ready_count' => 1,
    ]);
    IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'catalog_key' => 'apricot_oil',
    ]);
    $this->actingAs($admin);

    Livewire::test(ListIngredientEnrichmentBatches::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$batch]);

    Livewire::test(ViewIngredientEnrichmentBatch::class, ['record' => $batch->public_id])
        ->assertOk()
        ->assertSee('apricot_oil')
        ->assertDontSee('Approve safe batch')
        ->assertSee('Retry gaps')
        ->assertSee('Apply approved');

    expect(IngredientEnrichmentBatchResource::getUrl('view', ['record' => $batch]))
        ->toContain($batch->public_id);
});

it('denies the batch resource to non admins', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $batch = IngredientEnrichmentBatch::factory()->create();

    $this->actingAs($user)->get(IngredientEnrichmentBatchResource::getUrl('view', ['record' => $batch], panel: 'admin'))
        ->assertForbidden();
});

it('deletes a terminal batch with its items and private artifacts from the list', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create();
    $artifact = "ingredient-enrichment/batches/{$batch->public_id}/result.json";
    $sharedExport = 'ingredient-enrichment/platform-ingredients.jsonl';
    Storage::disk('local')->put($artifact, '{}');
    Storage::disk('local')->put($sharedExport, '{}');
    $deletingEvent = 'eloquent.deleting: '.IngredientEnrichmentBatch::class;
    $artifactWasPresentWhenDatabaseDeleteStarted = false;
    Event::listen($deletingEvent, function (IngredientEnrichmentBatch $deletingBatch) use (&$artifactWasPresentWhenDatabaseDeleteStarted): void {
        $artifactWasPresentWhenDatabaseDeleteStarted = Storage::disk('local')->exists(
            "ingredient-enrichment/batches/{$deletingBatch->public_id}/result.json",
        );
    });
    $this->actingAs($admin);

    try {
        Livewire::test(ListIngredientEnrichmentBatches::class)
            ->loadTable()
            ->callAction(TestAction::make('delete')->table($batch));
    } finally {
        Event::forget($deletingEvent);
    }

    $this->assertModelMissing($batch);
    $this->assertModelMissing($item);
    expect($artifactWasPresentWhenDatabaseDeleteStarted)->toBeTrue();
    Storage::disk('local')->assertMissing($artifact);
    Storage::disk('local')->assertExists($sharedExport);
});

it('preserves a terminal batch and its private artifacts when database deletion fails', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $artifact = "ingredient-enrichment/batches/{$batch->public_id}/result.json";
    Storage::disk('local')->put($artifact, '{}');
    $deletingEvent = 'eloquent.deleting: '.IngredientEnrichmentBatch::class;
    Event::listen($deletingEvent, function (): never {
        throw new RuntimeException('simulated database deletion failure');
    });

    try {
        expect(fn () => app(DeleteIngredientEnrichmentBatch::class)->handle($admin, $batch))
            ->toThrow(RuntimeException::class, 'simulated database deletion failure');
    } finally {
        Event::forget($deletingEvent);
    }

    $this->assertModelExists($batch);
    Storage::disk('local')->assertExists($artifact);
});

it('does not offer batch deletion while research is active', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
    ]);
    $this->actingAs($admin);

    Livewire::test(ListIngredientEnrichmentBatches::class)
        ->loadTable()
        ->assertActionHidden(TestAction::make('delete')->table($batch));

    $this->assertModelExists($batch);
});

it('rejects direct deletion of an active batch and preserves its artifacts', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
    ]);
    $artifact = "ingredient-enrichment/batches/{$batch->public_id}/result.json";
    Storage::disk('local')->put($artifact, '{}');

    expect(fn () => app(DeleteIngredientEnrichmentBatch::class)->handle($admin, $batch))
        ->toThrow(ValidationException::class);

    $this->assertModelExists($batch);
    Storage::disk('local')->assertExists($artifact);
});

it('rejects batch deletion by a non administrator', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);

    expect(fn () => app(DeleteIngredientEnrichmentBatch::class)->handle($user, $batch))
        ->toThrow(AuthorizationException::class);

    $this->assertModelExists($batch);
});

it('shows safe failure diagnostics when reviewing a failed item', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::PartiallyFailed,
        'total_count' => 1,
        'failed_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Failed,
        'catalog_key' => 'apricot_oil',
        'failure_code' => 'provider_http_400_unsupported_parameter',
        'failure_message' => 'Provider failed (HTTP 400, request req_123).',
    ]);
    $this->actingAs($admin);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientEnrichmentBatch::class,
    ])
        ->loadTable()
        ->assertSee('provider_http_400_unsupported_parameter')
        ->assertSee('Provider failed (HTTP 400, request req_123).');
});
