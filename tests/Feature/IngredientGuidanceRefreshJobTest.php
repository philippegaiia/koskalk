<?php

use App\Actions\IngredientEnrichment\RetryIngredientEnrichmentFailures;
use App\Actions\IngredientEnrichment\StartIngredientGuidanceRefresh;
use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientGuidanceAuthoringResponse;
use App\Data\IngredientGuidanceLocalizationResponse;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceContextBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshProcessor;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshResultValidator;
use App\Services\IngredientTranslationSourceFingerprint;
use Database\Seeders\SupportedLocaleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    config()->set('ingredient-enrichment.direct_ai.enabled', true);
    config()->set('ingredient-enrichment.openai.api_key', 'test-only');
    Bus::fake();
});

it('queues a guidance refresh and calls authoring and localization without research', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;

            return new IngredientGuidanceAuthoringResponse(
                guidance: [
                    'info_markdown' => guidanceText(),
                    'warnings' => [],
                    'unresolved_questions' => [],
                ],
                responseId: 'resp-guidance',
                requestId: 'req-guidance',
                model: 'gpt-test',
                inputTokens: 11,
                outputTokens: 22,
            );
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($calls) implements IngredientGuidanceLocalizationClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->calls['localize']++;

            return new IngredientGuidanceLocalizationResponse(
                translations: collect($context['locales'] ?? [])->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->all(),
                responseId: 'resp-localization',
                requestId: 'req-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => guidanceText(),
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                        'summary' => 'A supported practical formulation fact.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-28T00:00:00+00:00',
                    ]],
                ],
            ],
        ],
    ]);

    $batch = app(StartIngredientGuidanceRefresh::class)
        ->handle($admin, collect([$ingredient]));
    $item = $batch->items->sole();

    Bus::assertBatched(fn ($pending): bool => count($pending->jobs) === 1
        && $pending->jobs[0] instanceof GenerateIngredientGuidanceRefresh);

    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);
    $item->refresh();

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($item->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($item->result['mode'])->toBe(IngredientEnrichmentBatchMode::GuidanceRefresh->value)
        ->and($item->input_tokens)->toBe(44)
        ->and($item->output_tokens)->toBe(66)
        ->and($item->web_search_calls)->toBe(0)
        ->and($item->plan['decisions'])->toHaveCount(7);
});

it('localizes only outdated locales and never calls English authoring', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;
            throw new RuntimeException('authoring must not run');
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($calls) implements IngredientGuidanceLocalizationClient
    {
        public function __construct(private array &$calls) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->calls['localize']++;
            if ($this->calls['localize'] === 1) {
                throw new RuntimeException('temporary localization failure');
            }

            return new IngredientGuidanceLocalizationResponse(
                translations: [[
                    'locale' => 'fr',
                    'info_markdown' => localizedGuidanceText(),
                ]],
                responseId: 'resp-localization',
                requestId: 'req-localization',
                model: 'gpt-test',
                inputTokens: 5,
                outputTokens: 6,
            );
        }
    });

    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil', 'info_markdown' => guidanceText()]);
    $fingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($ingredient);
    $ingredient->translations()->create([
        'locale' => 'fr',
        'display_name' => 'Huile d’olive',
        'info_markdown' => localizedGuidanceText(),
        'source_fingerprint' => str_repeat('a', 64),
        'origin' => 'ai_generated',
    ]);
    $ingredient->translations()->create([
        'locale' => 'de',
        'display_name' => 'Olivenöl',
        'info_markdown' => localizedGuidanceText(),
        'source_fingerprint' => $fingerprint,
        'origin' => 'ai_generated',
    ]);

    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => 'pending',
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'snapshot' => app(IngredientGuidanceContextBuilder::class)->build($ingredient),
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($item->id))
        ->toThrow(RuntimeException::class, 'temporary localization failure');

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);
    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);

    expect($calls)->toBe(['author' => 0, 'localize' => 2])
        ->and($item->fresh()->result['translations'])->toHaveCount(1)
        ->and($item->fresh()->result['translations'][0]['locale'])->toBe('fr');
});

