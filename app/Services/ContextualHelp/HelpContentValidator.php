<?php

namespace App\Services\ContextualHelp;

use App\Data\HelpContentInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\MarkdownParser;

final class HelpContentValidator
{
    public function validate(HelpContentInput $input): HelpContentInput
    {
        $normalized = new HelpContentInput(
            trim($input->title),
            trim($input->summary),
            $input->bodyMarkdown === null ? null : trim(str_replace(["\r\n", "\r"], "\n", $input->bodyMarkdown)),
        );
        Validator::make($normalized->toArray(), [
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:600'],
            'body_markdown' => ['nullable', 'string', 'max:12000'],
        ])->validate();

        foreach (['title' => $normalized->title, 'summary' => $normalized->summary] as $field => $value) {
            if (! preg_match('/[\p{L}\p{N}]/u', $value) || preg_match('/<[^>]*>/', $value)) {
                throw ValidationException::withMessages([$field => __('help_admin.validation.plain_text')]);
            }
        }

        if (filled($normalized->bodyMarkdown)) {
            $this->validateMarkdown($normalized->bodyMarkdown);
        }

        return $normalized;
    }

    private function validateMarkdown(string $markdown): void
    {
        $environment = new Environment(['max_nesting_level' => 20]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $document = (new MarkdownParser($environment))->parse($markdown);
        $allowed = [Document::class, Paragraph::class, Text::class, Newline::class, Heading::class, ListBlock::class, ListItem::class, Emphasis::class, Strong::class, Link::class];

        foreach ($document->iterator() as $node) {
            if (! in_array($node::class, $allowed, true)
                || ($node instanceof Heading && ! in_array($node->getLevel(), [2, 3], true))) {
                throw ValidationException::withMessages(['body_markdown' => __('help_admin.validation.markdown')]);
            }
            if ($node instanceof Link && ! $this->safeLink($node->getUrl())) {
                throw ValidationException::withMessages(['body_markdown' => __('help_admin.validation.link')]);
            }
        }
    }

    private function safeLink(string $url): bool
    {
        $decoded = rawurldecode($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return false;
        }
        if (str_starts_with($decoded, '/')) {
            return ! str_starts_with($decoded, '//');
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null;
    }
}
