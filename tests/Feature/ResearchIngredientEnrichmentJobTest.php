<?php

use App\Data\IngredientEnrichmentPipelineResponse;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientIntakeBatchStatus;
use App\Enums\IngredientIntakeItemStatus;
use App\Jobs\ResearchIngredientEnrichment;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientIntakeBatch;
use App\Models\IngredientIntakeItem;
use App\Models\SupportedLocale;
use App\Services\IngredientEnrichment\IngredientEnrichmentInputBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentPipeline;
use App\Services\IngredientEnrichment\IngredientEnrichmentSubjectBuilder;
use App\Services\IngredientEnrichment\IngredientResearchProviderException;
use App\Services\IngredientEnrichment\ResearchIngredientEnrichmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('uses the configured enrichment job timeout', function (): void {
    config()->set('ingredient-enrichment.direct_ai.job_timeout_seconds', 900);

    $job = new ResearchIngredientEnrichment(123);

    expect($job->timeout)->toBe(900);
});

it('keeps the default enrichment request timeout below its job window', function (): void {
    expect(config('ingredient-enrichment.openai.timeout_seconds'))->toBe(600)
        ->and(config('ingredient-enrichment.direct_ai.job_timeout_seconds'))->toBe(900);
});

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
    $source = ['url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175', 'title' => 'EUR-Lex'];
    app()->instance(IngredientEnrichmentPipeline::class, new class($result, $source) extends IngredientEnrichmentPipeline
    {
        public function __construct(private array $result, private array $source) {}

        public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
        {
            return new IngredientEnrichmentPipelineResponse($this->result, [$this->source], 'resp_1', 'req_1', 'gpt-test', 100, 50, 0, 3);
        }
    });

    (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class));

    $item->refresh();
    expect($item->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($item->attempt_count)->toBe(1)
        ->and($item->result['proposal']['inci_name'])->toBe('Prunus armeniaca kernel oil')
        ->and($item->plan['changed'])->toBeTrue()
        ->and($item->provider_response_id)->toBe('resp_1')
        ->and($item->structured_source_calls)->toBe(3)
        ->and($item->original_result)->toBe($item->result)
        ->and($item->sources)->toBe([$source])
        ->and($item->batch->status)->toBe(IngredientEnrichmentBatchStatus::ReadyForReview)
        ->and($item->batch->structured_source_calls)->toBe(3)
        ->and($ingredient->fresh()->inci_name)->toBeNull();
});

it('validates and plans an intake proposal before it reaches review', function (): void {
    seedResearchLocales();
    $intakeBatch = IngredientIntakeBatch::factory()->create([
        'status' => IngredientIntakeBatchStatus::Researching,
    ]);
    $intakeItem = IngredientIntakeItem::factory()->create([
        'ingredient_intake_batch_id' => $intakeBatch->id,
        'status' => IngredientIntakeItemStatus::Queued,
        'original_current_name' => 'Apricot oil',
        'normalized_current_name' => 'apricot oil',
    ]);
    $subject = app(IngredientEnrichmentSubjectBuilder::class)->forIntake($intakeItem->fresh());
    $snapshot = app(IngredientEnrichmentInputBuilder::class)->buildForSubject($subject);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'status' => IngredientEnrichmentBatchStatus::Processing,
        'mode' => 'intake',
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $enrichmentItem = IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => $intakeItem->id,
        'catalog_key' => null,
        'snapshot' => $snapshot,
        'source_fingerprint' => $subject->fingerprint,
    ]);
    $sourceIngredient = Ingredient::factory()->create(['catalog_key' => 'intake-test-source']);
    $result = validDirectResult($sourceIngredient, $subject->fingerprint);
    $result['subject_type'] = 'intake';
    $result['subject_public_id'] = (string) $intakeItem->public_id;
    $result['catalog_key'] = null;
    $source = ['url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175', 'title' => 'EUR-Lex'];
    app()->instance(IngredientEnrichmentPipeline::class, new class($result, $source) extends IngredientEnrichmentPipeline
    {
        public function __construct(private array $result, private array $source) {}

        public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
        {
            return new IngredientEnrichmentPipelineResponse($this->result, [$this->source], 'resp_intake', 'req_intake', 'gpt-test', 100, 50, 0, 3);
        }
    });

    (new ResearchIngredientEnrichment($enrichmentItem->id))->handle(app(ResearchIngredientEnrichmentItem::class));

    expect($enrichmentItem->fresh()->status)->toBeIn([
        IngredientEnrichmentItemStatus::Ready,
        IngredientEnrichmentItemStatus::Warning,
    ])
        ->and($enrichmentItem->fresh()->validation_report['valid'])->toBeTrue()
        ->and($enrichmentItem->fresh()->plan['changed'])->toBeTrue()
        ->and($enrichmentItem->fresh()->result['subject_type'])->toBe('intake')
        ->and($intakeItem->fresh()->status)->toBe(IngredientIntakeItemStatus::Ready)
        ->and($sourceIngredient->fresh()->display_name)->not->toBe('Apricot Kernel Oil');
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
    app()->instance(IngredientEnrichmentPipeline::class, new class extends IngredientEnrichmentPipeline
    {
        public function __construct() {}

        public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
        {
            throw new RuntimeException('Provider must not be called.');
        }
    });

    (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class));

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Stale);
});

