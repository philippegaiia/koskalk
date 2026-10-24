<?php

namespace Database\Factories;

use App\Enums\UseLimitType;
use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceUseLimit>
 */
class DossierEvidenceUseLimitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_evidence_item_id' => DossierEvidenceItem::factory(),
            'ifra_product_category_id' => null,
            'product_class_label' => 'Autres produits à rincer',
            'max_percent' => '100',
            'limit_type' => UseLimitType::Maximum,
        ];
    }
}
