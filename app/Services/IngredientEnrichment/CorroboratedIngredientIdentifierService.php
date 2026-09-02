<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientValueProvenance;
use Carbon\CarbonImmutable;

/**
 * Adds CAS/EC identifiers reported by at least two independent consulted
 * research sources to the proposal when the official records lack that exact
 * value. A substance may legitimately carry several accepted CAS or EC
 * numbers, so corroborated values coexist with official ones and the first
 * official value remains `is_primary`; corroborated entries are written
 * `approved_secondary` with `source_confirmed` provenance listing every
 * corroborating URL.
 *
 * Independence is measured by registrable publisher domain, not URL: two
 * pages on one publisher's domain are a single authority, so they never
 * corroborate.
 * Official-precedence is value-level (owner decision): only an identifier the
 * official record already carries is skipped; a different value of the same
 * scheme may still enter when independent sources print it.
 */
class CorroboratedIngredientIdentifierService
{
    /** @var list<string> */
    private const CORROBORATED_SCHEMES = ['cas', 'ec'];

    public function __construct(private readonly SourcePublisherDomainResolver $publisherDomainResolver) {}

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<mixed>  $evidence
     * @return array<string, mixed>
     */
    public function merge(array $facts, array $evidence): array
    {
        $entries = collect($evidence)
            ->filter(fn (mixed $row): bool => is_array($row) && is_array($row['identifiers'] ?? null))
            ->flatMap(function (array $row): array {
                $rowEntries = [];
                foreach (self::CORROBORATED_SCHEMES as $scheme) {
                    foreach (($row['identifiers'][$scheme] ?? []) as $value) {
                        if (is_string($value) && $value !== '') {
                            $rowEntries[] = [
                                'scheme' => $scheme,
                                'value' => $value,
                                'source_url' => (string) ($row['source_url'] ?? ''),
                                'source_name' => (string) ($row['source_name'] ?? ''),
                            ];
                        }
                    }
                }

                return $rowEntries;
            });

        $accepted = $entries
            ->groupBy(fn (array $entry): string => $entry['scheme'].':'.mb_strtolower($entry['value']))
            ->filter(fn ($group): bool => count($this->publisherDomains($group->pluck('source_url')->all())) >= 2)
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'scheme' => $first['scheme'],
                    'value' => $first['value'],
                    'source_name' => $first['source_name'],
                    'source_url' => $first['source_url'],
                    'source_rows' => $group->values()->all(),
                    'source_urls' => $group->pluck('source_url')->filter()->unique()->values()->all(),
                ];
            })
            ->values();

        if ($accepted->isEmpty()) {
            return $facts;
        }

        $proposal = is_array($facts['proposal'] ?? null) ? $facts['proposal'] : [];
        $existingKeys = collect($proposal['identifiers'] ?? [])
            ->map(fn (array $identifier): string => ($identifier['scheme'] ?? '').':'.mb_strtolower((string) ($identifier['value'] ?? '')))
            ->flip();
        $identifiers = is_array($proposal['identifiers'] ?? null) ? $proposal['identifiers'] : [];
        $evidenceRows = is_array($facts['evidence'] ?? null) ? $facts['evidence'] : [];
        $provenance = is_array($facts['value_provenance'] ?? null) ? $facts['value_provenance'] : [];
        $fieldConfidence = is_array($facts['field_confidence'] ?? null) ? $facts['field_confidence'] : [];
        $retrievedAt = CarbonImmutable::now()->toIso8601String();

        foreach ($accepted as $entry) {
            // Value-level official precedence: at merge time the proposal
            // identifiers are the official records' values (official /
            // structured_mirror tiers), so skipping an exact scheme:value
            // duplicate is the "official record already carries it" check.
            // A *different* value of the same scheme is a second accepted
            // registry number and may coexist as is_primary => false.
            $key = $entry['scheme'].':'.mb_strtolower($entry['value']);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $index = count($identifiers);
            $identifiers[] = [
                'scheme' => $entry['scheme'],
                'value' => $entry['value'],
                'is_primary' => false,
                'source_name' => $entry['source_name'],
                'source_url' => $entry['source_url'],
                'source_tier' => IngredientSourceTier::ApprovedSecondary->value,
                'confidence' => IngredientEvidenceConfidence::Supported->value,
                'source_version' => null,
                'source_updated_at' => null,
                'retrieved_at' => $retrievedAt,
            ];
            foreach ($entry['source_urls'] as $sourceUrl) {
                $evidenceRows[] = [
                    'field' => "proposal.identifiers.{$index}",
                    'source_name' => (string) (collect($entry['source_rows'])
                        ->firstWhere('source_url', $sourceUrl)['source_name'] ?? $entry['source_name']),
                    'source_url' => $sourceUrl,
                    'source_tier' => IngredientSourceTier::ApprovedSecondary->value,
                    'confidence' => IngredientEvidenceConfidence::Supported->value,
                    'source_version' => null,
                    'source_updated_at' => null,
                    'retrieved_at' => $retrievedAt,
                ];
            }
            $provenance[] = [
                'field' => "proposal.identifiers.{$index}",
                'kind' => IngredientValueProvenance::SourceConfirmed->value,
                'reasoning' => (string) __('ingredient_enrichment.warnings.technical_identifier_corroborated'),
                'source_urls' => $entry['source_urls'],
            ];
            $fieldConfidence[] = [
                'field' => "proposal.identifiers.{$index}",
                'confidence' => IngredientEvidenceConfidence::Supported->value,
            ];
        }

        $facts['proposal'] = [...$proposal, 'identifiers' => $identifiers];
        $facts['evidence'] = $evidenceRows;
        $facts['value_provenance'] = $provenance;
        $facts['field_confidence'] = $fieldConfidence;

        return $facts;
    }

    /**
     * Distinct registrable publisher domains for a list of source URLs. URLs
     * without a resolvable public suffix never count as an authority.
     *
     * @param  array<mixed>  $urls
     * @return list<string>
     */
    private function publisherDomains(array $urls): array
    {
        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): ?string => $this->publisherDomainResolver->resolve($url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
