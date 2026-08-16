<?php

use App\Enums\IngredientEnrichmentResearchStage;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientValueProvenance;

it('defines stable hybrid enrichment vocabulary', function (): void {
    expect(collect(IngredientEvidenceConfidence::cases())->map->value->all())->toBe([
        'verified',
        'supported',
        'conflicting',
        'unresolved',
    ])->and(collect(IngredientSourceTier::cases())->map->value->all())->toBe([
        'official',
        'structured_mirror',
        'editorial',
        'reviewer_supplied',
    ])->and(collect(IngredientValueProvenance::cases())->map->value->all())->toBe([
        'source_confirmed',
        'ai_proposed',
        'reviewer_supplied',
        'unresolved',
    ])->and(collect(IngredientIdentifierScheme::cases())->map->value->all())->toContain(
        'pubchem_sid',
        'cosing_ref',
    )->and(IngredientIdentifierScheme::PubchemSid->label())->toBe('PubChem SID')
        ->and(IngredientIdentifierScheme::CosingRef->label())->toBe('CosIng reference')
        ->and(IngredientEnrichmentResearchStage::EuStructured->downstream())->toBe([
            IngredientEnrichmentResearchStage::EuOfficial,
            IngredientEnrichmentResearchStage::UsDeclaration,
            IngredientEnrichmentResearchStage::ConflictEvaluation,
            IngredientEnrichmentResearchStage::AiEditorial,
            IngredientEnrichmentResearchStage::Validation,
        ]);
});
