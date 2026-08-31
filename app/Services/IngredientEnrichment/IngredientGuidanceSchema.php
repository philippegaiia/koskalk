<?php

namespace App\Services\IngredientEnrichment;

class IngredientGuidanceSchema
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $nonUsageClaimTypes = collect(config('ingredient-enrichment.openai.guidance_research.allowed_claim_types'))
            ->reject(fn (string $claimType): bool => $claimType === 'usage')
            ->values()
            ->all();
        $claim = [
            'anyOf' => [
                $this->claim($nonUsageClaimTypes, ['not_applicable']),
                $this->claim(['usage'], ['cosmetics', 'soapmaking']),
            ],
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

    /**
     * @param  list<string>  $claimTypes
     * @param  list<string>  $usageApplications
     * @return array<string, mixed>
     */
    private function claim(array $claimTypes, array $usageApplications): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'claim_type' => [
                    'type' => 'string',
                    'enum' => $claimTypes,
                ],
                'support_type' => ['type' => 'string', 'enum' => ['evidence', 'fact']],
                'evidence_indexes' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'fact_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'usage_application' => [
                    'type' => 'string',
                    'enum' => $usageApplications,
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
    }
}
