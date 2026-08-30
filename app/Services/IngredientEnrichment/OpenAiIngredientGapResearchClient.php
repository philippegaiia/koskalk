<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceResearchClient;
use App\Data\IngredientGapResearchResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

class OpenAiIngredientGapResearchClient implements IngredientGuidanceResearchClient
{
    public function __construct(
        private readonly IngredientGuidanceEvidencePolicy $evidencePolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     *
     * @throws JsonException
     */
    public function research(array $facts): IngredientGapResearchResponse
    {
        if (! config('ingredient-enrichment.openai.gap_research.enabled')
            && ! config('ingredient-enrichment.openai.guidance_research.enabled')) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.gap_research_disabled'));
        }

        $apiKey = trim((string) config('ingredient-enrichment.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.missing_api_key'));
        }

        $url = rtrim((string) config('ingredient-enrichment.openai.base_url'), '/').'/responses';

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout((int) config('ingredient-enrichment.openai.connect_timeout_seconds'))
                ->timeout((int) config('ingredient-enrichment.openai.timeout_seconds'))
                ->retry([250, 1000], when: function (Throwable $exception, PendingRequest $request): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->serverError()));
                }, throw: false)
                ->post($url, [
                    'model' => config('ingredient-enrichment.openai.model'),
                    'reasoning' => ['effort' => config('ingredient-enrichment.openai.reasoning_effort')],
                    'instructions' => $this->instructions(),
                    'input' => $this->input($facts),
                    'tools' => [[
                        'type' => 'web_search',
                    ]],
                    'include' => ['web_search_call.action.sources'],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'ingredient_enrichment_gap_evidence',
                            'strict' => true,
                            'schema' => $this->schema(),
                        ],
                    ],
                    'store' => false,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.provider_failed'), previous: $exception);
        }

        if ($response->failed()) {
            throw $this->providerException($response->status(), $response->json(), (string) $response->header('x-request-id'));
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $outputText = collect($payload['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $output): array => is_array($output['content'] ?? null) ? $output['content'] : [])
            ->firstWhere('type', 'output_text')['text'] ?? null;

        if (! is_string($outputText)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        try {
            $result = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'), previous: $exception);
        }

        if (! is_array($result)
            || ! is_array($result['candidate_evidence'] ?? null)
            || ! is_array($result['warnings'] ?? null)
            || ! is_array($result['unresolved_questions'] ?? null)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $searchCalls = collect($payload['output'] ?? [])
            ->where('type', 'web_search_call');
        $sources = $searchCalls
            ->flatMap(fn (array $output): array => is_array(data_get($output, 'action.sources')) ? data_get($output, 'action.sources') : [])
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['url'] ?? null))
            ->map(fn (array $source): array => [
                'url' => trim($source['url']),
                'title' => trim((string) ($source['title'] ?? $source['url'])),
            ])
            ->unique('url')
            ->values()
            ->all();
        $candidateEvidence = $this->evidencePolicy->validateCandidates(
            $result['candidate_evidence'],
            $sources,
        );

        return new IngredientGapResearchResponse(
            candidateEvidence: $candidateEvidence,
            warnings: $result['warnings'],
            unresolvedQuestions: $result['unresolved_questions'],
            responseId: (string) ($payload['id'] ?? ''),
            requestId: (string) $response->header('x-request-id'),
            model: (string) ($payload['model'] ?? config('ingredient-enrichment.openai.model')),
            inputTokens: (int) data_get($payload, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.output_tokens', 0),
            webSearchCalls: $searchCalls->count(),
            sources: $sources,
        );
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You perform a broad but disciplined guidance-research pass for a cosmetic-ingredient catalogue. Return candidate evidence only. The supplied deterministic facts remain authoritative and must not be changed.

Search the open web for concise, material-specific formulation and soapmaking facts that are missing from the deterministic editorial context. Prioritize manufacturer technical sheets and application notes, supplier technical product pages, peer-reviewed or institutional scientific sources, regulatory references used only editorially, professional formulation references, and recognized specialist references. Reject marketplaces, social or community content, generic blogs, AI-generated or SEO pages, search-result snippets, and unsourced marketing.

Classify each finding as a material-wide fact or a product-grade observation. A product-grade recommendation must remain qualified to that grade. Classify experimental observations separately and never turn them into general recommendations. A recommended percentage is allowed only when the exact source explicitly presents it as formulation guidance from a manufacturer, supplier, professional, or specialist source. Record the correct application (`cosmetics` or `soapmaking`), lower and upper bounds without guessing or converting, and the percentage basis (`total_formula`, `oil_phase`, or `soap_oils`). Keep conflicting ranges as separate rows. If one source gives separate cosmetics and soapmaking ranges, return separate rows. Reported-use and experimental concentrations are not recommendations.

Omit category-obvious filler unless the exact material has a non-obvious practical consequence. You must not establish legal declarations, authorization, identifiers, INCI names, COSING functions, or regulatory conclusions. COSMILE Europe may be cited only for individually paraphrased introductory guidance; it cannot support any legal or identity field.

Every candidate evidence row must contain `field` set to `proposal.info_markdown`, a source name, the exact consulted source URL, a concise paraphrased summary, and the required classification fields. Never copy long passages. If the gap remains unresolved, return an empty candidate_evidence array and state the specific question. Return only the strict JSON object.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $facts
     *
     * @throws JsonException
     */
    private function input(array $facts): string
    {
        return '<ingredient_gap_research_facts>'."\n"
            .json_encode(
                $facts,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n"
            .'</ingredient_gap_research_facts>';
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $candidateEvidence = [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string'],
                'source_name' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'claim_type' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_claim_types'),
                ],
                'source_kind' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_source_kinds'),
                ],
                'scope' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_scopes'),
                ],
                'evidence_kind' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_evidence_kinds'),
                ],
                'usage_application' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_usage_applications'),
                ],
                'recommended_min_percent' => ['type' => ['string', 'null']],
                'recommended_max_percent' => ['type' => ['string', 'null']],
                'percentage_basis' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_percentage_bases'),
                ],
            ],
            'required' => [
                'field',
                'source_name',
                'source_url',
                'summary',
                'claim_type',
                'source_kind',
                'scope',
                'evidence_kind',
                'usage_application',
                'recommended_min_percent',
                'recommended_max_percent',
                'percentage_basis',
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'candidate_evidence' => ['type' => 'array', 'items' => $candidateEvidence],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['candidate_evidence', 'warnings', 'unresolved_questions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function providerException(int $status, ?array $payload, string $requestId): IngredientResearchProviderException
    {
        $providerCode = data_get($payload, 'error.code')
            ?? data_get($payload, 'error.type')
            ?? 'unknown_error';
        $providerCode = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $providerCode) ?: 'unknown_error', 0, 60);
        $safeRequestId = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $requestId) ?: 'unavailable', 0, 100);

        return new IngredientResearchProviderException(
            failureCode: "provider_http_{$status}_{$providerCode}",
            safeMessage: __('ingredient_enrichment_admin.validation.provider_failed_with_details', [
                'status' => $status,
                'code' => $providerCode,
                'request_id' => $safeRequestId,
            ]),
        );
    }
}
