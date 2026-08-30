<?php

namespace App\Services\IngredientEnrichment;

class IngredientGuidanceSchema
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $claim = [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'claim_type' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_claim_types'),
                ],
                'support_type' => ['type' => 'string', 'enum' => ['evidence', 'fact']],
                'evidence_indexes' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'fact_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'usage_application' => [
                    'type' => 'string',
                    'enum' => config('ingredient-enrichment.openai.guidance_research.allowed_usage_applications'),
                ],
            ],
            'required' => [
                'text',
                'claim_type',
                'support_type',
                'evidence_indexes',
                'fact_paths',
                'usage_application',
            ],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'overview' => ['type' => 'array', 'items' => $claim],
                'formulation_use' => ['type' => 'array', 'items' => $claim],
                'soapmaking' => ['type' => 'array', 'items' => $claim],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                'unresolved_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['overview', 'formulation_use', 'soapmaking', 'warnings', 'unresolved_questions'],
            'additionalProperties' => false,
        ];
    }
}
