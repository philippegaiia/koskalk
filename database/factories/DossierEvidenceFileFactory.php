<?php

namespace Database\Factories;

use App\Models\DossierEvidenceFile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEvidenceFile>
 */
class DossierEvidenceFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'disk' => config('dossiers.evidence_disk'),
            'path' => 'dossier-evidence/test/'.fake()->uuid().'.pdf',
            'original_filename' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', fake()->uuid()),
            'uploaded_by_user_id' => null,
        ];
    }
}
