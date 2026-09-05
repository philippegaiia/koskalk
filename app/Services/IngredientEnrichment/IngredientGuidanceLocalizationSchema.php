<?php

namespace App\Services\IngredientEnrichment;

class IngredientGuidanceLocalizationSchema
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(array $context): array
    {
        $locales = collect($context['locales'] ?? config('interface-translations.catalogue_locales', []))
            ->filter(fn (mixed $locale): bool => is_string($locale) && trim($locale) !== '')
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
                            'locale' => [
                                'type' => 'string',
                                'enum' => $locales,
                            ],
                            'info_markdown' => ['type' => 'string'],
                        ],
                        'required' => ['locale', 'info_markdown'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['translations'],
            'additionalProperties' => false,
        ];
    }
}
