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
            'formula-sharing' => ['sharing.sending', 'sharing.receiving', 'sharing.ingredients'],
            'formula-share-create' => ['sharing.sending'],
            'formula-share-review' => ['sharing.receiving', 'sharing.ingredients'],
            'media' => ['media.uploading', 'media.organizing', 'media.reuse_and_removal'],
            'preferences' => ['settings.language', 'settings.numbers'],
            'workspace' => ['settings.workspace', 'workspaces.overview', 'workspaces.shared_allowances'],
            'members' => ['workspaces.roles', 'workspaces.invitations', 'workspaces.shared_allowances'],
            'workspace-selection' => ['workspaces.overview'],
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
        return collect([
            'products' => 'Products',
            'formula-sharing' => 'Formula sharing',
            'formula-share-create' => 'Share Saved formula',
            'formula-share-review' => 'Review shared formula',
            'media' => 'Media library',
            'preferences' => 'Settings · Preferences',
            'workspace' => 'Settings · Workspace',
            'members' => 'Settings · Members',
            'workspace-selection' => 'Company selection',
        ])
            ->filter(fn (string $label, string $surface): bool => in_array($key, $this->forSurface($surface), true))
            ->values()->all();
    }
}
