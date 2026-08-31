<?php

namespace App\Services;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class WorkspaceIngredientGuidanceContent
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('br')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https']);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function fromPlatformMarkdown(?string $markdown): ?string
    {
        if (blank($markdown)) {
            return null;
        }

        return $this->sanitize(Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }

    public function sanitize(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        if (strip_tags($html) === $html) {
            $html = '<p>'.e($html).'</p>';
        }

        $normalized = RichContentRenderer::make($html)->toUnsafeHtml();
        $sanitized = $this->sanitizer->sanitize($normalized);

        if (blank($this->extractText($sanitized))) {
            return null;
        }

        return $sanitized;
    }

    public function text(?string $html): string
    {
        $sanitized = $this->sanitize($html);

        return $sanitized === null ? '' : $this->extractText($sanitized);
    }

    public function length(?string $html): int
    {
        return Str::length($this->text($html));
    }

    private function extractText(string $html): string
    {
        return trim(RichContentRenderer::make($html)->toText());
    }
}
