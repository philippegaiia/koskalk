<?php

namespace App\Services\Translations;

use App\Enums\InterfaceTranslationImportMode;
use App\Models\InterfaceTranslation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportInterfaceTranslationCatalogue
{
    public function __construct(private readonly InterfaceTranslationCatalogue $catalogue) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, preserved: int, rows: int, path: string}
     */
    public function handle(InterfaceTranslationImportMode $mode, mixed $requestedPath = null): array
    {
        $path = $this->catalogue->resolvePath($requestedPath);
        $payload = $this->catalogue->read($path);

        $result = DB::transaction(function () use ($payload, $mode): array {
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $preserved = 0;
            $cacheKeys = [];

            foreach ($payload['translations'] as $catalogueTranslation) {
                $translation = InterfaceTranslation::query()
                    ->where('group', $catalogueTranslation['group'])
                    ->where('key', $catalogueTranslation['key'])
                    ->lockForUpdate()
                    ->first();

                if ($translation === null) {
                    InterfaceTranslation::query()->create([
                        'group' => $catalogueTranslation['group'],
                        'key' => $catalogueTranslation['key'],
                        'text' => $catalogueTranslation['text'],
                    ]);

                    foreach (array_keys($catalogueTranslation['text']) as $locale) {
                        $cacheKeys[InterfaceTranslation::getCacheKey($catalogueTranslation['group'], $locale)] = true;
                    }

                    $created++;

                    continue;
                }

                $currentText = $translation->text ?? [];
                $nextText = $mode === InterfaceTranslationImportMode::Authoritative
                    ? $catalogueTranslation['text']
                    : $this->preserveExisting($currentText, $catalogueTranslation['text'], $preserved);

                ksort($currentText);
                ksort($nextText);

                if ($currentText === $nextText) {
                    $unchanged++;

                    continue;
                }

                $localesToFlush = array_unique(array_merge(array_keys($currentText), array_keys($nextText)));

                $translation->forceFill(['text' => $nextText])->save();

                foreach ($localesToFlush as $locale) {
                    $cacheKeys[InterfaceTranslation::getCacheKey($translation->group, $locale)] = true;
                }

                $updated++;
            }

            return compact('created', 'updated', 'unchanged', 'preserved', 'cacheKeys');
        });

        foreach (array_keys($result['cacheKeys']) as $cacheKey) {
            Cache::forget($cacheKey);
        }

        return [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'preserved' => $result['preserved'],
            'rows' => count($payload['translations']),
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, string>  $catalogue
     * @return array<string, mixed>
     */
    private function preserveExisting(array $current, array $catalogue, int &$preserved): array
    {
        foreach ($catalogue as $locale => $value) {
            $existingValue = $current[$locale] ?? null;

            if ($existingValue !== null && $existingValue !== '') {
                if ($existingValue !== $value) {
                    $preserved++;
                }

                continue;
            }

            $current[$locale] = $value;
        }

        return $current;
    }
}
