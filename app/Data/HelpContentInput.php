<?php

namespace App\Data;

final readonly class HelpContentInput
{
    public function __construct(
        public string $title,
        public string $summary,
        public ?string $bodyMarkdown,
    ) {}

    /** @return array{title: string, summary: string, body_markdown: ?string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'summary' => $this->summary, 'body_markdown' => $this->bodyMarkdown];
    }
}
