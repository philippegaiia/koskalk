<?php

namespace App\Services\ContextualHelp;

final class ApplicationHelpTopics
{
    public function __construct(private readonly HelpTopicResolver $resolver) {}

    /** @return list<string> */
    public function forSurface(string $surface): array
    {
        return match ($surface) {
            'products' => ['products.getting_started', 'products.finding_and_archiving', 'products.versions_and_copies'],
            'media' => ['media.uploading', 'media.organizing', 'media.reuse_and_removal'],
            'preferences' => ['settings.language', 'settings.numbers'],
            'workspace' => ['settings.workspace'],
            default => [],
        };
    }

    /** @return array{topics: array<string, array>, tabs: array<string, list<string>>} */
    public function resolve(string $surface, string $locale): array
    {
        $topics = $this->resolver->resolve($this->forSurface($surface), $locale);

        return ['topics' => $topics, 'tabs' => ['page' => array_keys($topics)]];
    }

    /** @return list<string> */
    public function locations(string $key): array
    {
        return collect(['products' => 'Products', 'media' => 'Media library', 'preferences' => 'Settings · Preferences', 'workspace' => 'Settings · Workspace'])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface), true))
            ->values()->all();
    }
}
