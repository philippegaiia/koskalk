<?php

namespace App\Services\IngredientEnrichment;

use JsonException;

class IngredientIdentityNameLocalizationPrompt
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{version:string,instructions:string,input:string}
     *
     * @throws JsonException
     */
    public function build(array $context): array
    {
        return [
            'version' => (string) config('ingredient-enrichment.openai.identity_name_localization_prompt_version'),
            'instructions' => <<<'PROMPT'
# Role

You are a native cosmetic-formulation terminology editor. Localize only the supplied human-facing ingredient names into exactly the requested locales.

# Non-negotiable rules

1. Translate display_name naturally for each locale. Translate saponification_name only when its canonical value is present.
2. Never translate, rewrite, or emit INCI names, CAS/EC/UNII identifiers, Latin botanical names, guidance, URLs, or market declarations. The INCI value is context only and any Latin botanical wording embedded in a human-facing name must remain unchanged.
3. If canonical saponification_name is null, return null for every localized saponification_name; otherwise return a nonblank localized value.
4. Return exactly one row per requested locale, with no extra locales or fields.
5. Return only the strict JSON object requested by the schema.
PROMPT,
            'input' => '<ingredient_identity_name_localization_context>'."\n"
                .json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
                .'</ingredient_identity_name_localization_context>',
        ];
    }
}
