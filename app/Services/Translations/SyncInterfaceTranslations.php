<?php

namespace App\Services\Translations;

use App\Models\InterfaceTranslation;
use Illuminate\Support\Str;

class SyncInterfaceTranslations
{
    public function __construct(private readonly EnglishTranslationSource $source) {}

    /**
     * @return array{created: int, existing: int, pruned: int}
     */
    public function handle(bool $prune = false): array
    {
        $created = 0;
        $existing = 0;
        $pruned = 0;
        $ownedKeys = array_keys($this->source->all());

        foreach ($ownedKeys as $fullKey) {
            $translation = InterfaceTranslation::query()->firstOrCreate([
                'group' => Str::before($fullKey, '.'),
                'key' => Str::after($fullKey, '.'),
            ], [
                'text' => [],
            ]);

            $translation->wasRecentlyCreated ? $created++ : $existing++;
        }

        if ($prune) {
            InterfaceTranslation::query()
                ->get()
                ->each(function (InterfaceTranslation $translation) use (&$pruned, $ownedKeys): void {
                    $fullKey = "{$translation->group}.{$translation->key}";

                    if (! in_array($fullKey, $ownedKeys, true)) {
                        $translation->delete();
                        $pruned++;
                    }
                });
        }

        return compact('created', 'existing', 'pruned');
    }
}
