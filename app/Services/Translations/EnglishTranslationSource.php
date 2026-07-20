<?php

namespace App\Services\Translations;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class EnglishTranslationSource
{
    /**
     * @var array<string, string>|null
     */
    private ?array $translations = null;

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->translations !== null) {
            return $this->translations;
        }

        $translations = [];

        foreach (config('interface-translations.sources', []) as $group => $patterns) {
            $path = lang_path("en/{$group}.php");

            if (! File::exists($path)) {
                continue;
            }

            $lines = require $path;

            if (! is_array($lines)) {
                continue;
            }

            foreach (Arr::dot($lines) as $key => $value) {
                if (is_string($value) && Str::is($patterns, $key)) {
                    $translations["{$group}.{$key}"] = $value;
                }
            }
        }

        ksort($translations);

        return $this->translations = $translations;
    }

    public function get(string $group, string $key): ?string
    {
        return $this->all()["{$group}.{$key}"] ?? null;
    }
}
