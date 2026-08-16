<?php

namespace App\Services\IngredientEnrichment\Sources;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientFunction;
use Illuminate\Support\Collection;

class CosingCheckerClient
{
    public function __construct(private readonly CachedIngredientSourceHttpClient $http) {}

    /**
     * @param  array{
     *     display_name?: string|null,
     *     inci_name?: string|null,
     *     identifiers?: list<array{scheme?: string, value?: string, is_primary?: bool}>
     * }  $record
     */
    public function lookup(array $record): IngredientSourceStageResult
    {
        $functionKeysByName = IngredientFunction::query()
            ->where('is_active', true)
            ->get(['key', 'name'])
            ->mapWithKeys(fn (IngredientFunction $function): array => [
                $this->normalize((string) $function->name) => $function->key,
            ]);

        $sourceCalls = 0;
        $warnings = [];
        $candidates = [];

        foreach ($this->queryTerms($record) as $term) {
            $response = $this->http->json(
                source: 'cosing_checker',
                url: rtrim((string) config('ingredient-enrichment.sources.cosing_checker.base_url'), '/').'/ingredients/',
                query: ['q' => $term, 'per_page' => 100],
                version: (string) config('ingredient-enrichment.sources.cosing_checker.inventory_version'),
                ttl: now()->addDays((int) config('ingredient-enrichment.sources.cosing_checker.ttl_days')),
            );
            $sourceCalls += $response->sourceCalls;

            foreach ($response->payload['results'] ?? [] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                [$normalizedCandidate, $candidateWarnings] = $this->normalizeCandidate($candidate, $functionKeysByName);
                $candidates[$normalizedCandidate['slug']] = $normalizedCandidate;
                $warnings = [...$warnings, ...$candidateWarnings];
            }

            if ($this->hasExactMatch(array_values($candidates), $term, $record)) {
                break;
            }
        }

        $normalizedCandidates = array_values($candidates);
        $soapSalts = [];
        if (($record['category'] ?? null) === 'lipids' || ($record['research_family'] ?? null) === 'lipids') {
            [$soapSalts, $soapSourceCalls, $soapWarnings] = $this->lookupSoapSalts(
                $record,
                $normalizedCandidates,
                $functionKeysByName,
            );
            $sourceCalls += $soapSourceCalls;
            $warnings = [...$warnings, ...$soapWarnings];
        }

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::EuStructured,
            status: 'completed',
            data: ['candidates' => $normalizedCandidates, 'soap_salts' => $soapSalts],
            evidence: collect($normalizedCandidates)
                ->map(fn (array $candidate): array => $this->evidence($candidate))
                ->merge(collect($soapSalts)->filter()->map(fn (array $candidate): array => $this->evidence($candidate)))
                ->all(),
            warnings: array_values(array_unique($warnings)),
            sourceCalls: $sourceCalls,
        );
    }

    /**
     * Search terms help discovery only. A salt is returned solely when the source record itself
     * explicitly relates the sodium or potassium ingredient to the base material.
     *
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $baseCandidates
     * @param  Collection<string, string>  $functionKeysByName
     * @return array{0: array<string, array<string, mixed>>, 1: int, 2: list<string>}
     */
    private function lookupSoapSalts(
        array $record,
        array $baseCandidates,
        Collection $functionKeysByName,
    ): array {
        $identityTerms = $this->soapIdentityTerms($record, $baseCandidates);
        $discoveryTerm = $identityTerms->first();
        if (! is_string($discoveryTerm)) {
            return [[], 0, []];
        }

        $matches = [];
        $sourceCalls = 0;
        $warnings = [];
        foreach (['naoh' => 'SODIUM', 'koh' => 'POTASSIUM'] as $kind => $prefix) {
            $candidates = collect();

            foreach (["{$prefix} {$discoveryTerm}", ...$identityTerms] as $queryTerm) {
                $response = $this->http->json(
                    source: 'cosing_checker',
                    url: rtrim((string) config('ingredient-enrichment.sources.cosing_checker.base_url'), '/').'/ingredients/',
                    query: ['q' => $queryTerm, 'per_page' => 100],
                    version: (string) config('ingredient-enrichment.sources.cosing_checker.inventory_version'),
                    ttl: now()->addDays((int) config('ingredient-enrichment.sources.cosing_checker.ttl_days')),
                );
                $sourceCalls += $response->sourceCalls;

                $candidates = $candidates->merge(collect($response->payload['results'] ?? [])
                    ->filter(fn (mixed $candidate): bool => is_array($candidate))
                    ->map(function (array $candidate) use ($functionKeysByName, &$warnings): array {
                        [$normalized, $candidateWarnings] = $this->normalizeCandidate($candidate, $functionKeysByName);
                        $warnings = [...$warnings, ...$candidateWarnings];

                        return $normalized;
                    }));
            }

            $candidates = $candidates
                ->unique('slug')
                ->filter(fn (array $candidate): bool => str_starts_with(strtoupper($candidate['inci_name']), "{$prefix} ")
                    && $this->isExplicitSoapSaltRelationship($candidate['description'], $identityTerms))
                ->values();

            if ($candidates->count() === 1) {
                $matches[$kind] = $candidates->first();
            }
        }

        return [$matches, $sourceCalls, array_values(array_unique($warnings))];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $baseCandidates
     * @return Collection<int, string>
     */
    private function soapIdentityTerms(array $record, array $baseCandidates): Collection
    {
        return collect([
            $record['display_name'] ?? null,
            $record['inci_name'] ?? null,
            ...collect($baseCandidates)->pluck('inci_name')->all(),
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
            ->map(function (string $name): string {
                $withoutParentheses = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $name) ?? $name;

                return trim(preg_replace('/\s+(?:seed\s+|kernel\s+|fruit\s+)?(?:oil|butter|fat|wax)$/iu', '', $withoutParentheses) ?? $withoutParentheses);
            })
            ->filter()
            ->unique(fn (string $term): string => $this->normalize($term))
            ->values();
    }

    /** @param Collection<int, string> $identityTerms */
    private function isExplicitSoapSaltRelationship(string $description, Collection $identityTerms): bool
    {
        $normalizedDescription = $this->normalize($description);
        $describesFattyAcidSalt = str_contains($normalizedDescription, 'salt')
            && str_contains($normalizedDescription, 'fatty acid');
        $namesBaseMaterial = $identityTerms->contains(
            fn (string $term): bool => str_contains($normalizedDescription, $this->normalize($term)),
        );

        return $describesFattyAcidSalt && $namesBaseMaterial;
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
        $identifiers = collect($record['identifiers'] ?? [])
            ->filter(fn (mixed $identifier): bool => is_array($identifier) && is_string($identifier['value'] ?? null))
            ->sortByDesc(fn (array $identifier): bool => (bool) ($identifier['is_primary'] ?? false))
            ->pluck('value');

        return collect([
            $record['inci_name'] ?? null,
            ...$identifiers,
            $record['display_name'] ?? null,
        ])
            ->filter(fn (mixed $term): bool => is_string($term) && trim($term) !== '')
            ->flatMap(fn (string $term): array => $this->queryVariants($term))
            ->unique(fn (string $term): string => $this->normalize($term))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function queryVariants(string $term): array
    {
        $term = trim($term);
        $withoutParentheticalName = trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $term) ?? $term);
        $withoutParentheticalName = preg_replace('/\s+/u', ' ', $withoutParentheticalName) ?? $withoutParentheticalName;

        return array_values(array_unique([$withoutParentheticalName, $term]));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  Collection<string, string>  $functionKeysByName
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function normalizeCandidate(array $candidate, Collection $functionKeysByName): array
    {
        $warnings = [];
        $functionKeys = collect($this->splitFunctions($candidate['function'] ?? null))
            ->map(function (string $function) use ($functionKeysByName, &$warnings): ?string {
                $key = $this->functionKey($function, $functionKeysByName);

                if (! is_string($key)) {
                    $warnings[] = __('ingredient_enrichment.warnings.unknown_cosing_function', ['function' => $function]);
                }

                return $key;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $slug = trim((string) ($candidate['slug'] ?? ''));

        return [[
            'slug' => $slug,
            'cosing_ref' => trim((string) ($candidate['ref_number'] ?? '')),
            'inci_name' => trim((string) ($candidate['inci_name'] ?? '')),
            'cas' => $this->splitValues($candidate['cas_number'] ?? null),
            'ec' => $this->splitValues($candidate['ec_number'] ?? null),
            'functions' => $functionKeys,
            'description' => trim((string) ($candidate['description'] ?? '')),
            'restriction' => trim((string) ($candidate['restriction'] ?? '')),
            'source_updated_at' => $this->normalizeSourceDate($candidate['update_date'] ?? null),
            'confidence' => 'supported',
        ], $warnings];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array{
     *     display_name?: string|null,
     *     inci_name?: string|null,
     *     identifiers?: list<array{scheme?: string, value?: string, is_primary?: bool}>
     * }  $record
     */
    private function hasExactMatch(array $candidates, string $term, array $record): bool
    {
        $normalizedTerm = $this->normalize($term);
        $identifiers = collect($record['identifiers'] ?? [])
            ->filter(fn (mixed $identifier): bool => is_array($identifier) && is_string($identifier['value'] ?? null))
            ->pluck('value')
            ->map(fn (string $value): string => $this->normalize($value))
            ->all();

        return collect($candidates)->contains(function (array $candidate) use ($normalizedTerm, $identifiers): bool {
            return $this->normalize((string) $candidate['inci_name']) === $normalizedTerm
                || in_array($this->normalize((string) $candidate['cosing_ref']), $identifiers, true)
                || collect([...$candidate['cas'], ...$candidate['ec']])
                    ->map(fn (string $value): string => $this->normalize($value))
                    ->contains(fn (string $value): bool => in_array($value, $identifiers, true));
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function evidence(array $candidate): array
    {
        return [
            'field' => 'source.eu_structured.'.$candidate['cosing_ref'],
            'source_name' => 'CosIng Checker',
            'source_url' => 'https://cosingchecker.com/ingredients/'.$candidate['slug'].'/',
            'source_tier' => 'structured_mirror',
            'confidence' => 'supported',
            'source_version' => (string) config('ingredient-enrichment.sources.cosing_checker.inventory_version'),
            'source_updated_at' => $candidate['source_updated_at'],
            'retrieved_at' => now()->toImmutable()->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitValues(mixed $values): array
    {
        if (! is_string($values)) {
            return [];
        }

        return collect(preg_split('/\s*[;\/]\s*/u', $values) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '' && $value !== '-')
            ->unique(fn (string $value): string => $this->normalize($value))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function splitFunctions(mixed $functions): array
    {
        if (! is_string($functions)) {
            return [];
        }

        return collect(preg_split('/\s*,\s*/u', $functions) ?: [])
            ->map(fn (string $function): string => trim($function))
            ->filter()
            ->unique(fn (string $function): string => $this->normalize($function))
            ->values()
            ->all();
    }

    /** @param Collection<string, string> $functionKeysByName */
    private function functionKey(string $function, Collection $functionKeysByName): ?string
    {
        $normalized = $this->normalize($function);
        $canonicalName = match ($normalized) {
            'fragrance' => 'perfuming',
            default => $normalized,
        };
        $suffix = str($normalized)->afterLast(' - ')->value();

        return collect([$canonicalName, $normalized, $suffix])
            ->unique()
            ->map(fn (string $name): ?string => $functionKeysByName->get($name))
            ->first(fn (?string $key): bool => is_string($key));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }

    private function normalizeSourceDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = trim($value);
        if (preg_match('/^(?<day>\d{2})\/(?<month>\d{2})\/(?<year>\d{4})$/', $date, $parts) === 1
            && checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            return $parts['year'].'-'.$parts['month'].'-'.$parts['day'];
        }

        return $date;
    }
}
