<?php

namespace App\Services\Translations;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

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

        foreach (File::files(lang_path('en')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $group = $file->getFilenameWithoutExtension();
            $lines = require $file->getPathname();

            if (! is_array($lines)) {
                continue;
            }

            foreach (Arr::dot($lines) as $key => $value) {
                if (is_string($value)) {
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
