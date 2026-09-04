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
2. Write each locale as a natural editorial rewrite by a native cosmetic-formulation professional. Translate the display name, translate the saponification name only when the canonical saponification name is present, and translate the guidance. Use native cosmetic-formulation terminology and recast syntax naturally for the target locale. Where applicable, use native soapmaking terminology; never translate literally or sentence by sentence, use English calques, or use English as a grammatical template. Prefer simple verbs, concrete wording, and natural rhythm. Avoid literal calques, bureaucratic evidence language, filler, sales language, repetitive openings, and unnecessary qualifiers.
3. Preserve the configured section count and order. Translate headings using the supplied localized heading map. Do not translate INCI names, identifiers, Latin botanical names, URLs, or market declarations when they appear in names or guidance. If the canonical saponification name is null, return null for every localized saponification name; otherwise return a nonblank localized saponification name.
4. Keep the result compact and faithful. Return exactly one row per requested locale and no extra locales.
5. Return only the strict JSON object requested by the schema. Do not include Markdown fences or extra keys.
PROMPT,
            'input' => '<ingredient_guidance_localization_context>'."\n"
                .json_encode(
                    $context,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n"
                .'</ingredient_guidance_localization_context>',
        ];
    }
}
