<?php

use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('persists auditable batches and per ingredient research items', function (): void {
    expect(Schema::hasColumns('ingredient_enrichment_batches', [
        'public_id',
        'requested_by_user_id',
        'status',
        'laravel_batch_id',
        'model',
        'prompt_version',
        'total_count',
        'input_tokens',
        'started_at',
        'completed_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_enrichment_batch_items', [
            'public_id',
            'ingredient_enrichment_batch_id',
            'ingredient_id',
            'catalog_key',
            'status',
            'snapshot',
            'source_fingerprint',
            'result',
            'validation_report',
            'plan',
            'replacement_fields',
            'sources',
            'provider_response_id',
            'provider_request_id',
            'approved_by_user_id',
            'applied_by_user_id',
        ]))->toBeTrue();

    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
        'started_at' => now(),
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'status' => IngredientEnrichmentItemStatus::Ready,
        'result' => ['proposal' => ['inci_name' => 'PRUNUS ARMENIACA KERNEL OIL']],
        'sources' => [['url' => 'https://ec.europa.eu/example']],
    ]);

    expect($batch->getRouteKeyName())->toBe('public_id')
        ->and($item->getRouteKeyName())->toBe('public_id')
        ->and($batch->status)->toBe(IngredientEnrichmentBatchStatus::Processing)
        ->and($batch->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($item->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($item->result)->toBeArray()
        ->and($item->sources)->toBeArray()
        ->and($batch->items()->first()->is($item))->toBeTrue()
        ->and($item->batch->is($batch))->toBeTrue()
        ->and($item->ingredient)->toBeInstanceOf(Ingredient::class);
});

it('prevents duplicate ingredients within a batch while retaining audit rows after ingredient deletion', function (): void {
    $batch = IngredientEnrichmentBatch::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $attributes = [
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'snapshot' => ['catalog_key' => $ingredient->catalog_key],
        'source_fingerprint' => str_repeat('a', 64),
    ];

    IngredientEnrichmentBatchItem::query()->create($attributes);

    expect(fn () => IngredientEnrichmentBatchItem::query()->create($attributes))
        ->toThrow(QueryException::class);

    $ingredient->delete();

    expect(IngredientEnrichmentBatchItem::query()->firstOrFail()->ingredient_id)->toBeNull();
});

it('allows only admins to access or mutate enrichment batches', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_admin' => false]);
    $batch = IngredientEnrichmentBatch::factory()->create();

    foreach (['view', 'update', 'approve', 'apply', 'retry', 'cancel'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $batch))->toBeTrue()
            ->and(Gate::forUser($user)->allows($ability, $batch))->toBeFalse();
    }

    expect(Gate::forUser($admin)->allows('viewAny', IngredientEnrichmentBatch::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', IngredientEnrichmentBatch::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewAny', IngredientEnrichmentBatch::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create', IngredientEnrichmentBatch::class))->toBeFalse();
});
