<?php

use App\Actions\IngredientEnrichment\StartIngredientEnrichmentBatch;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ingredient-enrichment.direct_ai.enabled', true);
    config()->set('ingredient-enrichment.openai.api_key', 'test-only');
    Bus::fake();
});

it('atomically captures selected platform ingredients and dispatches one batched job each', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $ingredients = Ingredient::factory()->count(3)->create();

    $batch = app(StartIngredientEnrichmentBatch::class)->handle($admin, $ingredients);

    expect($batch->status)->toBe(IngredientEnrichmentBatchStatus::Processing)
        ->and($batch->total_count)->toBe(3)
        ->and($batch->pending_count)->toBe(3)
        ->and($batch->items)->toHaveCount(3)
        ->and($batch->items->pluck('snapshot.*.catalog_key'))->not->toBeEmpty();

    Bus::assertBatched(function (PendingBatch $pending) use ($batch): bool {
        return $pending->name === "ingredient-enrichment:{$batch->public_id}"
            && count($pending->jobs) === 3
            && collect($pending->jobs)->every(fn (mixed $job): bool => $job instanceof ResearchIngredientEnrichment);
    });
});

it('rejects private ingredients without leaving partial batch rows', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $private = Ingredient::factory()->create(['owner_type' => 'user', 'owner_id' => $admin->id]);

    expect(fn () => app(StartIngredientEnrichmentBatch::class)->handle($admin, collect([$private])))
        ->toThrow(ValidationException::class, 'Only platform');

    expect(IngredientEnrichmentBatch::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});

it('rejects a non admin before creating or dispatching anything', function (): void {
    $user = User::factory()->create(['is_admin' => false]);
    $ingredient = Ingredient::factory()->create();

    expect(fn () => app(StartIngredientEnrichmentBatch::class)->handle($user, collect([$ingredient])))
        ->toThrow(AuthorizationException::class);

    expect(IngredientEnrichmentBatch::query()->count())->toBe(0);
    Bus::assertNothingBatched();
});
