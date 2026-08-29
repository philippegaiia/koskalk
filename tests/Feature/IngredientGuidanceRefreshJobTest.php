<?php

use App\Actions\IngredientEnrichment\RetryIngredientEnrichmentFailures;
use App\Actions\IngredientEnrichment\StartIngredientGuidanceRefresh;
use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientGuidanceAuthoringResponse;
use App\Data\IngredientGuidanceLocalizationResponse;
use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentBatchMode;
use App\Enums\IngredientEnrichmentBatchStatus;
use App\Enums\IngredientEnrichmentItemStatus;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Jobs\GenerateIngredientGuidanceRefresh;
use App\Models\Ingredient;
use App\Models\IngredientEnrichmentBatch;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\User;
use App\Services\IngredientEnrichment\IngredientEnrichmentSnapshotBuilder;
use App\Services\IngredientEnrichment\IngredientEnrichmentStageStore;
use App\Services\IngredientEnrichment\IngredientGuidanceContextBuilder;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshProcessor;
use App\Services\IngredientEnrichment\IngredientGuidanceRefreshResultValidator;
use App\Services\IngredientEnrichment\IngredientGuidanceStageRunner;
use App\Services\IngredientEnrichment\LocalizedGuidanceHeadings;
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
                ])->values()->all(),
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

    $stages = $item->research_stages;
    expect(data_get($stages, 'ai_guidance_authoring.data.stage_context.provider_configuration'))
        ->toMatchArray([
            'model' => (string) config('ingredient-enrichment.openai.model'),
            'reasoning_effort' => (string) config('ingredient-enrichment.openai.reasoning_effort'),
            'guidance_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_prompt_version'),
            'required_headings' => config('ingredient-enrichment.guidance.required_headings'),
            'soapmaking_heading' => (string) config('ingredient-enrichment.guidance.soapmaking_heading'),
        ])
        ->and(data_get($stages, 'ai_guidance_localization.data.stage_context.provider_configuration'))
        ->toMatchArray([
            'model' => (string) config('ingredient-enrichment.openai.model'),
            'reasoning_effort' => (string) config('ingredient-enrichment.openai.reasoning_effort'),
            'localization_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
            'localized_headings' => config('ingredient-enrichment.guidance.localized_headings'),
        ])
        ->and(data_get($stages, 'validation.data.stage_context.provider_configuration'))
        ->toMatchArray([
            'model' => (string) config('ingredient-enrichment.openai.model'),
            'reasoning_effort' => (string) config('ingredient-enrichment.openai.reasoning_effort'),
            'guidance_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_prompt_version'),
            'localization_prompt_version' => (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
            'required_headings' => config('ingredient-enrichment.guidance.required_headings'),
            'localized_headings' => config('ingredient-enrichment.guidance.localized_headings'),
        ]);
});

it('rejects a completed authoring cache whose item provenance changed', function (): void {
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
                responseId: 'resp-guidance-provenance',
                requestId: 'req-guidance-provenance',
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
                responseId: 'resp-localization-provenance',
                requestId: 'req-localization-provenance',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);
    $validStages = $fixture['item']->fresh()->research_stages;
    expect(data_get($validStages, 'ai_guidance_authoring.data.stage_context'))->toBeArray();

    foreach ([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization->value,
        'subject_public_id' => 'copied-ingredient',
        'source_fingerprint' => hash('sha256', 'unrelated-input'),
        'input_fingerprint' => hash('sha256', 'unrelated-context'),
        'dependency_fingerprint' => hash('sha256', 'unrelated-guidance'),
    ] as $field => $value) {
        $stages = $validStages;
        data_set($stages, "ai_guidance_authoring.data.stage_context.{$field}", $value);
        $fixture['item']->update([
            'status' => IngredientEnrichmentItemStatus::Failed,
            'research_stages' => $stages,
        ]);

        expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
            ->toThrow(LogicException::class);

        expect($calls)->toBe(['author' => 1, 'localize' => 1])
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed')
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization'))->toBeNull()
            ->and(data_get($fixture['item']->fresh()->research_stages, 'validation'))->toBeNull();
    }
});

