<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientSourceTier;
use Illuminate\Validation\ValidationException;

class IngredientEnrichmentEvidenceVerifier
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{url: string, title: string}>  $consultedSources
     */
    public function verify(array $result, array $consultedSources): void
    {
        $evidence = collect($result['evidence'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row)
                && is_string($row['field'] ?? null)
                && is_string($row['source_url'] ?? null));

        foreach ($this->citations($result) as $path => $citation) {
            $url = $citation['source_url'];
            $tier = IngredientSourceTier::tryFrom($citation['source_tier']);

            if (! $tier instanceof IngredientSourceTier || ! $this->isAllowedUrl($url, $tier)) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.disallowed_source'),
                ]);
            }

            if ($this->isCosmileUrl($url) && $this->isLegalOrIdentityField($citation['field'])) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.cosmile_legal_field'),
                ]);
            }

            $correlated = $evidence->contains(fn (array $row): bool => $row['field'] === $citation['field']
                && $row['source_url'] === $url);
            if (! $correlated) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.evidence_not_correlated'),
                ]);
            }
        }

    }

    /** @return array<string, array{field: string, source_url: string, source_tier: string}> */
    private function citations(array $result): array
    {
        $citations = [];

        foreach (['evidence', 'proposal.aliases', 'proposal.identifiers', 'proposal.cosing_functions', 'proposal.market_labels'] as $collectionPath) {
            foreach (data_get($result, $collectionPath, []) as $index => $row) {
                if (is_array($row)
                    && is_string($row['source_url'] ?? null)
                    && is_string($row['source_tier'] ?? null)) {
                    $citations["{$collectionPath}.{$index}.source_url"] = [
                        'field' => is_string($row['field'] ?? null) ? $row['field'] : "{$collectionPath}.{$index}",
                        'source_url' => $row['source_url'],
                        'source_tier' => $row['source_tier'],
                    ];
                }
            }
        }

        return $citations;
    }

    private function isAllowedUrl(string $url, IngredientSourceTier $tier): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        if ($tier === IngredientSourceTier::ReviewerSupplied) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return collect(config("ingredient-enrichment.source_hosts_by_tier.{$tier->value}", []))
            ->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, ".{$domain}"));
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
}
