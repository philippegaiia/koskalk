<?php

namespace App\Services\IngredientEnrichment;

use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use Illuminate\Support\Str;

class UsIngredientDeclarationService
{
    /**
     * @param  array{unii?: string|null, common_name?: string|null, inci_names?: list<string>, cas?: list<string>}  candidate
     */
    public function propose(array $candidate, bool $isColourant = false): IngredientSourceStageResult
    {
        $commonName = trim((string) ($candidate['common_name'] ?? ''));
        if ($commonName === '' || ($isColourant && preg_match('/^CI\s*\d{5}$/i', $commonName) === 1)) {
            $unresolvedMessage = $isColourant
                ? __('ingredient_enrichment.warnings.us_colour_declaration_unresolved')
                : __('ingredient_enrichment.warnings.us_declaration_unresolved');

            return new IngredientSourceStageResult(
                stage: IngredientEnrichmentResearchStage::UsDeclaration,
                status: 'completed',
                data: ['market_code' => 'us', 'declaration_name' => null, 'confidence' => 'unresolved'],
                unresolvedQuestions: [$unresolvedMessage],
            );
        }

        return new IngredientSourceStageResult(
            stage: IngredientEnrichmentResearchStage::UsDeclaration,
            status: 'completed',
            data: [
                'market_code' => 'us',
                'declaration_name' => $this->harmonizedBotanicalName($commonName, $candidate['inci_names'] ?? []),
                'confidence' => 'supported',
            ],
            evidence: [[
                'field' => 'proposal.market_labels.us.declaration_name',
                'source_name' => 'FDA cosmetic ingredient naming guidance',
                'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
                'source_tier' => 'official',
                'confidence' => 'supported',
                'source_version' => '21 CFR 701.3',
                'source_updated_at' => null,
                'retrieved_at' => now()->toImmutable()->toIso8601String(),
            ]],
        );
    }

    /** @param list<string> $inciNames */
    private function harmonizedBotanicalName(string $commonName, array $inciNames): string
    {
        foreach ([$commonName, ...$inciNames] as $name) {
            if (preg_match('/^(?<latin>.+?)\s+\((?<common>[^)]+)\)\s+(?<suffix>.+)$/u', trim($name), $parts) !== 1) {
                continue;
            }

            $plainCommonName = trim($parts['common'].' '.$parts['suffix']);
            if (mb_strtolower($plainCommonName) !== mb_strtolower($commonName)) {
                continue;
            }

            return Str::title($parts['common'])
                .' ('.Str::title($parts['latin']).') '
                .Str::title($parts['suffix']);
        }

        return $commonName;
    }
}
