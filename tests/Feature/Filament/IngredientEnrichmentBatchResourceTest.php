<?php

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ListIngredientEnrichmentBatches;
use App\Filament\Resources\IngredientEnrichmentBatches\Pages\ViewIngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
