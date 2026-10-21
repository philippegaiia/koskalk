<?php

namespace Database\Factories;

use App\Enums\ConstituentKind;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceConstituent>
 */
class DossierEvidenceConstituentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_evidence_item_id' => DossierEvidenceItem::factory(),
            'kind' => ConstituentKind::Allergen,
            'allergen_id' => Allergen::factory(),
            'substance_id' => null,
            'max_value' => '0.5',
            'unit' => 'percent',
            'note' => null,
        ];
    }
}
