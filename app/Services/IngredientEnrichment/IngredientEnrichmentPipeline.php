<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientEditorialClient;
use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientEnrichmentPipelineResponse;
use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientEnrichment\Sources\CosingCheckerClient;
use App\Services\IngredientEnrichment\Sources\EurLexGlossaryClient;
use App\Services\IngredientEnrichment\Sources\FdaColourAdditiveClient;
use App\Services\IngredientEnrichment\Sources\OpenFdaSubstanceClient;
use Throwable;

class IngredientEnrichmentPipeline
{
    public function __construct(
        private readonly IngredientEnrichmentStageStore $stages,
        private readonly IngredientIdentityMatchService $identityMatcher,
        private readonly CosingCheckerClient $cosing,
        private readonly EurLexGlossaryClient $eurLex,
        private readonly OpenFdaSubstanceClient $openFda,
        private readonly FdaColourAdditiveClient $fdaColours,
        private readonly UsIngredientDeclarationService $usDeclarations,
        private readonly IngredientEnrichmentFactsBuilder $facts,
        private readonly IngredientEditorialClient $editorial,
        private readonly IngredientGuidanceAuthoringClient $guidanceAuthoring,
        private readonly IngredientGuidanceLocalizationClient $guidanceLocalization,
        private readonly OpenAiIngredientGapResearchClient $gapResearch,
        private readonly LocalizedGuidanceHeadings $localizedGuidanceHeadings,
    ) {}

    public function run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse
    {
        $item = IngredientEnrichmentBatchItem::query()->findOrFail($itemId);
        $input = $this->input($item);
        $identity = $this->runStage($itemId, IngredientEnrichmentResearchStage::IdentityPreparation, function () use ($input): IngredientSourceStageResult {
            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::IdentityPreparation,
                status: 'completed',
                data: ['record' => $input['record']],
            );
        });
        $record = is_array($identity->data['record'] ?? null) ? $identity->data['record'] : $input['record'];

