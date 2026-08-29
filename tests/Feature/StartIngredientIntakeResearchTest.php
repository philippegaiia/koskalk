<?php

use App\Actions\IngredientIntake\StartIngredientIntakeResearch;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Enums\IngredientResearchFamily;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\User;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ingredient-enrichment.direct_ai.enabled', true);
    config()->set('ingredient-enrichment.openai.api_key', 'test-only');
    Bus::fake();
});

it('accepts one ten and seventy intake rows with the same queue workflow', function (int $count): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $intake = IngredientIntakeBatch::factory()->create([
        'family_hint' => IngredientResearchFamily::Colourants,
        'allow_gap_research' => true,
    ]);

    foreach (range(1, $count) as $row) {
        IngredientIntakeItem::factory()->create([
            'ingredient_intake_batch_id' => $intake->id,
            'row_number' => $row,
            'original_current_name' => "Colourant {$row}",
            'normalized_current_name' => "colourant {$row}",
        ]);
    }

    $enrichment = app(StartIngredientIntakeResearch::class)->handle($admin, $intake);

    expect($enrichment->status)->toBe(IngredientEnrichmentBatchStatus::Processing)
        ->and($enrichment->mode)->toBe(IngredientEnrichmentBatchMode::Intake)
        ->and($enrichment->total_count)->toBe($count)
        ->and($enrichment->items)->toHaveCount($count)
        ->and($intake->fresh()->status)->toBe(IngredientIntakeBatchStatus::Researching)
        ->and($intake->fresh()->queued_count)->toBe($count)
        ->and($intake->items()->where('status', IngredientIntakeItemStatus::Queued)->count())->toBe($count)
        ->and($enrichment->items->every(fn ($item): bool => data_get($item->snapshot, 'research_rules.research_family') === 'colourants'))
        ->toBeTrue()
        ->and($enrichment->items->every(fn ($item): bool => data_get($item->snapshot, 'research_rules.allow_gap_research') === true))
        ->toBeTrue();

    Bus::assertBatched(function (PendingBatch $pending) use ($count): bool {
        return count($pending->jobs) === $count;
    });
})->with([1, 10, 70]);

it('dispatches eligible rows while exact duplicates remain paused', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $existing = Ingredient::factory()->create(['display_name' => 'Cocoa Butter']);
    $intake = IngredientIntakeBatch::factory()->create();
    $duplicate = IngredientIntakeItem::factory()->create([
        'ingredient_intake_batch_id' => $intake->id,
        'original_current_name' => 'Cocoa Butter',
        'normalized_current_name' => 'cocoa butter',
        'status' => IngredientIntakeItemStatus::NeedsResolution,
        'duplicate_candidates' => [[
            'candidate_type' => 'ingredient',
            'ingredient_id' => $existing->id,
            'match_type' => 'exact',
        ]],
    ]);
    $eligible = IngredientIntakeItem::factory()->create([
        'ingredient_intake_batch_id' => $intake->id,
        'original_current_name' => 'Murumuru Butter',
        'normalized_current_name' => 'murumuru butter',
    ]);

    $enrichment = app(StartIngredientIntakeResearch::class)->handle($admin, $intake);

    expect($enrichment->items)->toHaveCount(1)
        ->and($enrichment->items->first()->ingredient_intake_item_id)->toBe($eligible->id)
        ->and($duplicate->fresh()->status)->toBe(IngredientIntakeItemStatus::NeedsResolution)
        ->and($eligible->fresh()->status)->toBe(IngredientIntakeItemStatus::Queued);

    Bus::assertBatched(fn (PendingBatch $pending): bool => count($pending->jobs) === 1);
});

it('does not create duplicate enrichment items when intake research is started again', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);
    $intake = IngredientIntakeBatch::factory()->create();
    IngredientIntakeItem::factory()->create(['ingredient_intake_batch_id' => $intake->id]);

    $first = app(StartIngredientIntakeResearch::class)->handle($admin, $intake);
    $second = app(StartIngredientIntakeResearch::class)->handle($admin, $intake->fresh());

    expect($second->id)->toBe($first->id)
        ->and(IngredientEnrichmentBatch::query()->count())->toBe(1)
        ->and($first->items()->count())->toBe(1);

    Bus::assertBatchCount(1);
});
