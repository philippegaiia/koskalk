<?php

namespace Database\Factories;

use App\Enums\HelpTopicDomain;
use App\Models\HelpTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HelpTopic> */
class HelpTopicFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'workbench.'.str_replace('-', '_', fake()->unique()->slug(3)),
            'domain' => HelpTopicDomain::SharedWorkbench,
            'archived_at' => null,
        ];
    }
}
