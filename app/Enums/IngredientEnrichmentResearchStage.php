<?php

namespace App\Enums;

enum IngredientEnrichmentResearchStage: string
{
    case IdentityPreparation = 'identity_preparation';
    case EuStructured = 'eu_structured';
    case EuOfficial = 'eu_official';
    case UsIdentity = 'us_identity';
    case UsDeclaration = 'us_declaration';
    case ConflictEvaluation = 'conflict_evaluation';
    case AiEditorial = 'ai_editorial';
    case Validation = 'validation';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::IdentityPreparation,
            self::UsIdentity,
            self::EuStructured,
            self::EuOfficial,
            self::UsDeclaration,
            self::ConflictEvaluation,
            self::AiEditorial,
            self::Validation,
        ];
    }

    /**
     * @return list<self>
     */
    public function downstream(): array
    {
        $position = array_search($this, self::ordered(), true);

        return $position === false ? [] : array_values(array_slice(self::ordered(), $position + 1));
    }

    public function label(): string
    {
        return __('ingredient_enrichment.research_stages.'.$this->value);
    }
}
