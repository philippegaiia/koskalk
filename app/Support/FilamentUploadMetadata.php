<?php

namespace App\Support;

class FilamentUploadMetadata
{
    /**
     * @param  array{name: string, size: int, type: ?string, url: ?string}|null  $metadata
     * @param  string|array<string, string>|null  $storedFileNames
     * @return array{name: string, size: int, type: ?string, url: ?string}|null
     */
    public static function applyDisplayName(?array $metadata, string|array|null $storedFileNames, string $fallback): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $displayName = is_string($storedFileNames) && filled($storedFileNames)
            ? $storedFileNames
            : self::singleStoredFileName($storedFileNames);

        $metadata['name'] = $displayName ?? $fallback;

        return $metadata;
    }

    /**
     * @param  string|array<string, string>|null  $storedFileNames
     */
    private static function singleStoredFileName(string|array|null $storedFileNames): ?string
    {
        if (! is_array($storedFileNames)) {
            return null;
        }

        $storedFileNames = array_values(array_filter(
            $storedFileNames,
            static fn (mixed $storedFileName): bool => is_string($storedFileName) && filled($storedFileName),
        ));

        return count($storedFileNames) === 1 ? $storedFileNames[0] : null;
    }
}