it('rejects a completed localization cache whose upstream guidance changed', function (): void {
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
                responseId: 'resp-guidance-provenance-localization',
                requestId: 'req-guidance-provenance-localization',
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
                responseId: 'resp-localization-provenance-localization',
                requestId: 'req-localization-provenance-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);
    $stages = $fixture['item']->fresh()->research_stages;
    expect(data_get($stages, 'ai_guidance_localization.data.stage_context'))->toBeArray();

    data_set($stages, 'ai_guidance_localization.data.stage_context.dependency_fingerprint', str_repeat('f', 64));
    $fixture['item']->update([
        'status' => IngredientEnrichmentItemStatus::Failed,
        'research_stages' => $stages,
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class);

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('failed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'validation'))->toBeNull();
});

it('recomputes guidance stages when effective provider configuration changes', function (): void {
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
                responseId: 'resp-guidance-provider-config',
                requestId: 'req-guidance-provider-config',
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
                responseId: 'resp-localization-provider-config',
                requestId: 'req-localization-provider-config',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    config()->set('ingredient-enrichment.openai.model', 'gpt-configuration-updated');
    $fixture['item']->update(['status' => IngredientEnrichmentItemStatus::Failed]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class);

    expect($calls)->toBe(['author' => 1, 'localize' => 1]);

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    expect($calls)->toBe(['author' => 2, 'localize' => 2])
        ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready);
});

it('invalidates downstream guidance caches when a queued retry recomputes authoring', function (): void {
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
                responseId: 'resp-guidance-queued-retry',
                requestId: 'req-guidance-queued-retry',
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
                responseId: 'resp-localization-queued-retry',
                requestId: 'req-localization-queued-retry',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);
    $stages = $fixture['item']->fresh()->research_stages;
    data_set($stages, 'ai_guidance_authoring.data.guidance.info_markdown', str_replace('## Overview', '## Summary', guidanceText()));
    $fixture['item']->update([
        'status' => IngredientEnrichmentItemStatus::Failed,
        'research_stages' => $stages,
    ]);

    $job = new GenerateIngredientGuidanceRefresh($fixture['item']->id);
    expect(fn () => $job->handle(app(IngredientGuidanceRefreshProcessor::class)))
        ->toThrow(LogicException::class);

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization'))->toBeNull()
        ->and(data_get($fixture['item']->fresh()->research_stages, 'validation'))->toBeNull();

    $job->handle(app(IngredientGuidanceRefreshProcessor::class));
    expect($calls)->toBe(['author' => 2, 'localize' => 2])
        ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready);
});

it('localizes only outdated locales and never calls English authoring', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    $requestedLocales = [];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;
            throw new RuntimeException('authoring must not run');
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($calls, $requestedLocales) implements IngredientGuidanceLocalizationClient
    {
        /** @param array<string,int> $calls @param list<list<string>> $requestedLocales */
        public function __construct(private array &$calls, private array &$requestedLocales) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->calls['localize']++;
            $this->requestedLocales[] = $context['locales'] ?? [];
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

    $ingredient->translations()->where('locale', 'fr')->update([
        'source_fingerprint' => $fingerprint,
    ]);
    app(RetryIngredientEnrichmentFailures::class)->handle($admin, $batch);
    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);

    expect($calls)->toBe(['author' => 0, 'localize' => 2])
        ->and($requestedLocales)->toBe([['fr'], ['fr']])
        ->and(data_get($item->fresh()->snapshot, 'guidance_stage_context.localization.expected_locales'))->toBe(['fr'])
        ->and($item->fresh()->result['translations'])->toHaveCount(1)
        ->and($item->fresh()->result['translations'][0]['locale'])->toBe('fr');
});

