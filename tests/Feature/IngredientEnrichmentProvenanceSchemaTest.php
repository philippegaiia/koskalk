<?php

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientSourceTier;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientFunction;
use App\Models\IngredientIdentifier;
use App\Models\IngredientIdentifierEvidence;
use App\Models\IngredientMarketLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('persists field provenance and resumable enrichment state', function (): void {
    expect(Schema::hasColumns('ingredient_identifier_evidence', [
        'ingredient_identifier_id',
        'source_name',
        'source_url',
        'source_tier',
        'confidence',
        'source_version',
        'source_updated_at',
        'retrieved_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_market_labels', [
            'source_tier',
            'confidence',
            'source_version',
            'source_updated_at',
            'retrieved_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_function_ingredient', [
            'source_tier',
            'confidence',
            'source_version',
            'source_updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_enrichment_batches', ['structured_source_calls']))->toBeTrue()
        ->and(Schema::hasColumns('ingredient_enrichment_batch_items', [
            'research_stages',
            'original_result',
            'edited_fields',
            'edited_by_user_id',
            'edited_at',
            'structured_source_calls',
        ]))->toBeTrue();

    $identifier = IngredientIdentifier::factory()->create();
    $evidence = IngredientIdentifierEvidence::factory()->for($identifier, 'identifier')->create([
        'confidence' => IngredientEvidenceConfidence::Verified,
        'source_tier' => IngredientSourceTier::Official,
    ]);

    expect($identifier->fresh()->evidence->first()->is($evidence))->toBeTrue()
        ->and($evidence->confidence)->toBe(IngredientEvidenceConfidence::Verified)
        ->and($evidence->source_tier)->toBe(IngredientSourceTier::Official)
        ->and(IngredientMarketLabel::factory()->create()->source_tier)->toBe(IngredientSourceTier::Official)
        ->and(IngredientMarketLabel::factory()->create()->source_tier)->toBe(IngredientSourceTier::Official);

    $ingredient = Ingredient::factory()->create();
    $function = IngredientFunction::factory()->create();
    $ingredient->functions()->attach($function, [
        'source' => 'cosing',
        'source_tier' => IngredientSourceTier::StructuredMirror->value,
        'confidence' => IngredientEvidenceConfidence::Supported->value,
        'source_version' => 'inventory-2026-03-21',
        'source_updated_at' => '2026-03-21',
    ]);

    expect($ingredient->fresh()->functions->first()->pivot->source_tier)->toBe(IngredientSourceTier::StructuredMirror->value)
        ->and($ingredient->fresh()->functions->first()->pivot->confidence)->toBe(IngredientEvidenceConfidence::Supported->value);

    $batch = IngredientEnrichmentBatch::factory()->create();
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->create([
        'research_stages' => ['eu_structured' => ['status' => 'completed']],
        'original_result' => ['format' => 'ingredient-enrichment-result'],
        'edited_fields' => ['proposal.inci_name'],
    ]);

    expect($batch->structured_source_calls)->toBe(0)
        ->and($item->research_stages)->toBeArray()
        ->and($item->original_result)->toBeArray()
        ->and($item->edited_fields)->toBeArray()
        ->and($item->structured_source_calls)->toBe(0);
});
