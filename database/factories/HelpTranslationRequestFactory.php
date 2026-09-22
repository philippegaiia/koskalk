<?php

namespace Database\Factories;

use App\Enums\HelpTranslationRequestStatus;
use App\Models\HelpTopicLocale;
use App\Models\HelpTopicRevision;
use App\Models\HelpTranslationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HelpTranslationRequest> */
class HelpTranslationRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'help_topic_locale_id' => HelpTopicLocale::factory()->translated(),
            'source_english_revision_id' => function (array $attributes): int {
                $locale = HelpTopicLocale::query()->findOrFail($attributes['help_topic_locale_id']);
                $english = HelpTopicLocale::query()->where('help_topic_id', $locale->help_topic_id)->where('locale', 'en')->first()
                    ?? HelpTopicLocale::factory()->create(['help_topic_id' => $locale->help_topic_id]);

                return HelpTopicRevision::factory()->create(['help_topic_locale_id' => $english->id])->id;
            },
            'expected_target_lock_version' => fn (array $attributes): int => HelpTopicLocale::query()->findOrFail($attributes['help_topic_locale_id'])->lock_version,
            'status' => HelpTranslationRequestStatus::Pending,
            'model' => 'test-model',
            'prompt_version' => 'test-v1',
            'reasoning_effort' => 'low',
        ];
    }
}