it('rejects corrupt frozen locales at the localization stage before calling the provider', function (): void {
    $corruptLocales = [
        'empty' => [],
        'duplicate' => ['fr', 'fr'],
        'blank' => ['fr', ''],
        'unsupported' => ['fr', 'xx'],
    ];

    foreach ($corruptLocales as $name => $locales) {
        $calls = ['localize' => 0];
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
                    responseId: 'resp-localization-corrupt',
                    requestId: 'req-localization-corrupt',
                    model: 'gpt-test',
                    inputTokens: 3,
                    outputTokens: 4,
                );
            }
        });

        $fixture = guidanceResumeFixture();
        $fixture['batch']->update(['mode' => IngredientEnrichmentBatchMode::GuidanceLocalization]);
        $snapshot = $fixture['item']->snapshot;
        data_set($snapshot, 'guidance_stage_context.localization.expected_locales', $locales);
        $fixture['item']->update([
            'status' => IngredientEnrichmentItemStatus::Pending,
            'snapshot' => $snapshot,
        ]);

        expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
            ->toThrow(LogicException::class);

        expect($calls)->toBe(['localize' => 0])
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('failed')
            ->and(data_get($fixture['item']->fresh()->research_stages, 'validation'))->toBeNull();
    }
});

it('orders first localization provider locales by configured target locale order', function (): void {
    config()->set('interface-translations.catalogue_locales', ['fr', 'de', 'es', 'it', 'nl', 'pt_BR']);
    $requestedLocales = [];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class implements IngredientGuidanceAuthoringClient
    {
        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            throw new RuntimeException('authoring must not run');
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($requestedLocales) implements IngredientGuidanceLocalizationClient
    {
        /** @param list<list<string>> $requestedLocales */
        public function __construct(private array &$requestedLocales) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->requestedLocales[] = $context['locales'] ?? [];

            return new IngredientGuidanceLocalizationResponse(
                translations: collect($context['locales'] ?? [])->reverse()->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->values()->all(),
                responseId: 'resp-localization-order',
                requestId: 'req-localization-order',
                model: 'gpt-test',
                inputTokens: 3,
                outputTokens: 4,
            );
        }
    });

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => guidanceText(),
    ]);
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
        'source_fingerprint' => str_repeat('a', 64),
        'origin' => 'ai_generated',
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::Pending,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'snapshot' => $context,
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
    ]);

    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);

    expect($requestedLocales)->toBe([['fr', 'de']])
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Warning)
        ->and(collect($item->fresh()->result['translations'])->pluck('locale')->all())->toBe(['fr', 'de']);
});

it('uses the runner frozen localization context after a translation update race', function (): void {
    config()->set('interface-translations.catalogue_locales', ['de', 'fr', 'es', 'it', 'nl', 'pt_BR']);
    $requestedLocales = [];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class implements IngredientGuidanceAuthoringClient
    {
        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            throw new RuntimeException('authoring must not run');
        }
    });
    app()->instance(IngredientGuidanceLocalizationClient::class, new class($requestedLocales) implements IngredientGuidanceLocalizationClient
    {
        /** @param list<list<string>> $requestedLocales */
        public function __construct(private array &$requestedLocales) {}

        public function localize(array $context): IngredientGuidanceLocalizationResponse
        {
            $this->requestedLocales[] = $context['locales'] ?? [];

            return new IngredientGuidanceLocalizationResponse(
                translations: collect($context['locales'] ?? [])->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->all(),
                responseId: 'resp-localization-race',
                requestId: 'req-localization-race',
                model: 'gpt-test',
                inputTokens: 3,
                outputTokens: 4,
            );
        }
    });

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Olive oil',
        'info_markdown' => guidanceText(),
    ]);
    $ingredient->translations()->createMany([
        [
            'locale' => 'fr',
            'display_name' => 'Huile d’olive',
            'info_markdown' => localizedGuidanceText(),
            'source_fingerprint' => str_repeat('a', 64),
            'origin' => 'ai_generated',
        ],
        [
            'locale' => 'de',
            'display_name' => 'Olivenöl',
            'info_markdown' => localizedGuidanceText(),
            'source_fingerprint' => str_repeat('a', 64),
            'origin' => 'ai_generated',
        ],
    ]);
    $batch = IngredientEnrichmentBatch::factory()->create([
        'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization,
        'status' => IngredientEnrichmentBatchStatus::Pending,
        'total_count' => 1,
        'pending_count' => 1,
    ]);
    $context = app(IngredientGuidanceContextBuilder::class)->build($ingredient);
    data_set($context, 'guidance_stage_context.localization.expected_locales', ['fr', 'de']);
    $item = IngredientEnrichmentBatchItem::factory()->for($batch, 'batch')->for($ingredient)->create([
        'snapshot' => $context,
        'source_fingerprint' => app(IngredientEnrichmentSnapshotBuilder::class)->fingerprint($ingredient),
    ]);

    app()->instance(IngredientGuidanceStageRunner::class, new class($ingredient) extends IngredientGuidanceStageRunner
    {
        public function __construct(private Ingredient $ingredient)
        {
            parent::__construct(
                app(IngredientEnrichmentStageStore::class),
                app(IngredientGuidanceRefreshResultValidator::class),
                app(IngredientEnrichmentSnapshotBuilder::class),
                app(IngredientTranslationSourceFingerprint::class),
                app(LocalizedGuidanceHeadings::class),
            );
        }

        public function run(
            int $itemId,
            IngredientEnrichmentResearchStage $stage,
            callable $callback,
        ): IngredientSourceStageResult {
            return parent::run($itemId, $stage, function (array $context) use ($stage, $callback): IngredientSourceStageResult {
                if ($stage === IngredientEnrichmentResearchStage::AiGuidanceLocalization) {
                    $fingerprint = app(IngredientTranslationSourceFingerprint::class)->forIngredient($this->ingredient->fresh());
                    $this->ingredient->translations()->update(['source_fingerprint' => $fingerprint]);
                }

                return $callback($context);
            });
        }
    });

    app(IngredientGuidanceRefreshProcessor::class)->handle($item->id);

    expect($requestedLocales)->toBe([['de', 'fr']])
        ->and($item->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Warning);
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

