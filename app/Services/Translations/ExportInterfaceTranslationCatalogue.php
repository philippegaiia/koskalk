<?php

namespace App\Services\Translations;

use App\Exceptions\InvalidInterfaceTranslationCatalogue;
use App\Models\InterfaceTranslation;
use App\Models\SupportedLocale;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class ExportInterfaceTranslationCatalogue
{
    public function __construct(
        private readonly InterfaceTranslationCatalogue $catalogue,
        private readonly EnglishTranslationSource $englishSource,
    ) {}

    /**
     * @return array{path: string, rows: int, locales: int, sha256: string}
     */
    public function handle(mixed $requestedPath = null): array
    {
        $path = $this->catalogue->resolvePath($requestedPath);
        $locales = SupportedLocale::query()
            ->where('code', '!=', 'en')
            ->orderBy('code')
            ->pluck('code')
            ->all();
        $ownedKeys = array_fill_keys(array_keys($this->englishSource->all()), true);

        $translations = InterfaceTranslation::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get(['group', 'key', 'text'])
            ->filter(fn (InterfaceTranslation $translation): bool => isset(
                $ownedKeys["{$translation->group}.{$translation->key}"],
            ))
            ->values()
            ->map(function (InterfaceTranslation $translation): array {
                $text = $translation->text ?? [];
                ksort($text);

                return [
                    'group' => $translation->group,
                    'key' => $translation->key,
                    'text' => $text,
                ];
            })
            ->all();

        $payload = $this->catalogue->validate([
            'format' => InterfaceTranslationCatalogue::FORMAT,
            'version' => InterfaceTranslationCatalogue::VERSION,
            'locales' => $locales,
            'translations' => $translations,
        ]);

        $jsonPayload = $payload;
        $jsonPayload['translations'] = array_map(
            fn (array $translation): array => [
                'group' => $translation['group'],
                'key' => $translation['key'],
                'text' => $translation['text'] === [] ? new stdClass : $translation['text'],
            ],
            $payload['translations'],
        );
        $contents = json_encode(
            $jsonPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        File::ensureDirectoryExists(dirname($path));
        $temporaryPath = $path.'.tmp.'.Str::random(12);

        try {
            $writtenBytes = File::put($temporaryPath, $contents);

            if ($writtenBytes !== strlen($contents)) {
                throw new InvalidInterfaceTranslationCatalogue("The catalogue could not be fully written to [{$temporaryPath}].");
            }

            if (! File::move($temporaryPath, $path)) {
                throw new InvalidInterfaceTranslationCatalogue("The catalogue could not replace [{$path}].");
            }
        } catch (Throwable $exception) {
            File::delete($temporaryPath);

            throw $exception;
        }

        $checksum = hash_file('sha256', $path);

        if ($checksum === false) {
            throw new InvalidInterfaceTranslationCatalogue("The catalogue checksum could not be calculated for [{$path}].");
        }

        return [
            'path' => $path,
            'rows' => count($translations),
            'locales' => count($locales),
            'sha256' => $checksum,
        ];
    }
}
