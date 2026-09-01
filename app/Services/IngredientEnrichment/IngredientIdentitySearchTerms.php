<?php

namespace App\Services\IngredientEnrichment;

/**
 * Expands an identity search term into registry-friendly variants.
 *
 * Registries (CosIng, FDA GSRS, EUR-Lex) index canonical names, so
 * catalogue names that use a different product form must be converted at
 * search time only; the matched registry record remains authoritative.
 */
final class IngredientIdentitySearchTerms
{
    /**
     * @return list<string>
     */
    public function variants(string $term): array
    {
        $term = trim($term);
        $withoutParentheticals = trim(preg_replace('/\s*\([^)]*\)\s*/u', ' ', $term) ?? $term);
        $withoutParentheticals = preg_replace('/\s+/u', ' ', $withoutParentheticals) ?? $withoutParentheticals;

        return array_values(array_unique(array_filter([
            $term,
            $withoutParentheticals,
            preg_replace('/\bKernels?\b/i', 'Seed', $withoutParentheticals),
        ], fn (string $variant): bool => $variant !== '')));
    }
}
