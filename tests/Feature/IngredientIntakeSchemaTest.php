<?php

use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('requires exactly one enrichment subject', function (): void {
    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientIntakeItem::factory()->create();
    $catalogKey = 'enrichment-test';

    expect(fn () => IngredientEnrichmentBatchItem::query()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $item->id,
        'catalog_key' => null,
        'status' => IngredientEnrichmentItemStatus::Pending,
        'snapshot' => ['subject_type' => 'intake', 'subject_public_id' => $item->public_id],
        'source_fingerprint' => hash('sha256', $catalogKey),
    ]))->not->toThrow(QueryException::class);

    expect(fn () => IngredientEnrichmentBatchItem::query()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => null,
        'catalog_key' => null,
        'status' => IngredientEnrichmentItemStatus::Pending,
        'snapshot' => ['subject_type' => 'intake'],
        'source_fingerprint' => hash('sha256', 'missing-subject'),
    ]))->toThrow(QueryException::class);
});

it('rejects an enrichment item that targets both an ingredient and an intake item', function (): void {
    $batch = IngredientEnrichmentBatch::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $item = IngredientIntakeItem::factory()->create();

    expect(fn () => IngredientEnrichmentBatchItem::query()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'ingredient_intake_item_id' => $item->id,
        'catalog_key' => $ingredient->catalog_key,
        'status' => IngredientEnrichmentItemStatus::Pending,
        'snapshot' => ['subject_type' => 'intake', 'subject_public_id' => $item->public_id],
        'source_fingerprint' => hash('sha256', 'both-subjects'),
    ]))->toThrow(QueryException::class);
});

it('stores intake identity separately from its normalized lookup values', function (): void {
    $item = IngredientIntakeItem::factory()->create([
        'original_current_name' => '  Coconut oil  ',
        'normalized_current_name' => 'coconut oil',
        'original_inci_name' => null,
        'normalized_inci_name' => null,
    ]);

    expect($item->getRouteKeyName())->toBe('public_id')
        ->and($item->original_current_name)->toBe('  Coconut oil  ')
        ->and($item->normalized_current_name)->toBe('coconut oil')
        ->and($item->original_inci_name)->toBeNull();
});