it('resumes localization after a guidance refresh failure without repeating authoring', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;

            return new IngredientGuidanceAuthoringResponse(
                guidance: [
                    'info_markdown' => guidanceText(),
                    'warnings' => [],
                    'unresolved_questions' => [],
                ],
                responseId: 'resp-guidance',
                requestId: 'req-guidance',
                model: 'gpt-test',
                inputTokens: 11,
                outputTokens: 22,
            );
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($calls) implements IngredientGuidanceLocalizationClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->calls['localize']++;
            if ($this->calls['localize'] === 1) {
                throw new RuntimeException('temporary localization failure');
            }

            return new IngredientGuidanceLocalizationResponse(
                translations: collect($context['locales'] ?? [])->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->all(),
                responseId: 'resp-localization',
                requestId: 'req-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => 'The previous guidance is replaced during this refresh.',
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/example',
                        'summary' => 'Persisted practical evidence.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-28T00:00:00+00:00',
                    ]],
                ],
            ],
        ],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::Processing,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'snapshot' => $context,
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($item->id))
        ->toThrow(RuntimeException::class, 'temporary localization failure');

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Failed)
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);

    expect($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Pending)
        ->and(array_keys($item->fresh()->research_stages))->toBe(['ai_guidance_authoring']);

    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);
    $completed = $item->fresh();

    expect($calls)->toBe(['author' => 1, 'localize' => 2])
        ->and($completed->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($completed->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($completed->research_stages, 'ai_guidance_localization.status'))->toBe('completed')
        ->and(data_get($completed->research_stages, 'validation.status'))->toBe('completed')
        ->and($completed->result['info_markdown'])->toBe(trim(guidanceText()))
        ->and($completed->provider_response_id)->toBe('resp-guidance')
        ->and($completed->provider_request_id)->toBe('req-guidance')
        ->and($completed->provider_model)->toBe('gpt-test')
        ->and($completed->input_tokens)->toBe(44)
        ->and($completed->output_tokens)->toBe(66)
        ->and($completed->sources[0]['url'])->toBe('https://cosmileeurope.eu/example');
});

it('does not store a localization-only flag on the guidance job', function (): void {
    $job = new GenerateIngredientGuidanceRefresh(123);

    expect(property_exists($job, 'localizationOnly'))->toBeFalse()
        ->and((new ReflectionClass($job))->getConstructor()?->getNumberOfParameters())->toBe(1);
});

it('resumes a validation failure without repeating completed guidance providers', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;

            return new IngredientGuidanceAuthoringResponse(
                guidance: ['info_markdown' => guidanceText(), 'warnings' => [], 'unresolved_questions' => []],
                responseId: 'resp-guidance',
                requestId: 'req-guidance',
                model: 'gpt-test',
                inputTokens: 11,
                outputTokens: 22,
            );
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($calls) implements IngredientGuidanceLocalizationClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->calls['localize']++;

            return new IngredientGuidanceLocalizationResponse(
                translations: collect($context['locales'] ?? [])->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->all(),
                responseId: 'resp-localization',
                requestId: 'req-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $validator = new class(app(IngredientEnrichmentSnapshotBuilder::class)) extends IngredientGuidanceRefreshResultValidator
    {
        public int $calls = 0;

        public function validateOrFail(
            array $result,
            Ingredient $ingredient,
            IngredientEnrichmentBatchMode $mode,
            ?array $expectedLocales = null,
        ): array {
            $this->calls++;
            if ($this->calls === 1) {
                throw ValidationException::withMessages(['result' => 'transient validation failure']);
            }

            return parent::validateOrFail($result, $ingredient, $mode, $expectedLocales);
        }
    };
    app()->instance(IngredientGuidanceRefreshResultValidator::class, $validator);

    $admin = User::factory()->create(['is_admin' => true]);
    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => 'The previous guidance is replaced during this refresh.',
        'source_data' => [
            'enrichment' => [
                'guidance' => [
                    'evidence' => [[
                        'source_name' => 'COSMILE Europe',
                        'source_url' => 'https://cosmileeurope.eu/example',
                        'summary' => 'Persisted practical evidence.',
                        'source_tier' => 'editorial',
                        'retrieved_at' => '2026-08-28T00:00:00+00:00',
                    ]],
                ],
            ],
        ],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceRefresh,
        'status' => IngredientEnrichmentBatchStatus::Processing,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'snapshot' => $context,
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($item->id))
        ->toThrow(ValidationException::class, 'transient validation failure');

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('completed')
        ->and(data_get($item->fresh()->research_stages, 'validation.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);
    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);

    expect($validator->calls)->toBe(2)
        ->and($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($item->fresh()->research_stages, 'validation.status'))->toBe('completed');
});

function guidanceText(): string
{
    return "## Overview\nOlive oil is a plant-derived fixed oil with a defined fatty-acid profile.\n\n## Formulation use\nIts material-specific profile supports a light emollient contribution and helps select it when a fluid oil phase is needed. ".str_repeat('Use the measured material grade and review the complete formula. ', 10);
}

function localizedGuidanceText(): string
{
    return "## Vue d’ensemble\nCette huile végétale apporte un profil lipidique défini.\n\n## Utilisation en formulation\nSon profil aide à choisir une phase huileuse fluide pour une formule adaptée. ".str_repeat('Évaluer la qualité et la formule complète. ', 10);
}
