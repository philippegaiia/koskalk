<?php

namespace App\Services\ContextualHelp;

use App\Data\HelpContentInput;
use App\Models\HelpTopicRevision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class HelpContentRenderer
{
    public function __construct(
        private readonly HelpContentValidator $validator,
    ) {}

    /** @return array{title: string, summary: string, body_html: ?string} */
    public function render(HelpTopicRevision $revision): array
    {
        return Cache::remember('contextual-help:render:v1:'.$revision->public_id, now()->addDay(), fn (): array => $this->renderInput(
            new HelpContentInput($revision->title, $revision->summary, $revision->body_markdown),
        ));
    }

    /** @return array{title: string, summary: string, body_html: ?string} */
    public function renderInput(HelpContentInput $input): array
    {
        $validated = $this->validator->validate($input);

        return [
            'title' => $validated->title,
            'summary' => $validated->summary,
            'body_html' => $this->renderMarkdown($validated->bodyMarkdown),
        ];
    }

    private function renderMarkdown(?string $markdown): ?string
    {
        if (blank($markdown)) {
            return null;
        }
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')->allowElement('h2')->allowElement('h3')
            ->allowElement('strong')->allowElement('em')->allowElement('ul')
            ->allowElement('ol')->allowElement('li')->allowElement('br')
            ->allowElement('a', ['href'])->allowLinkSchemes(['https'])->allowRelativeLinks();

        return (new HtmlSanitizer($config))->sanitize(Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
