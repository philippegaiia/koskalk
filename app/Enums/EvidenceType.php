<?php

namespace App\Enums;

/**
 * Kinds of DIP evidence, with the annex each belongs to (Regulation 1223/2009 Annex I layout used by the DIP)
 * and what it attaches to.
 */
enum EvidenceType: string
{
    case TechnicalSheet = 'technical_sheet';
    case SafetyDataSheet = 'safety_data_sheet';
    case IfraCertificate = 'ifra_certificate';
    case AllergenDeclaration = 'allergen_declaration';
    case RegulatoryConformityCertificate = 'regulatory_conformity_certificate';
    case CertificateOfAnalysis = 'certificate_of_analysis';
    case OrganicCertificate = 'organic_certificate';
    case RawMaterialAnimalTestingStatement = 'raw_material_animal_testing_statement';
    case SupplierSafetyAssessment = 'supplier_safety_assessment';
    case OtherSupplierDocument = 'other_supplier_document';
    case FinishedProductSpecification = 'finished_product_specification';
    case StabilityTest = 'stability_test';
    case PaoAssessment = 'pao_assessment';
    case ChallengeTest = 'challenge_test';
    case SkinIrritationTest = 'skin_irritation_test';
    case MucosaIrritationTest = 'mucosa_irritation_test';
    case OtherSafetyTest = 'other_safety_test';
    case PrimaryPackaging = 'primary_packaging';
    case SecondaryPackaging = 'secondary_packaging';
    case Labelling = 'labelling';
    case ManufacturingMethod = 'manufacturing_method';
    case ClaimEvidence = 'claim_evidence';
    case ProductAnimalTestingStatement = 'product_animal_testing_statement';
    case CpnpNotification = 'cpnp_notification';
    case GmpDeclaration = 'gmp_declaration';
    case AssessorQualification = 'assessor_qualification';

    public function annexLetter(): string
    {
        return match ($this) {
            self::FinishedProductSpecification => 'b',
            self::StabilityTest => 'c',
            self::PaoAssessment => 'd',
            self::ChallengeTest => 'e',
            self::SkinIrritationTest => 'f',
            self::MucosaIrritationTest => 'g',
            self::OtherSafetyTest => 'h',
            self::PrimaryPackaging => 'j',
            self::SecondaryPackaging => 'k',
            self::Labelling => 'l',
            self::AssessorQualification => 'n',
            self::ManufacturingMethod => 'o',
            self::GmpDeclaration => 'p',
            self::ClaimEvidence => 'q',
            self::ProductAnimalTestingStatement => 'r',
            self::CpnpNotification => 's',
            default => 'a',
        };
    }

    public function level(): EvidenceLevel
    {
        return match ($this) {
            self::GmpDeclaration, self::AssessorQualification => EvidenceLevel::Workspace,
            self::FinishedProductSpecification, self::StabilityTest, self::PaoAssessment, self::ChallengeTest,
            self::SkinIrritationTest, self::MucosaIrritationTest, self::OtherSafetyTest, self::PrimaryPackaging,
            self::SecondaryPackaging, self::Labelling, self::ManufacturingMethod, self::ClaimEvidence,
            self::ProductAnimalTestingStatement, self::CpnpNotification => EvidenceLevel::Product,
            default => EvidenceLevel::SupplierQualification,
        };
    }

    /**
     * @return list<ConstituentKind>
     */
    public function constituentKinds(): array
    {
        return match ($this) {
            self::AllergenDeclaration => [ConstituentKind::Allergen],
            self::TechnicalSheet, self::CertificateOfAnalysis => [ConstituentKind::Impurity],
            self::RegulatoryConformityCertificate => [ConstituentKind::RestrictedSubstance],
            default => [],
        };
    }

    /** 'ifra_category', 'product_class' or null. */
    public function useLimitBasis(): ?string
    {
        return match ($this) {
            self::IfraCertificate => 'ifra_category',
            self::RegulatoryConformityCertificate => 'product_class',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function factKeys(): array
    {
        return match ($this) {
            self::TechnicalSheet, self::CertificateOfAnalysis => ['microbiology_specification'],
            self::RegulatoryConformityCertificate, self::IfraCertificate => ['peroxide_value_mmol_l'],
            default => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function forLevel(EvidenceLevel $level): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->level() === $level));
    }
}