        $usIdentity = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::UsIdentity,
            fn (): IngredientSourceStageResult => $this->openFda->lookup($record),
        );
        $usIdentitySelection = $this->identityMatcher->select($usIdentity->data['candidates'] ?? [], $record);
        $record = $this->withUsInciIdentity($record, $usIdentitySelection['candidate']);
        $euStructured = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::EuStructured,
            fn (): IngredientSourceStageResult => $this->cosing->lookup($record),
        );
        $euIdentity = $this->identityMatcher->select($euStructured->data['candidates'] ?? [], $record);
        if ($euIdentity['candidate'] === null && is_string($record['inci_name'] ?? null)) {
            $euIdentity = $this->identityMatcher->select(
                $euStructured->data['candidates'] ?? [],
                [
                    ...$record,
                    'inci_name' => trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $record['inci_name']) ?? $record['inci_name']),
                ],
            );
        }
        $euCandidate = $euIdentity['candidate'];
        $euOfficial = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::EuOfficial,
            fn (): IngredientSourceStageResult => $this->eurLex->verify([
                'inci_name' => is_array($euCandidate) ? ($euCandidate['inci_name'] ?? null) : ($record['inci_name'] ?? null),
                'soap_inci_naoh_name' => data_get($euStructured->data, 'soap_salts.naoh.inci_name'),
                'soap_inci_koh_name' => data_get($euStructured->data, 'soap_salts.koh.inci_name'),
            ]),
        );
        $usCandidate = $this->identityMatcher->select(
            $usIdentity->data['candidates'] ?? [],
            $record,
        )['candidate'] ?? [];
        $usDeclaration = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::UsDeclaration,
            fn (): IngredientSourceStageResult => $this->usDeclaration($record, is_array($usCandidate) ? $usCandidate : [], $euOfficial),
        );
        $conflict = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::ConflictEvaluation,
            fn (): IngredientSourceStageResult => new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::ConflictEvaluation,
                status: 'completed',
                data: ['facts' => $this->facts->build($record, $euStructured, $euOfficial, $usIdentity, $usDeclaration)],
            ),
        );
        $facts = is_array($conflict->data['facts'] ?? null) ? $conflict->data['facts'] : [];
        $editorialFacts = [
            ...$facts,
            'catalog_key' => $input['catalog_key'],
            'vocabulary' => $input['vocabulary'],
        ];
        $guidanceResearch = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::AiGuidanceResearch,
            function () use ($editorialFacts, $allowGapResearch): IngredientSourceStageResult {
                $shouldResearch = $allowGapResearch
                    || (bool) config('ingredient-enrichment.openai.guidance_research.enabled', true);

                if (! $shouldResearch) {
                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceResearch,
                        status: 'completed',
                        data: $this->emptyGuidanceResearchData(),
                    );
                }

                $response = $this->gapResearch->research($editorialFacts);

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceResearch,
                    status: 'completed',
                    data: [
                        'performed' => true,
                        'candidate_evidence' => $response->candidateEvidence,
                        'warnings' => $response->warnings,
                        'unresolved_questions' => $response->unresolvedQuestions,
                        'sources' => $response->sources,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                        'web_search_calls' => $response->webSearchCalls,
                    ],
                );
            },
        );
        $editorial = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::AiEditorial,
            function () use ($editorialFacts, $guidanceResearch): IngredientSourceStageResult {
                if (($guidanceResearch->data['performed'] ?? false) === true) {
                    $editorialFacts['gap_research'] = [
                        'candidate_evidence' => $guidanceResearch->data['candidate_evidence'] ?? [],
                        'warnings' => $guidanceResearch->data['warnings'] ?? [],
                        'unresolved_questions' => $guidanceResearch->data['unresolved_questions'] ?? [],
                    ];
                }
                $response = $this->editorial->edit($editorialFacts);

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiEditorial,
                    status: 'completed',
                    data: [
                        'editorial' => $response->editorial,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                        'web_search_calls' => $response->webSearchCalls,
                    ],
                );
            },
        );
        $metadataValues = is_array($editorial->data['editorial'] ?? null) ? $editorial->data['editorial'] : [];
        $legacyGuidance = array_key_exists('info_markdown', $metadataValues)
            ? [
                'info_markdown' => is_string($metadataValues['info_markdown'] ?? null)
                    ? $metadataValues['info_markdown']
                    : '',
                'warnings' => $metadataValues['warnings'] ?? [],
                'unresolved_questions' => $metadataValues['unresolved_questions'] ?? [],
            ]
            : null;
        $guidance = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            function () use ($editorialFacts, $guidanceResearch, $legacyGuidance): IngredientSourceStageResult {
                if ($legacyGuidance !== null) {
                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
                        status: 'completed',
                        data: [
                            'guidance' => $legacyGuidance,
                            'provider_response_id' => '',
                            'provider_request_id' => '',
                            'provider_model' => '',
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                        ],
                    );
                }

                $context = [
                    ...$editorialFacts,
                    'guidance_evidence' => $this->guidanceEvidence($guidanceResearch),
                ];
                $response = $this->guidanceAuthoring->author($context);

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
                    status: 'completed',
                    data: [
                        'guidance' => $response->guidance,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                    ],
                );
            },
        );
        $metadataValues['info_markdown'] = (string) data_get($guidance->data, 'guidance.info_markdown', '');
        $metadataValues['warnings'] = collect($metadataValues['warnings'] ?? [])
            ->merge(data_get($guidance->data, 'guidance.warnings', []))
            ->merge($guidanceResearch->data['warnings'] ?? [])
            ->filter()->unique()->values()->all();
        $metadataValues['unresolved_questions'] = collect($metadataValues['unresolved_questions'] ?? [])
            ->merge(data_get($guidance->data, 'guidance.unresolved_questions', []))
            ->merge($guidanceResearch->data['unresolved_questions'] ?? [])
            ->filter()->unique()->values()->all();
        $soapmakingRelevant = (bool) ($metadataValues['soapmaking_relevant'] ?? false);
        $metadataTranslations = collect($metadataValues['translations'] ?? [])
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->map(fn (array $translation): array => collect($translation)->only([
                'locale', 'display_name', 'saponification_name',
            ])->all())
            ->values()
            ->all();
        $localization = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            function () use ($metadataTranslations, $metadataValues, $guidance, $legacyGuidance): IngredientSourceStageResult {
                if ($legacyGuidance !== null) {
                    $legacyTranslations = collect($metadataValues['translations'] ?? [])
                        ->filter(fn (mixed $translation): bool => is_array($translation))
                        ->map(fn (array $translation): array => [
                            'locale' => (string) ($translation['locale'] ?? ''),
                            'info_markdown' => is_string($translation['info_markdown'] ?? null)
                                ? $translation['info_markdown']
                                : '',
                        ])
                        ->values()
                        ->all();

                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                        status: 'completed',
                        data: [
                            'translations' => $legacyTranslations,
                            'provider_response_id' => '',
                            'provider_request_id' => '',
                            'provider_model' => '',
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                        ],
                    );
                }

                $legacyTranslations = collect($metadataValues['translations'] ?? [])
                    ->filter(fn (mixed $translation): bool => is_array($translation)
                        && array_key_exists('info_markdown', $translation))
                    ->map(fn (array $translation): array => [
                        'locale' => (string) ($translation['locale'] ?? ''),
                        'info_markdown' => is_string($translation['info_markdown'] ?? null)
                            ? $translation['info_markdown']
                            : '',
                    ])
                    ->values()
                    ->all();
                if ($legacyTranslations !== []) {
                    return new IngredientSourceStageResult(
                        stage: IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                        status: 'completed',
                        data: [
                            'translations' => $legacyTranslations,
                            'provider_response_id' => '',
                            'provider_request_id' => '',
                            'provider_model' => '',
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                        ],
                    );
                }

                $localizationContext = [
                    'locales' => collect($metadataTranslations)->pluck('locale')->filter()->values()->all(),
                    'english_guidance' => (string) data_get($guidance->data, 'guidance.info_markdown', ''),
                    'soapmaking_relevant' => (bool) ($metadataValues['soapmaking_relevant'] ?? false),
                    'localized_headings' => config('ingredient-enrichment.guidance.localized_headings', []),
                    'metadata_translations' => $metadataTranslations,
                ];
                $response = $this->guidanceLocalization->localize($localizationContext);

                return new IngredientSourceStageResult(
                    stage: IngredientEnrichmentResearchStage::AiGuidanceLocalization,
                    status: 'completed',
                    data: [
                        'translations' => $response->translations,
                        'provider_response_id' => $response->responseId,
                        'provider_request_id' => $response->requestId,
                        'provider_model' => $response->model,
                        'input_tokens' => $response->inputTokens,
                        'output_tokens' => $response->outputTokens,
                    ],
                );
            },
        );
        $localizedGuidance = collect($localization->data['translations'] ?? [])
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->keyBy('locale');
        $metadataValues['translations'] = collect($metadataTranslations)
            ->map(function (array $translation) use ($localizedGuidance, $soapmakingRelevant): array {
                $locale = (string) ($translation['locale'] ?? '');
                $guidance = (string) data_get($localizedGuidance->get($locale), 'info_markdown', '');

                return [
                    ...$translation,
                    'info_markdown' => $this->localizedGuidanceHeadings->normalize($guidance, $locale, $soapmakingRelevant),
                ];
            })
            ->values()
            ->all();
        $metadataValues['guidance_evidence'] = $this->guidanceEvidence($guidanceResearch);
        $validation = $this->runStage(
            $itemId,
            IngredientEnrichmentResearchStage::Validation,
            fn (): IngredientSourceStageResult => new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::Validation,
                status: 'completed',
                data: ['result' => $this->result($input, $facts, $metadataValues)],
            ),
        );
        $result = is_array($validation->data['result'] ?? null) ? $validation->data['result'] : [];
        $completed = $this->stagesFor($itemId);

        return new IngredientEnrichmentPipelineResponse(
            result: $result,
            sources: collect($facts['evidence'] ?? [])
                ->filter(fn (mixed $evidence): bool => is_array($evidence) && is_string($evidence['source_url'] ?? null))
                ->map(fn (array $evidence): array => [
                    'url' => (string) $evidence['source_url'],
                    'title' => (string) ($evidence['source_name'] ?? $evidence['source_url']),
                ])
                ->merge($guidanceResearch->data['sources'] ?? [])
                ->unique('url')
                ->values()
                ->all(),
            providerResponseId: (string) (
                $localization->data['provider_response_id']
                    ?? $guidance->data['provider_response_id']
                    ?? $editorial->data['provider_response_id']
                    ?? ''
            ),
            providerRequestId: (string) (
                $localization->data['provider_request_id']
                    ?? $guidance->data['provider_request_id']
                    ?? $editorial->data['provider_request_id']
                    ?? ''
            ),
            providerModel: (string) (
                $localization->data['provider_model']
                    ?? $guidance->data['provider_model']
                    ?? $editorial->data['provider_model']
                    ?? ''
            ),
            inputTokens: (int) ($editorial->data['input_tokens'] ?? 0)
                + (int) ($guidance->data['input_tokens'] ?? 0)
                + (int) ($localization->data['input_tokens'] ?? 0)
                + (int) ($guidanceResearch->data['input_tokens'] ?? 0),
            outputTokens: (int) ($editorial->data['output_tokens'] ?? 0)
                + (int) ($guidance->data['output_tokens'] ?? 0)
                + (int) ($localization->data['output_tokens'] ?? 0)
                + (int) ($guidanceResearch->data['output_tokens'] ?? 0),
            webSearchCalls: (int) ($editorial->data['web_search_calls'] ?? 0)
                + (int) ($guidance->data['web_search_calls'] ?? 0)
                + (int) ($localization->data['web_search_calls'] ?? 0)
                + (int) ($guidanceResearch->data['web_search_calls'] ?? 0),
            structuredSourceCalls: collect($completed)->sum(fn (array $stage): int => (int) ($stage['source_calls'] ?? 0)),
        );
    }

    /** @return array<string, mixed> */
    private function emptyGuidanceResearchData(): array
    {
        return [
            'performed' => false,
            'candidate_evidence' => [],
            'warnings' => [],
            'unresolved_questions' => [],
            'sources' => [],
            'provider_response_id' => '',
            'provider_request_id' => '',
            'provider_model' => '',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'web_search_calls' => 0,
        ];
    }

    /**
     * @return list<array{source_name: string, source_url: string, summary: string, source_tier: string, retrieved_at: string}>
     */
    private function guidanceEvidence(IngredientSourceStageResult $guidanceResearch): array
    {
        return collect($guidanceResearch->data['candidate_evidence'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row)
                && ($row['field'] ?? null) === 'proposal.info_markdown'
                && is_string($row['source_name'] ?? null)
                && is_string($row['source_url'] ?? null)
                && is_string($row['summary'] ?? null))
            ->map(fn (array $row): array => [
                'source_name' => trim((string) $row['source_name']),
                'source_url' => trim((string) $row['source_url']),
                'summary' => trim((string) $row['summary']),
                'source_tier' => 'editorial',
                'retrieved_at' => (string) ($row['retrieved_at'] ?? now()->toImmutable()->toIso8601String()),
            ])
            ->filter(fn (array $row): bool => $row['source_name'] !== ''
                && $row['source_url'] !== ''
                && $row['summary'] !== '')
            ->unique('source_url')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function withUsInciIdentity(array $record, ?array $candidate): array
    {
        if (is_string($record['inci_name'] ?? null) && trim($record['inci_name']) !== '') {
            return $record;
        }

        $inciName = is_array($candidate)
            ? collect($candidate['inci_names'] ?? [])->first(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            : null;

        return is_string($inciName) ? [...$record, 'inci_name' => trim($inciName)] : $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $candidate
     */
    private function usDeclaration(
        array $record,
        array $candidate,
        IngredientSourceStageResult $euOfficial,
    ): IngredientSourceStageResult {
        if (($record['category'] ?? null) !== IngredientCategory::Colourants->value
            && ($record['research_family'] ?? null) !== 'colourants') {
            $verifiedInciName = $euOfficial->data['common_ingredient_name'] ?? $record['inci_name'] ?? null;

            return $this->usDeclarations->propose(
                candidate: $candidate,
                verifiedInciName: is_string($verifiedInciName) ? $verifiedInciName : null,
            );
        }

        $names = collect([
            $euOfficial->data['common_ingredient_name'] ?? null,
            $record['inci_name'] ?? null,
            $record['display_name'] ?? null,
            $candidate['common_name'] ?? null,
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->values()
            ->all();
        $fda = $this->fdaColours->lookup(['names' => $names]);
        $matches = $fda->data['matches'] ?? [];

        if (! is_array($matches) || count($matches) !== 1 || ! is_array($matches[0])) {
            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::UsDeclaration,
                status: 'completed',
                data: ['market_code' => 'us', 'declaration_name' => null, 'confidence' => 'unresolved'],
                evidence: $fda->evidence,
                warnings: $fda->warnings,
                unresolvedQuestions: $fda->unresolvedQuestions,
                sourceCalls: $fda->sourceCalls,
            );
        }

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::UsDeclaration,
            status: 'completed',
            data: [
                'market_code' => 'us',
                'declaration_name' => $matches[0]['declaration_name'] ?? null,
                'confidence' => 'verified',
                'regulatory_findings' => [$matches[0]],
            ],
            evidence: $fda->evidence,
            warnings: $fda->warnings,
            unresolvedQuestions: $fda->unresolvedQuestions,
            sourceCalls: $fda->sourceCalls,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $editorial
     * @return array<string, mixed>
     */
    private function result(array $input, array $facts, array $editorial): array
    {
        $proposal = is_array($facts['proposal'] ?? null) ? $facts['proposal'] : [];
        $editorialSoapNaoh = filled($editorial['soap_inci_naoh_name'] ?? null)
            ? $editorial['soap_inci_naoh_name']
            : ($proposal['soap_inci_naoh_name'] ?? null);
        $editorialSoapKoh = filled($editorial['soap_inci_koh_name'] ?? null)
            ? $editorial['soap_inci_koh_name']
            : ($proposal['soap_inci_koh_name'] ?? null);
        $proposal = [
            ...$proposal,
            'soap_inci_naoh_name' => $editorialSoapNaoh,
            'soap_inci_koh_name' => $editorialSoapKoh,
            'display_name' => $editorial['display_name'] ?? $proposal['display_name'] ?? null,
            'category' => filled($proposal['category'] ?? null)
                ? $proposal['category']
                : ($editorial['category'] ?? null),
            'subcategory' => filled($proposal['subcategory'] ?? null)
                ? $proposal['subcategory']
                : ($editorial['subcategory'] ?? null),
            'saponification_name' => $editorial['saponification_name'] ?? null,
            'info_markdown' => $editorial['info_markdown'] ?? null,
            'soapmaking_relevant' => $editorial['soapmaking_relevant'] ?? false,
            'translations' => $editorial['translations'] ?? [],
        ];

        return [
            'format' => config('ingredient-enrichment.result_format'),
            'schema_version' => config('ingredient-enrichment.schema_version'),
            'subject_type' => $input['subject_type'],
            'subject_public_id' => $input['subject_public_id'],
            'catalog_key' => $input['catalog_key'],
            'source_fingerprint' => $input['source_fingerprint'],
            'proposal' => $proposal,
            'field_confidence' => [
                ...($facts['field_confidence'] ?? []),
                ...$this->editorialFieldConfidence($editorial, $proposal),
            ],
            'value_provenance' => $this->valueProvenance($input, $facts, $editorial, $proposal),
            'evidence' => [
                ...($facts['evidence'] ?? []),
                ...collect($editorial['guidance_evidence'] ?? [])
                    ->filter(fn (mixed $row): bool => is_array($row))
                    ->map(fn (array $row): array => [
                        'field' => 'proposal.info_markdown',
                        'source_name' => (string) ($row['source_name'] ?? ''),
                        'source_url' => (string) ($row['source_url'] ?? ''),
                        'source_tier' => (string) ($row['source_tier'] ?? 'editorial'),
                        'confidence' => 'supported',
                        'source_version' => null,
                        'source_updated_at' => null,
                        'retrieved_at' => (string) ($row['retrieved_at'] ?? now()->toImmutable()->toIso8601String()),
                    ])
                    ->filter(fn (array $row): bool => $row['source_name'] !== '' && $row['source_url'] !== '')
                    ->all(),
            ],
            'guidance_evidence' => collect($editorial['guidance_evidence'] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->values()
                ->all(),
            'regulatory_findings' => data_get($facts, 'regulatory_findings', []),
            'confidence' => $this->confidence($facts),
            'warnings' => collect([
                ...($facts['warnings'] ?? []),
                ...($facts['conflicts'] ?? []),
                ...($editorial['warnings'] ?? []),
            ])->filter(fn (mixed $warning): bool => is_string($warning) && $warning !== '')
                ->unique()->values()->all(),
            'unresolved_questions' => collect([
                ...($facts['unresolved_questions'] ?? []),
                ...($editorial['unresolved_questions'] ?? []),
            ])->filter(fn (mixed $question): bool => is_string($question) && $question !== '')
                ->unique()->values()->all(),
        ];
    }

    /** @param array<string, mixed> $facts */
    private function confidence(array $facts): string
    {
        if (collect($facts['conflicts'] ?? [])->contains(
            fn (mixed $conflict): bool => is_string($conflict) && $conflict !== '',
        )) {
            return 'low';
        }

        $values = collect($facts['field_confidence'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['confidence'] ?? null))
            ->pluck('confidence');

        return $values->contains(fn (string $value): bool => in_array($value, ['conflicting', 'unresolved'], true))
            ? 'low'
            : ($values->contains('supported') ? 'medium' : 'high');
    }

    /**
     * @param  array<string, mixed>  $editorial
     * @param  array<string, mixed>  $proposal
     * @return list<array{field: string, confidence: string}>
     */
    private function editorialFieldConfidence(array $editorial, array $proposal): array
    {
        return collect([
            ['field' => 'proposal.display_name', 'confidence' => 'supported'],
            ['field' => 'proposal.category', 'confidence' => filled($proposal['category'] ?? null) ? 'supported' : 'unresolved'],
            ['field' => 'proposal.subcategory', 'confidence' => filled($proposal['subcategory'] ?? null) ? 'supported' : 'unresolved'],
            ['field' => 'proposal.saponification_name', 'confidence' => 'supported'],
            ['field' => 'proposal.info_markdown', 'confidence' => 'supported'],
            ['field' => 'proposal.soapmaking_relevant', 'confidence' => 'supported'],
            ...collect($editorial['translations'] ?? [])->keys()->flatMap(fn (int $index): array => [
                ['field' => "proposal.translations.{$index}.display_name", 'confidence' => 'supported'],
                ['field' => "proposal.translations.{$index}.saponification_name", 'confidence' => 'supported'],
                ['field' => "proposal.translations.{$index}.info_markdown", 'confidence' => 'supported'],
            ])->all(),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $editorial
     * @param  array<string, mixed>  $proposal
     * @return list<array{field:string,kind:string,reasoning:string,source_urls:list<string>}>
     */
    private function valueProvenance(array $input, array $facts, array $editorial, array $proposal): array
    {
        $evidence = collect($facts['evidence'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->groupBy('field');
        $confidence = collect($facts['field_confidence'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['field'] ?? null))
            ->keyBy('field');
        $rows = [];

        foreach ([
            'proposal.display_name' => ['kind' => 'ai_proposed', 'reasoning' => 'Written by the editorial pass from the reviewed identity facts.'],
            'proposal.info_markdown' => ['kind' => 'ai_proposed', 'reasoning' => 'Written by the editorial pass from deterministic facts and permitted guidance evidence.'],
            'proposal.soapmaking_relevant' => ['kind' => 'ai_proposed', 'reasoning' => 'Selected by the editorial pass from the reviewed material identity.'],
            'proposal.saponification_name' => ['kind' => filled($editorial['saponification_name'] ?? null) ? 'ai_proposed' : 'unresolved', 'reasoning' => filled($editorial['saponification_name'] ?? null) ? 'Proposed as an editorial soapmaking stem; it is not an INCI name.' : 'No reviewed soapmaking stem was available.'],
        ] as $field => $definition) {
            $rows[] = [
                'field' => $field,
                'kind' => $definition['kind'],
                'reasoning' => $definition['reasoning'],
                'source_urls' => [],
            ];
        }

        $inciField = 'proposal.inci_name';
        $inciEvidence = $evidence->get($inciField, collect());
        $rows[] = [
            'field' => $inciField,
            'kind' => $inciEvidence->isNotEmpty() ? 'source_confirmed' : 'unresolved',
            'reasoning' => $inciEvidence->isNotEmpty()
                ? 'Matched to an exact deterministic source record.'
                : 'No exact deterministic source value was available.',
            'source_urls' => $this->sourceUrls($inciEvidence->all()),
        ];

        foreach (['proposal.category', 'proposal.subcategory'] as $field) {
            $value = data_get($proposal, str($field)->after('proposal.')->value());
            $hasSource = $evidence->get($field, collect())->isNotEmpty();
            $currentValue = data_get($input, 'record.'.str($field)->after('proposal.')->value());
            $isReviewerValue = filled($value) && filled($currentValue) && $value === $currentValue && ! $hasSource;
            $isAiProposal = filled($value) && ! $hasSource && ! $isReviewerValue;
            $rows[] = [
                'field' => $field,
                'kind' => $isReviewerValue
                    ? 'reviewer_supplied'
                    : ($hasSource ? 'source_confirmed' : ($isAiProposal ? 'ai_proposed' : 'unresolved')),
                'reasoning' => $isReviewerValue
                    ? 'Retained from the reviewed catalogue state; research does not silently replace taxonomy.'
                    : ($hasSource
                        ? 'Matched to an exact deterministic source record.'
                        : ($isAiProposal
                            ? 'Proposed from the researched identity and bounded catalogue taxonomy; human review is required.'
                            : 'No defensible taxonomy proposal was available.')),
                'source_urls' => $this->sourceUrls($evidence->get($field, collect())->all()),
            ];
        }

        foreach (['proposal.soap_inci_naoh_name', 'proposal.soap_inci_koh_name'] as $field) {
            $value = data_get($proposal, str($field)->after('proposal.')->value());
            $fieldEvidence = $evidence->get($field, collect());
            $hasSource = $fieldEvidence->isNotEmpty();
            $rows[] = [
                'field' => $field,
                'kind' => filled($value) ? ($hasSource ? 'source_confirmed' : 'ai_proposed') : 'unresolved',
                'reasoning' => filled($value)
                    ? ($hasSource ? 'Matched to an exact deterministic soap entry.' : 'Proposed independently from the reviewed base identity because no exact official salt entry was located.')
                    : 'No independently verified salt declaration was available.',
                'source_urls' => $this->sourceUrls($fieldEvidence->all()),
            ];
        }

        foreach (['aliases', 'identifiers', 'cosing_functions', 'market_labels'] as $collection) {
            foreach (is_array($proposal[$collection] ?? null) ? $proposal[$collection] : [] as $index => $_row) {
                $field = "proposal.{$collection}.{$index}";
                $fieldEvidence = $evidence->get($field, collect());
                $rows[] = [
                    'field' => $field,
                    'kind' => $fieldEvidence->isNotEmpty() ? 'source_confirmed' : 'unresolved',
                    'reasoning' => $fieldEvidence->isNotEmpty() ? 'Matched to the exact cited source record.' : 'No exact correlated source evidence was available.',
                    'source_urls' => $this->sourceUrls($fieldEvidence->all()),
                ];
            }
        }

        foreach (is_array($proposal['translations'] ?? null) ? $proposal['translations'] : [] as $index => $_translation) {
            $rows[] = [
                'field' => "proposal.translations.{$index}",
                'kind' => 'ai_proposed',
                'reasoning' => 'Translated by the editorial pass without changing deterministic identity facts.',
                'source_urls' => [],
            ];
        }

        return collect($rows)->unique('field')->values()->all();
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function sourceUrls(array $rows): array
    {
        return collect($rows)
            ->pluck('source_url')
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{catalog_key: string|null, source_fingerprint: string, subject_type: string, subject_public_id: string, record: array<string, mixed>, vocabulary: array<string, mixed>} */
    private function input(IngredientEnrichmentBatchItem $item): array
    {
        $item->loadMissing('ingredient');
        $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
        $current = is_array($snapshot['current'] ?? null) ? $snapshot['current'] : [];
        $canonical = is_array($current['canonical'] ?? null) ? $current['canonical'] : [];
        $subjectType = (string) ($snapshot['subject_type'] ?? ($item->ingredient_intake_item_id !== null ? 'intake' : 'ingredient'));
        $subjectPublicId = (string) ($snapshot['subject_public_id'] ?? ($item->ingredient?->public_id ?? ''));
        $catalogKey = is_string($snapshot['catalog_key'] ?? null)
            ? $snapshot['catalog_key']
            : ($item->catalog_key !== null ? (string) $item->catalog_key : null);
        $submittedIdentity = is_array($snapshot['subject_identity'] ?? null)
            ? $snapshot['subject_identity']
            : [];

        return [
            'catalog_key' => $catalogKey,
            'source_fingerprint' => (string) ($snapshot['source_fingerprint'] ?? $item->source_fingerprint),
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'record' => [
                'catalog_key' => $catalogKey,
                'subject_type' => $subjectType,
                'subject_public_id' => $subjectPublicId,
                'display_name' => $canonical['display_name'] ?? ($submittedIdentity['current_name'] ?? null),
                'inci_name' => $canonical['inci_name'] ?? ($submittedIdentity['inci_name'] ?? null),
                'category' => $canonical['category'] ?? null,
                'subcategory' => $canonical['subcategory'] ?? null,
                'identifiers' => is_array($current['identifiers'] ?? null) ? $current['identifiers'] : [],
                'aliases' => is_array($current['aliases'] ?? null) ? $current['aliases'] : [],
                'trusted_soap_chemistry' => is_array($current['soap_chemistry'] ?? null)
                    ? $current['soap_chemistry']
                    : null,
                'research_family' => data_get($snapshot, 'research_rules.research_family'),
                'duplicate_context' => data_get($snapshot, 'research_rules.duplicate_context', []),
                'duplicate_resolution' => data_get($snapshot, 'research_rules.duplicate_resolution'),
            ],
            'vocabulary' => is_array($snapshot['vocabulary'] ?? null) ? $snapshot['vocabulary'] : [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function stagesFor(int $itemId): array
    {
        $item = IngredientEnrichmentBatchItem::query()->findOrFail($itemId);

        return $this->stages->stages($item);
    }

    /** @param callable(): IngredientSourceStageResult $callback */
    private function runStage(
        int $itemId,
        IngredientEnrichmentResearchStage $stage,
        callable $callback,
    ): IngredientSourceStageResult {
        $stored = $this->stagesFor($itemId)[$stage->value] ?? null;
        if (is_array($stored) && ($stored['status'] ?? null) === 'completed') {
            return IngredientSourceStageResult::fromArray($stored);
        }

        try {
            $result = $callback();
            if ($result->stage !== $stage) {
                throw new \LogicException("Unexpected enrichment stage {$result->stage->value}.");
            }

            $this->stages->complete($itemId, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->stages->fail($itemId, $stage, $this->safeFailureCode($exception));

            throw $exception;
        }
    }

    private function safeFailureCode(Throwable $exception): string
    {
        if ($exception instanceof IngredientResearchProviderException) {
            return $exception->failureCode;
        }

        return mb_strtolower(class_basename($exception));
    }
}
