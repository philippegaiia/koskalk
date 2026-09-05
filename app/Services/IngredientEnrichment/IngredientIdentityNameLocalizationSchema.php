<?php

namespace App\Services\IngredientEnrichment;

class IngredientIdentityNameLocalizationSchema
{
    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function build(array $context): array
    {
        $locales = collect($context['locales'] ?? [])
            ->filter(fn (mixed $locale): bool => is_string($locale) && filled(trim($locale)))
            ->map(fn (string $locale): string => trim($locale))
            ->values()
            ->all();

        return [
            'type' => 'object',
            'properties' => [
                'translations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'locale' => ['type' => 'string', 'enum' => $locales],
                            'display_name' => ['type' => 'string'],
                            'saponification_name' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['locale', 'display_name', 'saponification_name'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['translations'],
            'additionalProperties' => false,
        ];
    }
}
