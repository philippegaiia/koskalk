<?php

namespace App\Services\IngredientEnrichment;

class IngredientGuidanceSchema
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'info_markdown' => ['type' => 'string'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['info_markdown', 'warnings', 'unresolved_questions'],
            'additionalProperties' => false,
        ];
    }
}
