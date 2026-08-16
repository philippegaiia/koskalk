<?php

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Filament\Resources\IngredientIntakeBatches\IngredientIntakeBatchResource;
use App\Filament\Resources\IngredientIntakeBatches\Pages\CreateIngredientIntakeBatch;
use App\Filament\Resources\IngredientIntakeBatches\Pages\ListIngredientIntakeBatches;
use App\Filament\Resources\IngredientIntakeBatches\Pages\ViewIngredientIntakeBatch;
use App\Filament\Resources\IngredientIntakeBatches\RelationManagers\ItemsRelationManager;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('offers intake creation from the admin index', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(ListIngredientIntakeBatches::class)
        ->assertActionExists('create')
        ->assertActionHasLabel('create', 'New intake batch')
        ->assertActionHasUrl('create', IngredientIntakeBatchResource::getUrl('create'));
});

it('creates a paste intake batch with one or more identities', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateIngredientIntakeBatch::class)
        ->fillForm([
            'name' => 'Initial oils',
            'input_method' => 'paste',
            'pasted_input' => "current_name,inci_name\nCoconut Oil,\nArgan Oil,Argania Spinosa Kernel Oil",
            'family_hint' => 'lipids',
            'allow_gap_research' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = IngredientIntakeBatch::query()->firstOrFail();

    expect($batch->name)->toBe('Initial oils')
        ->and($batch->status)->toBe(IngredientIntakeBatchStatus::Draft)
        ->and($batch->items)->toHaveCount(2)
        ->and($batch->items->first()->original_current_name)->toBe('Coconut Oil')
        ->and($batch->items->last()->original_inci_name)->toBe('Argania Spinosa Kernel Oil');
});

it('shows editable draft rows and a start-research action', function (): void {
    Bus::fake();
    $admin = User::factory()->admin()->create();
    $batch = IngredientIntakeBatch::factory()->create([
        'status' => IngredientIntakeBatchStatus::Draft,
        'total_count' => 1,
    ]);
    $item = IngredientIntakeItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientIntakeItemStatus::Draft,
        'original_current_name' => 'Coconut Oil',
        'normalized_current_name' => 'coconut oil',
    ]);
    $this->actingAs($admin);

    Livewire::test(ViewIngredientIntakeBatch::class, ['record' => $batch->public_id])
        ->assertOk()
        ->assertSee('Coconut Oil')
        ->assertActionExists('startResearch');

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewIngredientIntakeBatch::class,
    ])
        ->loadTable()
        ->assertCanSeeTableRecords([$item])
        ->assertActionVisible(TestAction::make('editRow')->table($item))
        ->assertActionVisible(TestAction::make('removeRow')->table($item));
});

it('denies intake batches to non administrators', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $batch = IngredientIntakeBatch::factory()->create();

    $this->actingAs($user)
        ->get(IngredientIntakeBatchResource::getUrl('view', ['record' => $batch], panel: 'admin'))
        ->assertForbidden();
});

it('deletes an intake batch, its research audit, and its private upload', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $intakeBatch = IngredientIntakeBatch::factory()->create([
        'status' => IngredientIntakeBatchStatus::Completed,
        'storage_disk' => 'local',
        'storage_path' => 'ingredient-intake/uploads/identities.csv',
    ]);
    $intakeItem = IngredientIntakeItem::factory()->for($intakeBatch, 'batch')->create([
        'status' => IngredientIntakeItemStatus::Promoted,
    ]);
    $enrichmentBatch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Applied,
    ]);
    $enrichmentItem = IngredientEnrichmentBatchItem::factory()
        ->for($enrichmentBatch, 'batch')
        ->for($intakeItem, 'intakeItem')
        ->create([
            'ingredient_id' => null,
            'catalog_key' => null,
        ]);
    $intakeBatch->update(['ingredient_enrichment_batch_id' => $enrichmentBatch->id]);
    $enrichmentArtifact = "ingredient-enrichment/batches/{$enrichmentBatch->public_id}/result.json";
    Storage::disk('local')->put($enrichmentArtifact, '{}');
    Storage::disk('local')->put('ingredient-intake/uploads/identities.csv', "current_name\nCoconut Oil\n");
    $this->actingAs($admin);

    Livewire::test(ListIngredientIntakeBatches::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('delete')->table($intakeBatch))
        ->callAction(TestAction::make('delete')->table($intakeBatch));

    $this->assertModelMissing($intakeBatch);
    $this->assertModelMissing($intakeItem);
    $this->assertModelMissing($enrichmentBatch);
    $this->assertModelMissing($enrichmentItem);
    Storage::disk('local')->assertMissing($enrichmentArtifact);
    Storage::disk('local')->assertMissing('ingredient-intake/uploads/identities.csv');
});

it('does not offer intake deletion while research is active', function (): void {
    $admin = User::factory()->admin()->create();
    $batch = IngredientIntakeBatch::factory()->create([
        'status' => IngredientIntakeBatchStatus::Researching,
    ]);
    $this->actingAs($admin);

    Livewire::test(ListIngredientIntakeBatches::class)
        ->loadTable()
        ->assertActionHidden(TestAction::make('delete')->table($batch));
});
