<?php

namespace Database\Factories;

use App\Enums\FileExportType;
use App\Models\FileExport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FileExport> */
class FileExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'type' => FileExportType::Ingredients,
            'status' => 'pending',
            'parameters' => ['format' => 'csv'],
            'locale' => 'en',
            'disk' => 'local',
            'expires_at' => now()->addDay(),
        ];
    }
}
