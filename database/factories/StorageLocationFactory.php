<?php

namespace Database\Factories;

use App\Models\StorageLocation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StorageLocation> */
class StorageLocationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return ['workspace_id' => Workspace::factory(), 'name' => $name,
            'normalized_name' => mb_strtolower($name), 'is_active' => true];
    }
}
