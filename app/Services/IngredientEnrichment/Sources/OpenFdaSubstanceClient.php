<?php

namespace App\Services\IngredientEnrichment\Sources;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Services\IngredientEnrichment\IngredientIdentitySearchTerms;
use App\Services\IngredientEnrichment\IngredientSourceException;
use Illuminate\Support\Str;

class OpenFdaSubstanceClient
{
    public function __construct(
        private readonly CachedIngredientSourceHttpClient $http,
        private readonly IngredientIdentitySearchTerms $searchTerms,
    ) {}

    /**
     * Queries each search variant in order and keeps traversing until a
     * candidate is named *exactly* by a queried term (earliest variant wins).
     * GSRS name searches match phrases inside synonyms, so an earlier
     * sibling-form hit (an acid, ester, or modified record that merely
     * contains the phrase) must not suppress the later variant that holds the
     * exact record — otherwise discovery never offers the matcher the right
     * candidate. Traversal stays bounded by the distinct term list.
     *
     * @param  array{
     *     display_name?: string|null,
     *     inci_name?: string|null,
     *     identifiers?: list<array{scheme?: string, value?: string, is_primary?: bool}>
     * }  $record
     */
    public function lookup(array $record): IngredientSourceStageResult
    {
        $source = config('ingredient-enrichment.sources.open_fda');
        $sourceCalls = 0;
        $candidates = [];

        foreach ($this->queryTerms($record) as $term) {
            try {
                $response = $this->http->json(
                    source: 'open_fda',
                    url: (string) $source['base_url'],
                    query: ['search' => 'names.name:"'.Str::upper(str_replace('"', '', $term)).'"', 'limit' => 20],
                    version: (string) ($source['source_version'] ?? 'openfda-gsrs-v1'),
                    ttl: now()->addDays((int) $source['ttl_days']),
                );
            } catch (IngredientSourceException $exception) {
                if ($exception->source !== 'open_fda' || $exception->status !== 404) {
                    throw $exception;
                }

                $sourceCalls++;

                continue;
            }

            $sourceCalls += $response->sourceCalls;

            $batch = [];
            foreach ($response->payload['results'] ?? [] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $candidate = $this->normalizeCandidate($result);
                $candidates[$candidate['unii'] ?: $candidate['common_name']] = $candidate;
                $batch[] = $candidate;
            }

            if ($this->batchNamesTermExactly($batch, $term)) {
                break;
            }
        }

        $normalizedCandidates = array_values($candidates);

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::UsIdentity,
            status: 'completed',
            data: ['candidates' => $normalizedCandidates],
            evidence: collect($normalizedCandidates)
                ->map(fn (array $candidate): array => [
                    'field' => 'source.us_identity.'.$candidate['unii'],
                    'source_name' => 'FDA Global Substance Registration System',
                    'source_url' => (string) $source['base_url'],
                    'source_tier' => 'official',
                    'confidence' => 'verified',
                    'source_version' => (string) ($source['source_version'] ?? 'openfda-gsrs-v1'),
                    'source_updated_at' => null,
                    'retrieved_at' => now()->toImmutable()->toIso8601String(),
                ])
                ->all(),
            sourceCalls: $sourceCalls,
        );
    }

    /**
     * @param  array{
     *     display_name?: string|null,
     *     inci_name?: string|null,
     *     identifiers?: list<array{scheme?: string, value?: string, is_primary?: bool}>
     * }  $record
     * @return list<string>
     */
    private function queryTerms(array $record): array
    {
        return collect([
            $record['inci_name'] ?? null,
            ...collect($record['identifiers'] ?? [])->pluck('value')->all(),
            $record['display_name'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $term): array => $this->searchTerms->variants($term))
            ->map(fn (string $value): string => trim($value))
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
    }

    /**
     * Whether any candidate in the batch carries a whole name that equals the
     * queried term. Sibling records that only contain the phrase in a longer
     * synonym do not count as a match, so traversal continues to the next
     * variant.
     *
     * @param  list<array{common_name: string, inci_names: list<string>, names: list<string>}>  $batch
     */
    private function batchNamesTermExactly(array $batch, string $term): bool
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return false;
        }

        foreach ($batch as $candidate) {
            $names = collect([
                $candidate['common_name'] ?? null,
                ...($candidate['inci_names'] ?? []),
                ...($candidate['names'] ?? []),
            ])
                ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                ->map(fn (string $name): string => mb_strtolower(trim($name)))
                ->unique();

            if ($names->contains($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{unii: string, common_name: string, inci_names: list<string>, names: list<string>, cas: list<string>}
     */
    private function normalizeCandidate(array $result): array
    {
        $names = collect($result['names'] ?? [])
            ->filter(fn (mixed $name): bool => is_array($name))
            ->filter(fn (array $name): bool => is_string($name['name'] ?? null) && trim($name['name']) !== '')
            ->values();
        $commonName = $names
            ->first(fn (array $name): bool => (bool) ($name['preferred'] ?? false) || (bool) ($name['display_name'] ?? false));
        $commonName = is_array($commonName) ? trim((string) $commonName['name']) : trim((string) data_get($names->first(), 'name'));

        $inciNames = $names
            ->filter(function (array $name): bool {
                return collect($name['name_orgs'] ?? [])
                    ->filter(fn (mixed $organization): bool => is_array($organization))
                    ->contains(fn (array $organization): bool => mb_strtoupper((string) ($organization['name_org'] ?? '')) === 'INCI');
            })
            ->pluck('name')
            ->map(fn (string $name): string => trim($name))
            ->values()
            ->all();
        $cas = collect($result['codes'] ?? [])
            ->filter(fn (mixed $code): bool => is_array($code))
            ->filter(fn (array $code): bool => mb_strtoupper((string) ($code['code_system'] ?? '')) === 'CAS')
            ->pluck('code')
            ->filter(fn (mixed $code): bool => is_string($code))
            ->map(fn (string $code): string => trim($code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'unii' => trim((string) ($result['unii'] ?? '')),
            'common_name' => $commonName,
            'inci_names' => $inciNames,
            'names' => $names
                ->pluck('name')
                ->map(fn (string $name): string => trim($name))
                ->unique(fn (string $name): string => Str::lower($name))
                ->values()
                ->all(),
            'cas' => $cas,
        ];
    }
}
