<?php

namespace App\Services\ContextualHelp;

use App\Models\HelpTopic;
use App\Models\SupportedLocale;

final class HelpTopicResolver
{
    public function __construct(private readonly HelpTopicRegistry $registry, private readonly HelpContentRenderer $renderer) {}

    /**
     * @param  list<string>  $topicKeys
     * @return array<string, array{key: string, locale: string, title: string, summary: string, body_html: ?string, revision: string}>
     */
    public function resolve(array $topicKeys, string $locale): array
    {
        if (! config('contextual-help.enabled', true)) {
            return [];
        }
        if (app()->environment('local') && ($unknown = array_diff($topicKeys, array_keys($this->registry->definitions()))) !== []) {
            logger()->debug('Unregistered contextual help keys', ['keys' => array_values($unknown)]);
        }
        $keys = array_values(array_intersect($topicKeys, array_keys($this->registry->definitions())));
        if ($keys === []) {
            return [];
        }
        if ($locale !== 'en' && ! SupportedLocale::query()->where('code', $locale)->where('is_active', true)->exists()) {
            $locale = 'en';
        }
        $topics = HelpTopic::query()->whereIn('key', $keys)->whereNull('archived_at')
            ->with(['locales' => fn ($query) => $query->whereIn('locale', array_unique(['en', $locale]))->with('publishedRevision')])
            ->get()->keyBy('key');
        $result = [];
        foreach ($keys as $key) {
            $topic = $topics->get($key);
            $english = $topic?->locales->firstWhere('locale', 'en')?->publishedRevision;
            if (! $english) {
                continue;
            }
            $translated = $topic->locales->firstWhere('locale', $locale)?->publishedRevision;
            $revision = $locale !== 'en' && $translated?->source_english_revision_id === $english->id ? $translated : $english;
            $result[$key] = [
                'key' => $key,
                'locale' => $revision->id === $english->id ? 'en' : $locale,
                ...$this->renderer->render($revision),
                'revision' => $revision->public_id,
            ];
        }

        return $result;
    }
}
