<?php

namespace Database\Factories;

use App\Enums\EvidenceType;
use App\Models\DossierEvidenceFile;
use App\Models\DossierEvidenceItem;
use App\Models\SupplierQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceItem>
 */
class DossierEvidenceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_qualification_id' => SupplierQualification::factory(),
            'workspace_id' => fn (array $attributes): int => SupplierQualification::query()->findOrFail($attributes['supplier_qualification_id'])->workspace_id,
            'dossier_evidence_file_id' => fn (array $attributes): int => DossierEvidenceFile::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'type' => EvidenceType::TechnicalSheet,
            'issued_on' => null,
            'revision_due_on' => null,
            'notes' => null,
            'retired_at' => null,
            'replaced_by_id' => null,
            'created_by_user_id' => null,
        ];
    }
}
