<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceDossierSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceDossierSetting>
 */
class WorkspaceDossierSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'responsible_person_name' => fake()->company(),
            'responsible_person_address' => fake()->address(),
            'responsible_person_siret' => fake()->numerify('##############'),
            'manufacturer_name' => null,
            'manufacturer_address' => null,
            'packer_name' => null,
            'packer_address' => null,
            'numbering_prefix' => 'GA',
            'assessor_name' => null,
            'assessor_address' => null,
            'assessor_qualification' => null,
        ];
    }
}
