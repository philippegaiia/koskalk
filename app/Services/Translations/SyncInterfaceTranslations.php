<?php

namespace App\Services\Translations;

use App\Models\InterfaceTranslation;
use Illuminate\Support\Str;

class SyncInterfaceTranslations
{
    public function __construct(private readonly EnglishTranslationSource $source) {}

    /**
     * @return array{created: int, existing: int}
     */
    public function handle(): array
    {
        $created = 0;
        $existing = 0;

        foreach (array_keys($this->source->all()) as $fullKey) {
            $translation = InterfaceTranslation::query()->firstOrCreate([
                'group' => Str::before($fullKey, '.'),
                'key' => Str::after($fullKey, '.'),
            ], [
                'text' => [],
            ]);

            $translation->wasRecentlyCreated ? $created++ : $existing++;
        }

        return compact('created', 'existing');
    }
}
