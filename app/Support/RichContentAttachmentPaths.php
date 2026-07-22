<?php

namespace App\Support;

use Illuminate\Support\Collection;

class RichContentAttachmentPaths
{
    /**
     * Extract attachment IDs and rich-content URLs from serialized editor content.
     *
     * @return Collection<int, string>
     */
    public static function extract(mixed $content): Collection
    {
        if (! is_string($content) || $content === '') {
            return collect();
        }

        preg_match_all('/data-id="([^"]+)"/', $content, $dataIdMatches);
        preg_match_all('/(?:src|href)="([^"]*recipes\/(?:[^\/]+\/)?rich-content\/[^"]+)"/', $content, $sourceMatches);

        $sourcePaths = collect($sourceMatches[1] ?? [])
            ->map(function (string $path): string {
                $normalizedPath = parse_url($path, PHP_URL_PATH);

                if (is_string($normalizedPath) && preg_match('~recipes/(?:[^/]+/)?rich-content/.*$~', $normalizedPath, $matches) === 1) {
                    return $matches[0];
                }

                return $path;
            });

        return collect($dataIdMatches[1] ?? [])
            ->merge($sourcePaths)
            ->filter(fn (mixed $value): bool => is_string($value) && str_contains($value, '/rich-content/'))
            ->unique()
            ->values();
    }
}
