<?php

namespace Database\Factories;

use App\Enums\HelpContentExportReason;
use App\Enums\HelpContentExportStatus;
use App\Models\HelpContentExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HelpContentExport> */
class HelpContentExportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'status' => HelpContentExportStatus::Pending,
            'format_version' => 1,
            'reason' => HelpContentExportReason::Manual,
        ];
    }
}