it('persists safe provider diagnostics when research fails', function (): void {
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'failed_oil']);
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
    app()->instance(IngredientEnrichmentPipeline::class, new class extends IngredientEnrichmentPipeline
    {
        public function __construct() {}

        public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
        {
            throw new IngredientResearchProviderException(
                'provider_http_400_unsupported_parameter',
                'The research provider could not complete this ingredient (HTTP 400, unsupported_parameter, request req_failed_item_123).',
            );
        }
    });

    expect(fn () => (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class)))
        ->toThrow(RuntimeException::class, 'HTTP 400');

    $item->refresh();
    expect($item->status)->toBe(IngredientEnrichmentItemStatus::Failed)
        ->and($item->failure_code)->toBe('provider_http_400_unsupported_parameter')
        ->and($item->failure_message)->toContain('HTTP 400')
        ->and($item->failure_message)->toContain('req_failed_item_123')
        ->and($item->failure_message)->not->toContain('Sensitive provider detail')
        ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::PartiallyFailed);
});

it('persists safe field diagnostics when a generated proposal fails validation', function (): void {
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'invalid_result_oil']);
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
    app()->instance(IngredientEnrichmentPipeline::class, new class extends IngredientEnrichmentPipeline
    {
        public function __construct() {}

        public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
        {
            IngredientEnrichmentBatchItem::query()->findOrFail($itemId)->update([
                'research_stages' => [
                    'eu_structured' => [
                        'status' => 'completed',
                        'source_calls' => 2,
                    ],
                    'ai_editorial' => [
                        'status' => 'completed',
                        'source_calls' => 0,
                        'data' => [
                            'provider_response_id' => 'resp_validation',
                            'provider_request_id' => 'req_validation',
                            'provider_model' => 'gpt-validation',
                            'input_tokens' => 321,
                            'output_tokens' => 123,
                            'web_search_calls' => 4,
                        ],
                    ],
                ],
            ]);

            throw ValidationException::withMessages([
                'proposal.inci_name' => 'The INCI name could not be verified.',
            ]);
        }
    });

    expect(fn () => (new ResearchIngredientEnrichment($item->id))->handle(app(ResearchIngredientEnrichmentItem::class)))
        ->toThrow(ValidationException::class);

    expect($item->fresh()->failure_code)->toBe('ValidationException')
        ->and($item->fresh()->failure_message)->toContain('The INCI name could not be verified.')
        ->and($item->fresh()->failure_message)->not->toContain('research provider')
        ->and($item->fresh()->provider_response_id)->toBe('resp_validation')
        ->and($item->fresh()->provider_request_id)->toBe('req_validation')
        ->and($item->fresh()->provider_model)->toBe('gpt-validation')
        ->and($item->fresh()->input_tokens)->toBe(321)
        ->and($item->fresh()->output_tokens)->toBe(123)
        ->and($item->fresh()->web_search_calls)->toBe(4)
        ->and($item->fresh()->structured_source_calls)->toBe(2);
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
        'schema_version' => 2,
        'catalog_key' => $ingredient->catalog_key,
        'source_fingerprint' => $fingerprint,
        'proposal' => [
            'display_name' => 'Apricot Kernel Oil',
            'inci_name' => 'PRUNUS ARMENIACA KERNEL OIL',
            'category' => 'other',
            'subcategory' => null,
            'saponification_name' => 'Apricot Kernel Oil',
            'soap_inci_naoh_name' => null,
            'soap_inci_koh_name' => null,
            'info_markdown' => "## Overview\nA useful cosmetic vegetable oil.\n\n## Formulation use\nUsed as an emollient oil in cosmetic formulations.",
            'soapmaking_relevant' => false,
            'identifiers' => [],
            'cosing_functions' => [],
            'translations' => collect(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])->map(function (string $locale): array {
                $headings = config("ingredient-enrichment.guidance.localized_headings.{$locale}");

                return [
                    'locale' => $locale,
                    'display_name' => "Apricot Oil {$locale}",
                    'saponification_name' => null,
                    'info_markdown' => "## {$headings['overview']}\nA translated cosmetic oil.\n\n## {$headings['formulation_use']}\nUsed as an emollient in cosmetic formulations.",
                ];
            })->all(),
            'market_labels' => [],
        ],
        'field_confidence' => [
            ['field' => 'proposal.inci_name', 'confidence' => 'verified'],
        ],
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '32025D1175',
            'source_updated_at' => null,
            'retrieved_at' => now()->toIso8601String(),
        ]],
        'regulatory_findings' => [],
        'confidence' => 'high',
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}