it('rejects a validation cache when cached localization output changes', function (): void {
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
                responseId: 'resp-guidance-output-fingerprint',
                requestId: 'req-guidance-output-fingerprint',
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
                ])->values()->all(),
                responseId: 'resp-localization-output-fingerprint',
                requestId: 'req-localization-output-fingerprint',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    $stages = $fixture['item']->fresh()->research_stages;
    $localization = $stages['ai_guidance_localization'];
    $marker = 'Mutated cached localization output.';
    $localization['data']['translations'][0]['info_markdown'] .= "\n{$marker}";
    $fixture['item']->update([
        'status' => IngredientEnrichmentItemStatus::Failed,
        'research_stages' => [
            ...$stages,
            'ai_guidance_localization' => $localization,
        ],
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class, 'provenance context');

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('completed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'validation.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    $completed = $fixture['item']->fresh();
    $mutatedTranslation = collect($completed->result['translations'])
        ->firstWhere('locale', data_get($localization, 'data.translations.0.locale'));
    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($completed->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($mutatedTranslation['info_markdown'] ?? '')->toContain($marker)
        ->and(data_get($completed->research_stages, 'validation.status'))->toBe('completed');
});

it('fails a corrupt completed authoring stage and retries authoring from that stage', function (): void {
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
                responseId: 'resp-guidance-retry',
                requestId: 'req-guidance-retry',
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
                responseId: 'resp-localization-retry',
                requestId: 'req-localization-retry',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    $fixture['item']->update([
        'research_stages' => [
            'ai_guidance_authoring' => storedGuidanceStage('ai_guidance_authoring', [
                'guidance' => ['warnings' => [], 'unresolved_questions' => []],
                'provider_response_id' => 'stored-guidance',
                'provider_request_id' => 'stored-request',
                'provider_model' => 'gpt-stored',
                'input_tokens' => 11,
                'output_tokens' => 22,
            ]),
        ],
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class, 'provenance context');

    expect($calls)->toBe(['author' => 0, 'localize' => 0])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    $completed = $fixture['item']->fresh();
    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($completed->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($completed->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($completed->research_stages, 'ai_guidance_localization.status'))->toBe('completed');
});

it('fails an ambiguous completed localization stage before retrying its upstream authoring', function (): void {
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
                responseId: 'resp-guidance-ambiguous-cache',
                requestId: 'req-guidance-ambiguous-cache',
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
                responseId: 'resp-localization-retry',
                requestId: 'req-localization-retry',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    $fixture['item']->update([
        'research_stages' => [
            'ai_guidance_authoring' => storedGuidanceStage('ai_guidance_authoring', [
                'guidance' => ['info_markdown' => guidanceText(), 'warnings' => [], 'unresolved_questions' => []],
                'provider_response_id' => 'stored-guidance',
                'provider_request_id' => 'stored-request',
                'provider_model' => 'gpt-stored',
                'input_tokens' => 11,
                'output_tokens' => 22,
            ]),
            'ai_guidance_localization' => storedGuidanceStage('ai_guidance_localization', [
                'provider_response_id' => 'stored-localization',
                'provider_request_id' => 'stored-localization-request',
                'provider_model' => 'gpt-stored',
                'input_tokens' => 33,
                'output_tokens' => 44,
            ]),
        ],
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class, 'provenance context');

    expect($calls)->toBe(['author' => 0, 'localize' => 0])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    $completed = $fixture['item']->fresh();
    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($completed->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($completed->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($completed->research_stages, 'ai_guidance_localization.status'))->toBe('completed');
});

it('fails a completed authoring stage with malformed accounting instead of dropping token usage', function (): void {
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
                responseId: 'resp-guidance-retry',
                requestId: 'req-guidance-retry',
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
                responseId: 'resp-localization-retry',
                requestId: 'req-localization-retry',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();
    $fixture['item']->update([
        'research_stages' => [
            'ai_guidance_authoring' => storedGuidanceStage('ai_guidance_authoring', [
                'guidance' => ['info_markdown' => guidanceText(), 'warnings' => [], 'unresolved_questions' => []],
                'provider_response_id' => 'stored-guidance',
                'provider_request_id' => 'stored-request',
                'provider_model' => 'gpt-stored',
                'output_tokens' => 22,
            ]),
        ],
    ]);

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class, 'provenance context');

    expect($calls)->toBe(['author' => 0, 'localize' => 0])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed');

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    $completed = $fixture['item']->fresh();
    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and($completed->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and($completed->input_tokens)->toBe(44)
        ->and($completed->output_tokens)->toBe(66);
});

it('validates malformed fresh stage accounting before persisting completion', function (): void {
    $fixture = guidanceResumeFixture();
    $runner = app(IngredientGuidanceStageRunner::class);
    $result = new IngredientSourceStageResult(
        stage: IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
        status: 'completed',
        data: [
            'guidance' => [
                'info_markdown' => guidanceText(),
                'warnings' => [],
                'unresolved_questions' => [],
            ],
            'provider_response_id' => 'resp-guidance',
            'provider_request_id' => 'req-guidance',
            'provider_model' => 'gpt-test',
            'input_tokens' => '11',
            'output_tokens' => 22,
        ],
    );

    expect(fn () => $runner->run(
        $fixture['item']->id,
        IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
        fn (): IngredientSourceStageResult => $result,
    ))->toThrow(LogicException::class, 'input_tokens');

    expect(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.data'))->toBe([]);
});

it('fails cached authoring when guidance headings or shape are invalid', function (): void {
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
                responseId: 'resp-guidance-cached-shape',
                requestId: 'req-guidance-cached-shape',
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
                responseId: 'resp-localization-cached-shape',
                requestId: 'req-localization-cached-shape',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $mutations = [
        'headings' => fn (array $guidance): array => [
            ...$guidance,
            'info_markdown' => str_replace('## Overview', '## Summary', $guidance['info_markdown']),
        ],
        'shape' => fn (array $guidance): array => [...$guidance, 'unexpected' => 'not allowed'],
    ];

    foreach ($mutations as $mutate) {
        $calls = ['author' => 0, 'localize' => 0];
        $fixture = guidanceResumeFixture();
        $guidance = $mutate([
            'info_markdown' => guidanceText(),
            'warnings' => [],
            'unresolved_questions' => [],
        ]);
        $fixture['item']->update([
            'research_stages' => [
                'ai_guidance_authoring' => storedGuidanceStage('ai_guidance_authoring', [
                    'guidance' => $guidance,
                    'provider_response_id' => 'stored-guidance',
                    'provider_request_id' => 'stored-request',
                    'provider_model' => 'gpt-stored',
                    'input_tokens' => 11,
                    'output_tokens' => 22,
                ]),
            ],
        ]);

        expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
            ->toThrow(LogicException::class);

        expect($calls)->toBe(['author' => 0, 'localize' => 0])
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed')
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization'))->toBeNull();

        app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
        app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

        expect($calls)->toBe(['author' => 1, 'localize' => 1])
            ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed');
    }
});

it('fails fresh authoring with invalid headings before localization', function (): void {
    $calls = ['author' => 0, 'localize' => 0];
    app()->instance(IngredientGuidanceAuthoringClient::class, new class($calls) implements IngredientGuidanceAuthoringClient
    {
        /** @param array<string,int> $calls */
        public function __construct(private array &$calls) {}

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->calls['author']++;
            $infoMarkdown = $this->calls['author'] === 1
                ? str_replace('## Overview', '## Summary', guidanceText())
                : guidanceText();

            return new IngredientGuidanceAuthoringResponse(
                guidance: ['info_markdown' => $infoMarkdown, 'warnings' => [], 'unresolved_questions' => []],
                responseId: 'resp-guidance-fresh-shape',
                requestId: 'req-guidance-fresh-shape',
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
                responseId: 'resp-localization-fresh-shape',
                requestId: 'req-localization-fresh-shape',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class);

    expect($calls)->toBe(['author' => 1, 'localize' => 0])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('failed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization'))->toBeNull();

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    expect($calls)->toBe(['author' => 2, 'localize' => 1])
        ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed');
});

it('fails fresh localization when a response has an unexpected locale before validation', function (): void {
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
                responseId: 'resp-guidance-fresh-localization',
                requestId: 'req-guidance-fresh-localization',
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
            $translations = $this->calls['localize'] === 1
                ? [['locale' => 'xx', 'info_markdown' => localizedGuidanceText()]]
                : collect($context['locales'] ?? [])->map(fn (string $locale): array => [
                    'locale' => $locale,
                    'info_markdown' => localizedGuidanceText(),
                ])->all();

            return new IngredientGuidanceLocalizationResponse(
                translations: $translations,
                responseId: 'resp-localization-fresh-localization',
                requestId: 'req-localization-fresh-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $fixture = guidanceResumeFixture();

    expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
        ->toThrow(LogicException::class);

    expect($calls)->toBe(['author' => 1, 'localize' => 1])
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('failed')
        ->and(data_get($fixture['item']->fresh()->research_stages, 'validation'))->toBeNull();

    app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
    app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

    expect($calls)->toBe(['author' => 1, 'localize' => 2])
        ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
        ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('completed');
});

it('fails cached localization when locales or translated content are invalid before validation', function (): void {
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
                responseId: 'resp-guidance-cached-localization',
                requestId: 'req-guidance-cached-localization',
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
                responseId: 'resp-localization-cached-localization',
                requestId: 'req-localization-cached-localization',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $mutations = [
        'missing_locale' => function (array $translations): array {
            array_pop($translations);

            return $translations;
        },
        'extra_locale' => fn (array $translations): array => [
            ...$translations,
            ['locale' => 'xx', 'info_markdown' => localizedGuidanceText()],
        ],
        'malformed_content' => fn (array $translations): array => [
            ...$translations,
            ['locale' => 'fr', 'info_markdown' => ''],
        ],
    ];

    foreach ($mutations as $mutate) {
        $calls = ['author' => 0, 'localize' => 0];
        $fixture = guidanceResumeFixture();
        app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);
        $stages = $fixture['item']->fresh()->research_stages;
        $localization = $stages['ai_guidance_localization'];
        $localization['data']['translations'] = $mutate($localization['data']['translations']);
        $fixture['item']->update([
            'status' => IngredientEnrichmentItemStatus::Failed,
            'research_stages' => [
                ...$stages,
                'ai_guidance_localization' => $localization,
            ],
        ]);

        expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
            ->toThrow(LogicException::class);

        expect($calls)->toBe(['author' => 1, 'localize' => 1])
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('failed');

        app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
        app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

        expect($calls)->toBe(['author' => 1, 'localize' => 2])
            ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
            ->and(data_get($fixture['item']->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('completed');
    }
});

it('fails cached validation when its envelope context or normalized result is inconsistent', function (): void {
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
                responseId: 'resp-guidance-context',
                requestId: 'req-guidance-context',
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
                responseId: 'resp-localization-context',
                requestId: 'req-localization-context',
                model: 'gpt-test',
                inputTokens: 33,
                outputTokens: 44,
            );
        }
    });

    $mismatches = [
        'mode' => function (array $result): array {
            return [...$result, 'mode' => IngredientEnrichmentBatchMode::GuidanceLocalization->value];
        },
        'subject' => function (array $result): array {
            return [...$result, 'subject_public_id' => 'another-ingredient'];
        },
        'fingerprint' => function (array $result): array {
            return [...$result, 'source_fingerprint' => hash('sha256', 'unrelated-input')];
        },
        'report' => static fn (array $result): array => $result,
        'report_errors' => static fn (array $result): array => $result,
        'report_warnings' => static fn (array $result): array => $result,
        'normalized' => function (array $result): array {
            return [...$result, 'info_markdown' => $result['info_markdown'].'\nmutated'];
        },
        'missing_result' => static fn (array $result): array => $result,
        'missing_report' => static fn (array $result): array => $result,
    ];

    foreach ($mismatches as $name => $mutate) {
        $calls = ['author' => 0, 'localize' => 0];
        $fixture = guidanceResumeFixture();
        app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);
        $validValidation = $fixture['item']->fresh()->research_stages['validation'];
        $validResult = $validValidation['data']['result'];
        $corruptValidation = $validValidation;
        if ($name === 'normalized') {
            $corruptValidation['data']['validation_report']['normalized'] = $mutate($validResult);
        } elseif ($name === 'report') {
            $corruptValidation['data']['validation_report']['valid'] = false;
        } elseif ($name === 'report_errors') {
            $corruptValidation['data']['validation_report']['errors'] = ['result' => ['stale report']];
        } elseif ($name === 'report_warnings') {
            $corruptValidation['data']['validation_report']['warnings'] = ['stale report'];
        } elseif ($name === 'missing_result') {
            unset($corruptValidation['data']['result']);
        } elseif ($name === 'missing_report') {
            unset($corruptValidation['data']['validation_report']);
        } else {
            $corruptValidation['data']['result'] = $mutate($validResult);
        }
        $fixture['item']->update([
            'status' => IngredientEnrichmentItemStatus::Failed,
            'research_stages' => [
                ...$fixture['item']->fresh()->research_stages,
                'validation' => $corruptValidation,
            ],
        ]);

        expect(fn () => app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id))
            ->toThrow(LogicException::class);

        expect($calls)->toBe(['author' => 1, 'localize' => 1])
            ->and(data_get($fixture['item']->fresh()->research_stages, 'validation.status'))->toBe('failed');

        app(RetryIngredientEnrichmentFailures::class)->handle($fixture['admin'], $fixture['batch']);
        app(IngredientGuidanceRefreshProcessor::class)->handle($fixture['item']->id);

        expect($calls)->toBe(['author' => 1, 'localize' => 1])
            ->and($fixture['item']->fresh()->status)->toBe(IngredientEnrichmentItemStatus::Ready)
            ->and(data_get($fixture['item']->fresh()->research_stages, 'validation.status'))->toBe('completed');
    }
});

/**
 * @return array{admin: User, ingredient: Ingredient, batch: IngredientEnrichmentBatch, item: IngredientEnrichmentBatchItem}
 */
function guidanceResumeFixture(): array
{
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

    return compact('admin', 'ingredient', 'batch', 'item');
}

/**
 * @param  array<string,mixed>  $data
 * @return array<string,mixed>
 */
function storedGuidanceStage(string $stage, array $data): array
{
    return [
        'stage' => $stage,
        'status' => 'completed',
        'data' => $data,
        'evidence' => [],
        'warnings' => [],
        'unresolved_questions' => [],
        'source_calls' => 0,
    ];
}

function guidanceText(): string
{
    return "## Overview\nOlive oil is a plant-derived fixed oil with a defined fatty-acid profile.\n\n## Formulation use\nIts material-specific profile supports a light emollient contribution and helps select it when a fluid oil phase is needed. ".str_repeat('Use the measured material grade and review the complete formula. ', 10);
}

function localizedGuidanceText(): string
{
    return "## Vue d’ensemble\nCette huile végétale apporte un profil lipidique défini.\n\n## Utilisation en formulation\nSon profil aide à choisir une phase huileuse fluide pour une formule adaptée. ".str_repeat('Évaluer la qualité et la formule complète. ', 10);
}
