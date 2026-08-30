<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceResearchClient;
use App\Data\IngredientGapResearchResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class OpenAiIngredientGapResearchClient implements IngredientGuidanceResearchClient
{
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
                        'filters' => [
                            'allowed_domains' => config('ingredient-enrichment.openai.gap_research.allowed_domains'),
                        ],
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

        $this->assertCandidateEvidenceIsAllowed($result['candidate_evidence']);

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

        return new IngredientGapResearchResponse(
            candidateEvidence: $result['candidate_evidence'],
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
You perform an exceptional, source-restricted gap-research pass for a cosmetic-ingredient catalogue. Return candidate evidence only. The supplied deterministic facts remain authoritative and must not be changed.

Use web search only to collect concise, practical formulation and soapmaking facts that are missing from the deterministic editorial context. Prioritize the exact material identity and look for material type or origin, physical form, formulation role, phase or dispersion, solubility, handling, stability, compatibility, and qualitative soapmaking behavior. Omit unsupported topics and therapeutic claims. You must not establish legal declarations, authorization, identifiers, INCI names, COSING functions, or regulatory conclusions. COSMILE Europe may be cited only for individually paraphrased introductory guidance; it cannot support any legal or identity field.

Every candidate evidence row must contain `field` set to `proposal.info_markdown`, a source name, the exact consulted source URL, and a concise paraphrased summary of the useful fact. Never copy long passages. If the gap remains unresolved, return an empty candidate_evidence array and state the specific question. Return only the strict JSON object.
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
            ],
            'required' => ['field', 'source_name', 'source_url', 'summary'],
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

    /** @param list<mixed> $candidateEvidence */
    private function assertCandidateEvidenceIsAllowed(array $candidateEvidence): void
    {
        foreach ($candidateEvidence as $candidate) {
            if (! is_array($candidate)
                || ! is_string($candidate['field'] ?? null)
                || ! is_string($candidate['source_url'] ?? null)
                || ! is_string($candidate['summary'] ?? null)
                || trim($candidate['summary']) === '') {
                throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
            }

            $host = Str::lower((string) parse_url($candidate['source_url'], PHP_URL_HOST));
            if (Str::endsWith($host, 'cosmileeurope.eu')
                && Str::startsWith($candidate['field'], [
                    'proposal.inci_name',
                    'proposal.aliases',
                    'proposal.identifiers',
                    'proposal.cosing_functions',
                    'proposal.market_labels',
                    'regulatory_findings',
                ])) {
                throw new RuntimeException(__('ingredient_enrichment_admin.validation.cosmile_legal_field'));
            }
        }
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
