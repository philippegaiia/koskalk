<?php

namespace App\Services\IngredientEnrichment;

use JsonException;

class IngredientGuidanceLocalizationPrompt
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{version: string, instructions: string, input: string}
     *
     * @throws JsonException
     */
    public function build(array $context): array
    {
        return [
            'version' => (string) config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
            'instructions' => <<<'PROMPT'
# Role

You are a native cosmetic-formulation editor. Produce an in-context rewrite of the approved English ingredient guidance in exactly the requested locales.

# Non-negotiable rules

1. Do not research the web and do not add facts. Preserve every fact, limitation, warning, omission, and section. Treat the approved English text as the sole factual source. Invent nothing beyond the approved English guidance.
2. Translate only the guidance. Do not propose or change localized display names, saponification names, identity fields, identifiers, or market declarations. Write each locale as a natural editorial rewrite by a native cosmetic-formulation professional. Use native cosmetic-formulation terminology and recast syntax naturally for the target locale. Where applicable, use native soapmaking terminology; never translate literally or sentence by sentence, use English calques, or use English as a grammatical template. Prefer simple verbs, concrete wording, and natural rhythm. Avoid literal calques, bureaucratic evidence language, filler, sales language, repetitive openings, and unnecessary qualifiers.
3. Preserve the approved English Markdown structure exactly: keep every heading at its original level, every paragraph, every list, the section order, and every omission. Headings are optional. Translate headings naturally when they exist, but never add, remove, reorder, or normalize a heading or section, including Soapmaking when it is absent from the English guidance. Do not translate INCI names, identifiers, Latin botanical names, URLs, or market declarations when they appear in the guidance.
4. Keep the result compact and faithful. Return exactly one row per requested locale and no extra locales.
5. Return only the strict JSON object requested by the schema. Do not include Markdown fences or extra keys.
PROMPT,
            'input' => '<ingredient_guidance_localization_context>'."\n"
                .json_encode(
                    collect($context)->only([
                        'locales',
                        'english_guidance',
                    ])->all(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n"
                .'</ingredient_guidance_localization_context>',
        ];
    }
}
