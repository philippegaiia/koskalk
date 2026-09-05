<?php

use App\Actions\IngredientEnrichment\DeleteIngredientEnrichmentBatches;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ListIngredientEnrichmentBatches;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('deletes selected terminal batches with their items, artifacts, and Laravel batch records', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $batches = IngredientEnrichmentBatch::factory()->count(2)->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $items = $batches->map(fn (IngredientEnrichmentBatch $batch): IngredientEnrichmentBatchItem => IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create());
    $jobBatchIds = $batches->map(fn (IngredientEnrichmentBatch $batch): string => Bus::batch([])->name('test enrichment')->dispatch()->id);

    foreach ($batches as $index => $batch) {
        $batch->update(['laravel_batch_id' => $jobBatchIds[$index]]);
        Storage::disk('local')->put(
            "ingredient-enrichment/batches/{$batch->public_id}/result.json",
            '{}',
        );
    }

    $this->actingAs($admin);

    Livewire::test(ListIngredientEnrichmentBatches::class)
        ->loadTable()
        ->assertActionExists(TestAction::make('deleteSelected')->table()->bulk())
        ->selectTableRecords($batches->pluck('id')->all())
        ->callAction(TestAction::make('deleteSelected')->table()->bulk())
        ->assertNotified();

    foreach ($batches as $batch) {
        $this->assertModelMissing($batch);
        $this->assertModelMissing($items->firstWhere('ingredient_enrichment_batch_id', $batch->id));
        expect(Storage::disk('local')->allFiles("ingredient-enrichment/batches/{$batch->public_id}"))->toBe([]);
        $this->assertDatabaseMissing('job_batches', ['id' => $jobBatchIds[$batches->search($batch)]]);
    }
});

it('allows the table to select only terminal batches for bulk deletion', function (): void {
    $admin = User::factory()->admin()->create();
    $terminal = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Cancelled,
    ]);
    $active = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
    ]);
    $this->actingAs($admin);

    $component = Livewire::test(ListIngredientEnrichmentBatches::class)->loadTable();

    expect($component->instance()->getAllSelectableTableRecordKeys())
        ->toContain((string) $terminal->getKey())
        ->not->toContain((string) $active->getKey());
});

it('rejects the entire selection when any selected batch is active', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $terminal = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $active = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
    ]);
    $terminalArtifact = "ingredient-enrichment/batches/{$terminal->public_id}/result.json";
    $activeArtifact = "ingredient-enrichment/batches/{$active->public_id}/result.json";
    Storage::disk('local')->put($terminalArtifact, '{}');
    Storage::disk('local')->put($activeArtifact, '{}');

    expect(fn () => app(DeleteIngredientEnrichmentBatches::class)->handle(
        $admin,
        collect([$terminal, $active]),
    ))->toThrow(ValidationException::class);

    $this->assertModelExists($terminal);
    $this->assertModelExists($active);
    Storage::disk('local')->assertExists($terminalArtifact);
    Storage::disk('local')->assertExists($activeArtifact);
});

it('preserves the original selection when a batch becomes active before confirmation', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $first = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $second = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $firstArtifact = "ingredient-enrichment/batches/{$first->public_id}/result.json";
    $secondArtifact = "ingredient-enrichment/batches/{$second->public_id}/result.json";
    Storage::disk('local')->put($firstArtifact, '{}');
    Storage::disk('local')->put($secondArtifact, '{}');

    $this->actingAs($admin);
    $component = Livewire::test(ListIngredientEnrichmentBatches::class)
        ->loadTable()
        ->selectTableRecords([$first->id, $second->id])
        ->mountAction(TestAction::make('deleteSelected')->table()->bulk());

    $second->update(['status' => IngredientEnrichmentBatchStatus::Processing]);

    $component
        ->callMountedAction()
        ->assertNotified();

    $this->assertModelExists($first);
    $this->assertModelExists($second);
    Storage::disk('local')->assertExists($firstArtifact);
    Storage::disk('local')->assertExists($secondArtifact);
});

it('rejects the original selection when a batch disappears before confirmation', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $first = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $second = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::ReadyForReview,
    ]);
    $firstArtifact = "ingredient-enrichment/batches/{$first->public_id}/result.json";
    Storage::disk('local')->put($firstArtifact, '{}');

    $this->actingAs($admin);
    $component = Livewire::test(ListIngredientEnrichmentBatches::class)
        ->loadTable()
        ->selectTableRecords([$first->id, $second->id])
        ->mountAction(TestAction::make('deleteSelected')->table()->bulk());

    $second->delete();

    $component
        ->callMountedAction()
        ->assertNotified();

    $this->assertModelExists($first);
    Storage::disk('local')->assertExists($firstArtifact);
});

it('authorizes every selected batch before deleting any batch', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $batches = IngredientEnrichmentBatch::factory()->count(2)->create([
        'status' => IngredientEnrichmentBatchStatus::Applied,
    ]);

    expect(fn () => app(DeleteIngredientEnrichmentBatches::class)->handle($user, $batches))
        ->toThrow(AuthorizationException::class);

    foreach ($batches as $batch) {
        $this->assertModelExists($batch);
    }
});

it('preserves applied ingredient fields and source data when deleting its batch audit', function (): void {
    Storage::fake('local');
    config()->set('ingredient-enrichment.batch_artifacts.disk', 'local');
    config()->set('ingredient-enrichment.batch_artifacts.directory', 'ingredient-enrichment/batches');
    $admin = User::factory()->admin()->create();
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Applied Ingredient',
        'inci_name' => 'APPLIED INGREDIENT',
        'source_data' => [
            'enrichment' => ['core' => ['field_confidence' => ['display_name' => 'verified']]],
            'catalog_source' => 'reviewed',
        ],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Applied,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'status' => 'applied',
    ]);
    $originalSourceData = $ingredient->source_data;

    app(DeleteIngredientEnrichmentBatches::class)->handle($admin, collect([$batch]));

    $this->assertModelMissing($batch);
    $this->assertModelMissing($item);
    $this->assertModelExists($ingredient);
    expect($ingredient->fresh()->display_name)->toBe('Applied Ingredient')
        ->and($ingredient->fresh()->inci_name)->toBe('APPLIED INGREDIENT')
        ->and($ingredient->fresh()->source_data)->toBe($originalSourceData);
});
