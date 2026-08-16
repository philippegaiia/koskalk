<?php

namespace App\Services\IngredientEnrichment;

class LocalizedGuidanceHeadings
{
    public function normalize(string $guidance, string $locale, bool $soapmakingRelevant): string
    {
        $headings = data_get(config('ingredient-enrichment.guidance'), "localized_headings.{$locale}");
        if (! is_array($headings)) {
            return $guidance;
        }

        $expected = collect([
            $headings['overview'] ?? null,
            $headings['formulation_use'] ?? null,
            $soapmakingRelevant ? ($headings['soapmaking'] ?? null) : null,
        ])->filter(fn (mixed $heading): bool => is_string($heading) && $heading !== '')->values();

        $index = 0;

        return preg_replace_callback('/^##\s+.+$/m', function (array $matches) use ($expected, &$index): string {
            $heading = $expected->get($index);
            $index++;

            return is_string($heading) ? "## {$heading}" : $matches[0];
        }, $guidance) ?? $guidance;
    }
}
