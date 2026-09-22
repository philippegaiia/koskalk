<?php

namespace Database\Factories;

use App\Models\HelpTopic;
use App\Models\HelpTopicLocale;
use App\Models\SupportedLocale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HelpTopicLocale> */
class HelpTopicLocaleFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'help_topic_id' => HelpTopic::factory(),
            'locale' => fn (): string => $this->supportedLocale('en'),
            'lock_version' => 0,
        ];
    }

    public function translated(string $locale = 'fr'): static
    {
        return $this->state(fn (): array => [
            'locale' => fn (): string => $this->supportedLocale($locale),
        ]);
    }

    private function supportedLocale(string $code): string
    {
        if (! SupportedLocale::query()->where('code', $code)->exists()) {
            SupportedLocale::factory()->create(['code' => $code]);
        }

        return $code;
    }
}
