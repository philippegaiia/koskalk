<?php

use App\Contracts\IngredientResearchClient;
use App\Data\IngredientResearchResponse;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\SupportedLocale;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\ResearchIngredientEnrichmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('researches, validates, plans, and persists a proposal without changing the ingredient', function (): void {
    seedResearchLocales();
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'apricot_oil',
        'category' => IngredientCategory::Other,
        'display_name' => 'Apricot oil',
        'inci_name' => null,
        'info_markdown' => null,
    ]);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
    ]);
    $result = validDirectResult($ingredient, $snapshot['source_fingerprint']);
    $source = ['url' => 'https://ec.europa.eu/growth/tools-databases/cosing/', 'title' => 'European Commission CosIng'];
    app()->instance(IngredientResearchClient::class, new class($result, $source) implements IngredientResearchClient
    {
        public function __construct(private array $result, private array $source) {}

        public function research(array $record): IngredientResearchResponse
        {
            return new IngredientResearchResponse($this->result, 'resp_1', 'req_1', 'gpt-test', 100, 50, 1, [$this->source]);
        }
    });

    (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class));

    $item->refresh();
    expect($item->status)->toBe(IngredientEnrichmentItemStatus::Warning)
        ->and($item->attempt_count)->toBe(1)
        ->and($item->result['proposal']['inci_name'])->toBe('PRUNUS ARMENIACA KERNEL OIL')
        ->and($item->plan['changed'])->toBeTrue()
        ->and($item->provider_response_id)->toBe('resp_1')
        ->and($item->sources)->toBe([$source])
        ->and($item->batch->status)->toBe(IngredientEnrichmentBatchStatus::ReadyForReview)
        ->and($ingredient->fresh()->inci_name)->toBeNull();
});

it('marks a changed source snapshot stale without calling the provider', function (): void {
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'stale_oil']);
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);
    $batch = IngredientEnrichmentBatch::factory()->create(['total_count' => 1, 'pending_count' => 1]);
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'catalog_key' => $ingredient->catalog_key,
        'snapshot' => $snapshot,
        'source_fingerprint' => $snapshot['source_fingerprint'],
    ]);
    $ingredient->update(['display_name' => 'Changed after queue']);
    app()->instance(IngredientResearchClient::class, new class implements IngredientResearchClient
    {
        public function research(array $record): IngredientResearchResponse
        {
            throw new RuntimeException('Provider must not be called.');
        }
    });

    (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class));

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Stale);
});

function seedResearchLocales(): void
{
    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        SupportedLocale::factory()->create(['code' => $locale, 'name' => $locale]);
    }
}

/** @return array<string, mixed> */
function validDirectResult(Ingredient $ingredient, string $fingerprint): array
{
    return [
        'format' => 'soapkraft-platform-ingredient-enrichment-result',
        'schema_version' => 1,
        'catalog_key' => $ingredient->catalog_key,
        'source_fingerprint' => $fingerprint,
        'proposal' => [
            'display_name' => 'Apricot Kernel Oil',
            'inci_name' => 'PRUNUS ARMENIACA KERNEL OIL',
            'category' => 'other',
            'subcategory' => null,
            'saponification_name' => 'Apricot Kernel Oil',
            'info_markdown' => "## Overview\nA useful cosmetic vegetable oil.\n\n## Formulation use\nUsed as an emollient oil in cosmetic formulations.",
            'soapmaking_relevant' => false,
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])->map(fn (string $locale): array => [
                'locale' => $locale,
                'display_name' => "Apricot Oil {$locale}",
                'saponification_name' => null,
                'info_markdown' => "## Overview\nA translated cosmetic oil.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.",
            ])->all(),
            'market_labels' => [],
        ],
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'European Commission CosIng',
            'source_url' => 'https://ec.europa.eu/growth/tools-databases/cosing/',
            'checked_at' => now()->toDateString(),
        ]],
        'confidence' => 'high',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}
