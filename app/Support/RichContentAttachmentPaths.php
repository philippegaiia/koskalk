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

    /**
     * Extract distinct image identities from saved HTML or live TipTap state.
     *
     * @return Collection<int, string>
     */
    public static function extractImageIdentities(mixed $content): Collection
    {
        if (is_string($content)) {
            return static::extractSavedHtmlImageIdentities($content);
        }

        if (! is_array($content)) {
            return collect();
        }

        $identities = [];
        static::collectTipTapImageIdentities($content, $identities);

        return collect($identities)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private static function extractSavedHtmlImageIdentities(string $content): Collection
    {
        preg_match_all('/<img\b[^>]*>/i', $content, $imageMatches);

        return collect($imageMatches[0] ?? [])
            ->map(fn (string $image): mixed => static::extract($image)
                ->first(fn (string $path): bool => static::hasSupportedImageExtension($path)))
            ->filter(fn (mixed $identity): bool => is_string($identity) && $identity !== '')
            ->unique()
            ->values();
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<int, string>  $identities
     */
    private static function collectTipTapImageIdentities(array $node, array &$identities): void
    {
        if (array_is_list($node)) {
            foreach ($node as $childNode) {
                if (is_array($childNode)) {
                    static::collectTipTapImageIdentities($childNode, $identities);
                }
            }

            return;
        }

        if (($node['type'] ?? null) === 'image') {
            $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $identity = $attributes['id'] ?? $attributes['src'] ?? null;

            if (is_string($identity) && $identity !== '') {
                $identities[] = $identity;
            }
        }

        $content = $node['content'] ?? null;

        if (is_array($content)) {
            static::collectTipTapImageIdentities($content, $identities);
        }
    }

    private static function hasSupportedImageExtension(string $path): bool
    {
        $pathWithoutQuery = parse_url($path, PHP_URL_PATH);
        $extension = pathinfo(is_string($pathWithoutQuery) ? $pathWithoutQuery : $path, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), ['jpeg', 'jpg', 'png', 'webp'], true);
    }
}
