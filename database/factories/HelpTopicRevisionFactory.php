<?php

namespace Database\Factories;

use App\Enums\HelpContentOrigin;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HelpTopicRevision> */
class HelpTopicRevisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'help_topic_locale_id' => HelpTopicLocale::factory(),
            'revision_number' => fn (array $attributes): int => 1 + (int) HelpTopicRevision::query()->where('help_topic_locale_id', $attributes['help_topic_locale_id'])->max('revision_number'),
            'title' => fake()->sentence(3),
            'summary' => fake()->sentence(),
            'body_markdown' => fake()->paragraph(),
            'source_english_revision_id' => function (array $attributes): ?int {
                $locale = HelpTopicLocale::query()->findOrFail($attributes['help_topic_locale_id']);
                if ($locale->locale === 'en') {
                    return null;
                }
                $english = HelpTopicLocale::query()->where('help_topic_id', $locale->help_topic_id)->where('locale', 'en')->first()
                    ?? HelpTopicLocale::factory()->create(['help_topic_id' => $locale->help_topic_id]);

                return HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id])->id;
            },
            'origin' => HelpContentOrigin::Human,
            'created_at' => now(),
        ];
    }

    public function translated(string $locale = 'fr'): static
    {
        return $this->state(fn (): array => [
            'help_topic_locale_id' => HelpTopicLocale::factory()->translated($locale),
        ]);
    }
}
