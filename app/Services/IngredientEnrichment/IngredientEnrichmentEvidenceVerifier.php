<?php

namespace App\Services\IngredientEnrichment;

use Illuminate\Validation\ValidationException;

class IngredientEnrichmentEvidenceVerifier
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{url: string, title: string}>  $consultedSources
     */
    public function verify(array $result, array $consultedSources): void
    {
        $consulted = collect($consultedSources)
            ->pluck('url')
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): string => $this->normalizeUrl($url))
            ->flip();

        foreach ($this->citations($result) as $path => $url) {
            if (! $this->isAllowedUrl($url)) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.disallowed_source'),
                ]);
            }

            if (! $consulted->has($this->normalizeUrl($url))) {
                throw ValidationException::withMessages([
                    $path => __('ingredient_enrichment_admin.validation.unconsulted_source'),
                ]);
            }
        }
    }

    /** @return array<string, string> */
    private function citations(array $result): array
    {
        $citations = [];

        foreach (['evidence', 'proposal.identifiers', 'proposal.cosing_functions', 'proposal.market_labels'] as $collectionPath) {
            foreach (data_get($result, $collectionPath, []) as $index => $row) {
                if (is_array($row) && is_string($row['source_url'] ?? null)) {
                    $citations["{$collectionPath}.{$index}.source_url"] = $row['source_url'];
                }
            }
        }

        return $citations;
    }

    private function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return collect(config('ingredient-enrichment.openai.allowed_domains', []))
            ->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, ".{$domain}"));
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return trim($url);
        }

        $normalized = strtolower((string) ($parts['scheme'] ?? '')).'://'.strtolower((string) ($parts['host'] ?? ''));
        $normalized .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $normalized .= rtrim((string) ($parts['path'] ?? ''), '/');
        $normalized .= isset($parts['query']) ? '?'.$parts['query'] : '';

        return $normalized;
    }
}
