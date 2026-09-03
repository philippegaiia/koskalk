<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientGuidanceEvidenceValidationResult;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class IngredientGuidanceEvidencePolicy
{
    private const string DEFAULT_RETRIEVED_AT = '1970-01-01T00:00:00+00:00';

    public const array REJECTION_CODES = [
        'invalid_shape',
        'invalid_field',
        'invalid_url',
        'blocked_domain',
        'unconsulted_url',
        'invalid_classification',
        'invalid_usage_metadata',
    ];

    /**
     * Check a guidance citation against the pages consulted for the research request.
     *
     * Guidance research deliberately permits a broader set of non-blocked sources than
     * identity research, but it must still be limited to pages actually consulted.
     *
     * @param  list<array{url: string, title: string}>  $consultedSources
     */
    public function allowsCitationUrl(string $url, array $consultedSources): bool
    {
        $canonicalUrl = $this->canonicalUrl($url);
        if ($canonicalUrl === null) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($this->isBlockedHost($host)) {
            return false;
        }

        return isset($this->consultedUrlMap($consultedSources)[$canonicalUrl]);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array{url: string, title: string}>  $consultedSources
     * @return list<array<string, mixed>>
     */
    public function validateCandidates(array $candidates, array $consultedSources): array
    {
        $validation = $this->partitionCandidates($candidates, $consultedSources);

        if ($validation->rejected !== []) {
            $rejection = $validation->rejected[0];
            $this->policyViolation(
                $rejection['index'],
                $this->rejectionMessage($rejection['code']),
            );
        }

        return $validation->accepted;
    }

    /**
     * Validate each candidate independently, retaining only rows that satisfy the guidance evidence policy.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array{url: string, title: string}>  $consultedSources
     */
    public function partitionCandidates(array $candidates, array $consultedSources): IngredientGuidanceEvidenceValidationResult
    {
        if (! array_is_list($candidates)) {
            $this->invalidResponse();
        }

        $consultedUrls = $this->consultedUrlMap($consultedSources);
        $accepted = [];
        $rejected = [];

        foreach ($candidates as $index => $candidate) {
            $evaluation = $this->evaluateCandidate($candidate, $index, $consultedUrls);

            if ($evaluation['accepted'] !== null) {
                $accepted[] = $evaluation['accepted'];
            } else {
                $rejected[] = $evaluation['rejection'];
            }
        }

        return new IngredientGuidanceEvidenceValidationResult($accepted, $rejected);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    public function toPersisted(array $candidates, CarbonImmutable $retrievedAt): array
    {
        return collect($candidates)
            ->map(fn (array $candidate): array => [
                'source_name' => trim((string) $candidate['source_name']),
                'source_url' => trim((string) $candidate['source_url']),
                'summary' => trim((string) $candidate['summary']),
                'source_tier' => 'editorial',
                'retrieved_at' => $retrievedAt->toIso8601String(),
                'claim_type' => $candidate['claim_type'],
                'source_kind' => $candidate['source_kind'],
                'scope' => $candidate['scope'],
                'evidence_kind' => $candidate['evidence_kind'],
                'usage_application' => $candidate['usage_application'],
                'recommended_min_percent' => $candidate['recommended_min_percent'],
                'recommended_max_percent' => $candidate['recommended_max_percent'],
                'percentage_basis' => $candidate['percentage_basis'],
                'identifiers' => $candidate['identifiers'] ?? ['cas' => [], 'ec' => []],
            ])
            ->values()
            ->all();
    }

    /**
     * Normalize both the current classified rows and the legacy five-key rows.
     *
     * @return list<array<string, mixed>>
     */
    public function normalizePersisted(mixed $rows, ?CarbonImmutable $fallbackRetrievedAt = null): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $fallback = ($fallbackRetrievedAt ?? CarbonImmutable::parse(self::DEFAULT_RETRIEVED_AT))->toIso8601String();

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): ?array => $this->normalizePersistedRow($row, $fallback))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * Reconcile a prior persisted evidence bank with a fresh research bank.
     *
     * A logical row is identified by its canonical source URL, whitespace- and
     * case-normalized summary, claim classifications, usage application, normalized
     * decimal bounds, and percentage basis. Refresh metadata (source name, source
     * tier, and retrieval time) is intentionally excluded, so a fresh logical
     * duplicate replaces the prior row in its existing position. Prior rows retain
     * their order, while distinct fresh rows append in research order.
     *
     * @param  list<array<string, mixed>>  $prior
     * @param  list<array<string, mixed>>  $fresh
     * @return list<array<string, mixed>>
     */
    public function reconcilePersisted(array $prior, array $fresh): array
    {
        $merged = [];
        $indexes = [];

        foreach ($this->normalizePersisted($prior) as $row) {
            $key = $this->logicalEvidenceKey($row);
            if (isset($indexes[$key])) {
                continue;
            }

            $indexes[$key] = count($merged);
            $merged[] = $row;
        }

        foreach ($this->normalizePersisted($fresh) as $row) {
            $key = $this->logicalEvidenceKey($row);
            if (isset($indexes[$key])) {
                $merged[$indexes[$key]] = $row;

                continue;
            }

            $indexes[$key] = count($merged);
            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * @param  array<string, bool>  $consultedUrls
     * @return array{
     *     accepted:array<string,mixed>|null,
     *     rejection:array{index:int,code:string,host:?string}|null
     * }
     */
    private function evaluateCandidate(mixed $candidate, int $index, array $consultedUrls): array
    {
        $host = $this->candidateHost($candidate);
        $expectedKeys = [
            'field', 'source_name', 'source_url', 'summary', 'claim_type', 'source_kind',
            'scope', 'evidence_kind', 'usage_application', 'recommended_min_percent',
            'recommended_max_percent', 'percentage_basis', 'identifiers',
        ];

        if (! is_array($candidate)
            || array_diff($expectedKeys, array_keys($candidate)) !== []
            || array_diff(array_keys($candidate), $expectedKeys) !== []) {
            return $this->rejected($index, 'invalid_shape', $host);
        }

        $field = is_string($candidate['field'] ?? null) ? trim($candidate['field']) : '';
        $sourceName = is_string($candidate['source_name'] ?? null) ? trim($candidate['source_name']) : '';
        $sourceUrl = is_string($candidate['source_url'] ?? null) ? trim($candidate['source_url']) : '';
        $summary = is_string($candidate['summary'] ?? null) ? trim($candidate['summary']) : '';

        if ($field !== 'proposal.info_markdown') {
            return $this->rejected($index, 'invalid_field', $host);
        }

        if ($sourceName === '' || $summary === '') {
            return $this->rejected($index, 'invalid_shape', $host);
        }

        $canonicalUrl = $this->canonicalUrl($sourceUrl);
        if ($canonicalUrl === null) {
            return $this->rejected($index, 'invalid_url', $host);
        }

        if ($this->isBlockedHost($host ?? '')) {
            return $this->rejected($index, 'blocked_domain', $host);
        }

        if (! isset($consultedUrls[$canonicalUrl])) {
            return $this->rejected($index, 'unconsulted_url', $host);
        }

        foreach ([
            'claim_type' => 'allowed_claim_types',
            'source_kind' => 'allowed_source_kinds',
            'scope' => 'allowed_scopes',
            'evidence_kind' => 'allowed_evidence_kinds',
            'usage_application' => 'allowed_usage_applications',
            'percentage_basis' => 'allowed_percentage_bases',
        ] as $fieldName => $configKey) {
            if (! is_string($candidate[$fieldName] ?? null)
                || ! in_array($candidate[$fieldName], config("ingredient-enrichment.openai.guidance_research.{$configKey}", []), true)) {
                return $this->rejected($index, 'invalid_classification', $host);
            }
        }

        if (! $this->hasValidPercentageEvidence($candidate)) {
            return $this->rejected($index, 'invalid_usage_metadata', $host);
        }

        $identifiers = $this->normalizedIdentifiers($candidate['identifiers'] ?? null);
        if ($identifiers === null) {
            return $this->rejected($index, 'invalid_shape', $host);
        }

        return [
            'accepted' => [
                'field' => $field,
                'source_name' => $sourceName,
                'source_url' => $sourceUrl,
                'summary' => $summary,
                'claim_type' => $candidate['claim_type'],
                'source_kind' => $candidate['source_kind'],
                'scope' => $candidate['scope'],
                'evidence_kind' => $candidate['evidence_kind'],
                'usage_application' => $candidate['usage_application'],
                'recommended_min_percent' => $candidate['recommended_min_percent'],
                'recommended_max_percent' => $candidate['recommended_max_percent'],
                'percentage_basis' => $candidate['percentage_basis'],
                'identifiers' => $identifiers,
            ],
            'rejection' => null,
        ];
    }

    /**
     * Normalizes source-reported identifiers, accepting only well-formed
     * CAS and EC numbers. Returns null when the shape or a value is invalid.
     *
     * @return array{cas: list<string>, ec: list<string>}|null
     */
    private function normalizedIdentifiers(mixed $identifiers): ?array
    {
        if (! is_array($identifiers)
            || ! is_array($identifiers['cas'] ?? null) || ! array_is_list($identifiers['cas'])
            || ! is_array($identifiers['ec'] ?? null) || ! array_is_list($identifiers['ec'])) {
            return null;
        }

        $cas = $this->normalizeIdentifierList($identifiers['cas'], '/^\d{2,7}-\d{2}-\d$/u');
        $ec = $this->normalizeIdentifierList($identifiers['ec'], '/^\d{3}-\d{3}-\d$/u');

        return $cas === null || $ec === null ? null : ['cas' => $cas, 'ec' => $ec];
    }

    /** @param array<mixed> $values @return list<string>|null */
    private function normalizeIdentifierList(array $values, string $pattern): ?array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);
            if ($value === '' || preg_match($pattern, $value) !== 1) {
                return null;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $candidate */
    private function hasValidPercentageEvidence(array $candidate): bool
    {
        $claimType = $candidate['claim_type'];
        $isUsage = $claimType === 'usage';
        $min = $candidate['recommended_min_percent'];
        $max = $candidate['recommended_max_percent'];
        $application = $candidate['usage_application'];
        $basis = $candidate['percentage_basis'];

        if (! $isUsage) {
            return $application === 'not_applicable'
                && $min === null
                && $max === null
                && $basis === 'not_applicable';
        }

        $recommendationKinds = [
            'manufacturer_technical',
            'supplier_technical',
            'professional_reference',
            'specialist_reference',
        ];

        if ($candidate['evidence_kind'] !== 'formulation_recommendation'
            || ! in_array($candidate['source_kind'], $recommendationKinds, true)
            || ! in_array($application, ['cosmetics', 'soapmaking'], true)
            || ($application === 'cosmetics' && ! in_array($basis, ['total_formula', 'oil_phase'], true))
            || ($application === 'soapmaking' && $basis !== 'soap_oils')
            || ($min === null && $max === null)) {
            return false;
        }

        foreach ([$min, $max] as $value) {
            if ($value !== null && ! $this->isValidDecimalBound($value)) {
                return false;
            }
        }

        return $min === null || $max === null || $this->compareDecimals($min, $max) <= 0;
    }

    private function isValidDecimalBound(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            return false;
        }

        return $this->compareDecimals($value, '0') >= 0
            && $this->compareDecimals($value, '100') <= 0;
    }

    private function compareDecimals(string $left, string $right): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftInteger = ltrim($leftInteger, '0') ?: '0';
        $rightInteger = ltrim($rightInteger, '0') ?: '0';

        if (strlen($leftInteger) !== strlen($rightInteger)) {
            return strlen($leftInteger) <=> strlen($rightInteger);
        }

        if ($leftInteger !== $rightInteger) {
            return strcmp($leftInteger, $rightInteger) <=> 0;
        }

        $length = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $length, '0');
        $rightFraction = str_pad($rightFraction, $length, '0');

        return strcmp($leftFraction, $rightFraction) <=> 0;
    }

    /**
     * @param  list<array{url: string, title: string}>  $consultedSources
     * @return array<string, bool>
     */
    private function consultedUrlMap(array $consultedSources): array
    {
        return collect($consultedSources)
            ->map(fn (mixed $source): ?string => is_array($source)
                ? $this->canonicalUrl($source['url'] ?? null)
                : null)
            ->filter()
            ->mapWithKeys(fn (string $url): array => [$url => true])
            ->all();
    }

    private function candidateHost(mixed $candidate): ?string
    {
        if (! is_array($candidate) || ! is_string($candidate['source_url'] ?? null)) {
            return null;
        }

        $host = parse_url(trim($candidate['source_url']), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * @return array{
     *     accepted:null,
     *     rejection:array{index:int,code:string,host:?string}
     * }
     */
    private function rejected(int $index, string $code, ?string $host): array
    {
        return [
            'accepted' => null,
            'rejection' => [
                'index' => $index,
                'code' => $code,
                'host' => $host,
            ],
        ];
    }

    private function rejectionMessage(string $code): string
    {
        return match ($code) {
            'invalid_shape' => __('ingredient_enrichment_admin.validation.guidance_evidence_invalid_shape'),
            'invalid_field' => __('ingredient_enrichment_admin.validation.guidance_evidence_invalid_field'),
            'invalid_url' => __('ingredient_enrichment_admin.validation.guidance_evidence_invalid_url'),
            'blocked_domain' => __('ingredient_enrichment_admin.validation.guidance_evidence_blocked_domain'),
            'unconsulted_url' => __('ingredient_enrichment_admin.validation.guidance_evidence_unconsulted_url'),
            'invalid_classification' => __('ingredient_enrichment_admin.validation.guidance_evidence_invalid_classification'),
            'invalid_usage_metadata' => __('ingredient_enrichment_admin.validation.guidance_evidence_invalid_usage_metadata'),
            default => __('ingredient_enrichment_admin.validation.disallowed_source'),
        };
    }

    private function canonicalUrl(mixed $url): ?string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$port.$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function isBlockedHost(string $host): bool
    {
        foreach (config('ingredient-enrichment.openai.guidance_research.blocked_domains', []) as $domain) {
            $domain = strtolower((string) $domain);
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function isCosmileUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'cosmileeurope.eu' || str_ends_with($host, '.cosmileeurope.eu');
    }

    private function isLegalOrIdentityField(string $field): bool
    {
        return $field === 'proposal.inci_name'
            || str_starts_with($field, 'proposal.aliases.')
            || str_starts_with($field, 'proposal.identifiers.')
            || str_starts_with($field, 'proposal.cosing_functions.')
            || str_starts_with($field, 'proposal.market_labels.')
            || str_starts_with($field, 'regulatory_findings.');
    }

    private function hasAllClassifications(array $row): bool
    {
        return array_key_exists('claim_type', $row)
            && array_key_exists('source_kind', $row)
            && array_key_exists('scope', $row)
            && array_key_exists('evidence_kind', $row)
            && array_key_exists('usage_application', $row)
            && array_key_exists('recommended_min_percent', $row)
            && array_key_exists('recommended_max_percent', $row)
            && array_key_exists('percentage_basis', $row);
    }

    private function hasSomeClassifications(array $row): bool
    {
        return collect([
            'claim_type',
            'source_kind',
            'scope',
            'evidence_kind',
            'usage_application',
            'recommended_min_percent',
            'recommended_max_percent',
            'percentage_basis',
        ])->contains(fn (string $key): bool => array_key_exists($key, $row));
    }

    /**
     * Normalize one persisted row, quarantining rows that cannot be represented
     * as either a complete classified row or a complete legacy editorial row.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizePersistedRow(array $row, string $fallbackRetrievedAt): ?array
    {
        foreach (['source_name', 'source_url', 'summary'] as $field) {
            if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                return null;
            }
        }

        $sourceName = trim($row['source_name']);
        $sourceUrl = trim($row['source_url']);
        $summary = trim($row['summary']);
        if ($this->canonicalUrl($sourceUrl) === null) {
            return null;
        }

        if (array_key_exists('source_tier', $row)
            && (! is_string($row['source_tier']) || trim($row['source_tier']) !== 'editorial')) {
            return null;
        }

        if (array_key_exists('retrieved_at', $row)
            && (! is_string($row['retrieved_at'])
                || trim($row['retrieved_at']) === ''
                || ! $this->isParsableDateTime($row['retrieved_at']))) {
            return null;
        }

        if ($this->hasSomeClassifications($row) && ! $this->hasAllClassifications($row)) {
            return null;
        }

        $hasClassifications = $this->hasAllClassifications($row);
        if ($hasClassifications && ! $this->hasValidPersistedClassifications($row)) {
            return null;
        }

        return [
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
            'summary' => $summary,
            'source_tier' => 'editorial',
            'retrieved_at' => array_key_exists('retrieved_at', $row)
                ? trim($row['retrieved_at'])
                : $fallbackRetrievedAt,
            'claim_type' => $hasClassifications ? $row['claim_type'] : 'origin',
            'source_kind' => $hasClassifications ? $row['source_kind'] : 'legacy_editorial',
            'scope' => $hasClassifications ? $row['scope'] : 'material',
            'evidence_kind' => $hasClassifications ? $row['evidence_kind'] : 'fact',
            'usage_application' => $hasClassifications ? $row['usage_application'] : 'not_applicable',
            'recommended_min_percent' => $hasClassifications ? $row['recommended_min_percent'] : null,
            'recommended_max_percent' => $hasClassifications ? $row['recommended_max_percent'] : null,
            'percentage_basis' => $hasClassifications ? $row['percentage_basis'] : 'not_applicable',
        ];
    }

    /** @param array<string, mixed> $row */
    private function hasValidPersistedClassifications(array $row): bool
    {
        foreach ([
            'claim_type' => 'allowed_claim_types',
            'source_kind' => 'allowed_source_kinds',
            'scope' => 'allowed_scopes',
            'evidence_kind' => 'allowed_evidence_kinds',
            'usage_application' => 'allowed_usage_applications',
            'percentage_basis' => 'allowed_percentage_bases',
        ] as $field => $configKey) {
            $allowedValues = config("ingredient-enrichment.openai.guidance_research.{$configKey}", []);
            if ($field === 'source_kind') {
                $allowedValues[] = 'legacy_editorial';
            }
            if (! is_string($row[$field] ?? null)
                || ! in_array($row[$field], $allowedValues, true)) {
                return false;
            }
        }

        return collect(['recommended_min_percent', 'recommended_max_percent'])
            ->every(fn (string $field): bool => $row[$field] === null || is_string($row[$field]))
            && $this->hasValidPercentageEvidence($row);
    }

    private function isParsableDateTime(string $value): bool
    {
        try {
            CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function logicalEvidenceKey(array $row): string
    {
        return json_encode([
            'source_url' => $this->canonicalUrl($row['source_url'] ?? null)
                ?? mb_strtolower(trim((string) ($row['source_url'] ?? ''))),
            'summary' => $this->normalizedSummary($row['summary'] ?? null),
            'claim_type' => $this->normalizedKeyValue($row['claim_type'] ?? null),
            'source_kind' => $this->normalizedKeyValue($row['source_kind'] ?? null),
            'scope' => $this->normalizedKeyValue($row['scope'] ?? null),
            'evidence_kind' => $this->normalizedKeyValue($row['evidence_kind'] ?? null),
            'usage_application' => $this->normalizedKeyValue($row['usage_application'] ?? null),
            'recommended_min_percent' => $this->normalizedDecimalBound($row['recommended_min_percent'] ?? null),
            'recommended_max_percent' => $this->normalizedDecimalBound($row['recommended_max_percent'] ?? null),
            'percentage_basis' => $this->normalizedKeyValue($row['percentage_basis'] ?? null),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizedSummary(mixed $summary): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $summary));

        return mb_strtolower($normalized ?? '');
    }

    private function normalizedKeyValue(mixed $value): ?string
    {
        return is_string($value) ? mb_strtolower(trim($value)) : null;
    }

    private function normalizedDecimalBound(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private function invalidResponse(): never
    {
        throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
    }

    private function policyViolation(int $index, string $message): never
    {
        throw ValidationException::withMessages([
            "candidate_evidence.{$index}" => $message,
        ]);
    }
}
