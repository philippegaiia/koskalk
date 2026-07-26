<?php

namespace App\Services\Translations;

use App\Exceptions\InvalidInterfaceTranslationCatalogue;
use App\Models\SupportedLocale;
use App\Rules\PreservesTranslationPlaceholders;
use Illuminate\Support\Facades\Validator;
use JsonException;

class InterfaceTranslationCatalogue
{
    public const DEFAULT_RELATIVE_PATH = 'database/seeders/data/interface-translations.json';

    public const FORMAT = 'soapkraft-interface-translations';

    public const VERSION = 1;

    public function __construct(private readonly EnglishTranslationSource $englishSource) {}

    public function resolvePath(mixed $path): string
    {
        if ($path === null || $path === '') {
            return base_path(self::DEFAULT_RELATIVE_PATH);
        }

        if (! is_string($path)) {
            throw new InvalidInterfaceTranslationCatalogue('The catalogue path must be a string.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return array{
     *     format: string,
     *     version: int,
     *     locales: list<string>,
     *     translations: list<array{group: string, key: string, text: array<string, string>}>
     * }
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidInterfaceTranslationCatalogue("The translation catalogue is not readable at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidInterfaceTranslationCatalogue("The translation catalogue could not be read at [{$path}].");
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidInterfaceTranslationCatalogue(
                "The translation catalogue contains invalid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $this->validate($payload);
    }

    /**
     * @return array{
     *     format: string,
     *     version: int,
     *     locales: list<string>,
     *     translations: list<array{group: string, key: string, text: array<string, string>}>
     * }
     */
    public function validate(mixed $payload): array
    {
        if (! is_array($payload) || ! $this->hasExactKeys($payload, [
            'format',
            'version',
            'locales',
            'translations',
        ])) {
            throw new InvalidInterfaceTranslationCatalogue('The translation catalogue has an invalid top-level structure.');
        }

        if ($payload['format'] !== self::FORMAT || $payload['version'] !== self::VERSION) {
            throw new InvalidInterfaceTranslationCatalogue('The translation catalogue format or version is unsupported.');
        }

        $locales = $this->validateLocales($payload['locales']);
        $translations = $this->validateTranslations($payload['translations'], $locales);

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'locales' => $locales,
            'translations' => $translations,
        ];
    }

    /**
     * @return list<string>
     */
    private function validateLocales(mixed $locales): array
    {
        if (! is_array($locales) || ! array_is_list($locales)) {
            throw new InvalidInterfaceTranslationCatalogue('The catalogue locales must be a list.');
        }

        foreach ($locales as $locale) {
            if (! is_string($locale) || $locale === '' || $locale === 'en') {
                throw new InvalidInterfaceTranslationCatalogue('Catalogue locales must be non-English locale codes.');
            }
        }

        if (count($locales) !== count(array_unique($locales))) {
            throw new InvalidInterfaceTranslationCatalogue('Catalogue locale codes must be unique.');
        }

        $sortedLocales = $locales;
        sort($sortedLocales);

        if ($locales !== $sortedLocales) {
            throw new InvalidInterfaceTranslationCatalogue('Catalogue locale codes must be sorted.');
        }

        $supportedLocales = SupportedLocale::query()
            ->where('code', '!=', 'en')
            ->pluck('code')
            ->all();
        $unsupportedLocales = array_diff($locales, $supportedLocales);

        if ($unsupportedLocales !== []) {
            throw new InvalidInterfaceTranslationCatalogue(
                'The catalogue contains unsupported locales: '.implode(', ', $unsupportedLocales).'.',
            );
        }

        return $locales;
    }

    /**
     * @param  list<string>  $locales
     * @return list<array{group: string, key: string, text: array<string, string>}>
     */
    private function validateTranslations(mixed $translations, array $locales): array
    {
        if (! is_array($translations) || ! array_is_list($translations)) {
            throw new InvalidInterfaceTranslationCatalogue('The catalogue translations must be a list.');
        }

        $validated = [];
        $seenKeys = [];
        $previousFullKey = null;

        foreach ($translations as $index => $translation) {
            if (! is_array($translation) || ! $this->hasExactKeys($translation, ['group', 'key', 'text'])) {
                throw new InvalidInterfaceTranslationCatalogue("Translation at index {$index} has an invalid structure.");
            }

            $group = $translation['group'];
            $key = $translation['key'];

            if (! is_string($group) || $group === '' || mb_strlen($group) > 255) {
                throw new InvalidInterfaceTranslationCatalogue("Translation at index {$index} has an invalid group.");
            }

            if (! is_string($key) || $key === '' || mb_strlen($key) > 255) {
                throw new InvalidInterfaceTranslationCatalogue("Translation at index {$index} has an invalid key.");
            }

            $fullKey = "{$group}.{$key}";

            if (isset($seenKeys[$fullKey])) {
                throw new InvalidInterfaceTranslationCatalogue("The catalogue contains duplicate key [{$fullKey}].");
            }

            if ($previousFullKey !== null && strcmp($previousFullKey, $fullKey) > 0) {
                throw new InvalidInterfaceTranslationCatalogue('Catalogue translations must be sorted by group and key.');
            }

            $english = $this->englishSource->get($group, $key);

            if ($english === null) {
                throw new InvalidInterfaceTranslationCatalogue("The catalogue key [{$fullKey}] is not application-owned.");
            }

            $text = $this->validateText($translation['text'], $locales, $english, $fullKey);

            $seenKeys[$fullKey] = true;
            $previousFullKey = $fullKey;
            $validated[] = [
                'group' => $group,
                'key' => $key,
                'text' => $text,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function validateText(mixed $text, array $locales, string $english, string $fullKey): array
    {
        if (! is_array($text) || ($text !== [] && array_is_list($text))) {
            throw new InvalidInterfaceTranslationCatalogue("The translations for [{$fullKey}] must be a locale map.");
        }

        $textLocales = array_keys($text);
        $sortedTextLocales = $textLocales;
        sort($sortedTextLocales);

        if ($textLocales !== $sortedTextLocales) {
            throw new InvalidInterfaceTranslationCatalogue("The locale values for [{$fullKey}] must be sorted.");
        }

        $unlistedLocales = array_diff($textLocales, $locales);

        if ($unlistedLocales !== []) {
            throw new InvalidInterfaceTranslationCatalogue(
                "The translations for [{$fullKey}] contain unlisted locales: ".implode(', ', $unlistedLocales).'.',
            );
        }

        if ($text !== [] && $textLocales !== $locales) {
            throw new InvalidInterfaceTranslationCatalogue(
                "The translations for [{$fullKey}] must include every catalogue locale or be empty.",
            );
        }

        foreach ($text as $locale => $value) {
            if (! is_string($value)) {
                throw new InvalidInterfaceTranslationCatalogue(
                    "The [{$locale}] value for [{$fullKey}] must be a string.",
                );
            }

            $validator = Validator::make(
                ['translation' => $value],
                ['translation' => [new PreservesTranslationPlaceholders($english)]],
            );

            if ($validator->fails()) {
                throw new InvalidInterfaceTranslationCatalogue(
                    "The [{$locale}] value for [{$fullKey}] does not preserve the English placeholders.",
                );
            }
        }

        /** @var array<string, string> $text */
        return $text;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($keys);

        return $actualKeys === $keys;
    }
}
