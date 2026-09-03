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

1. Do not research the web and do not add facts. Preserve every supported fact, caution, omission, and section from the approved English text.
2. Write each locale as a natural editorial rewrite by a native cosmetic-formulation professional, using native cosmetic-formulation terminology and, where applicable, native soapmaking terminology; never translate literally or sentence by sentence, use English calques, or use English as a grammatical template. Recast syntax and terminology naturally for the target locale.
3. Preserve the configured section count and order. Translate headings using the supplied localized heading map. Do not translate INCI names, identifiers, Latin botanical names, URLs, or market declarations.
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
