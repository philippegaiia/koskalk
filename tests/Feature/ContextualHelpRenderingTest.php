<?php

use App\Data\HelpContentInput;
use App\Services\ContextualHelp\HelpContentRenderer;
use App\Services\ContextualHelp\HelpContentValidator;
use Illuminate\Validation\ValidationException;

it('renders supported help markdown with identical plain text fields', function () {
    $input = new HelpContentInput('Water mode', 'Choose the denominator carefully.', "## Water basis\n\nUse **weight** or *percentage*.\n\n- First\n- Second\n\n[Reference](https://example.com/help)");
    $result = app(HelpContentRenderer::class)->renderInput($input);
    expect($result['title'])->toBe($input->title)
        ->and($result['summary'])->toBe($input->summary)
        ->and($result['body_html'])->toContain('<h2>', '<strong>', '<em>', '<ul>', 'href="https://example.com/help"');
});

it('preserves safe app relative help links', function () {
    $result = app(HelpContentRenderer::class)->renderInput(new HelpContentInput('Help', 'Read the related explanation.', '[Related](/calculator)'));
    expect($result['body_html'])->toContain('href="/calculator"');
});

it('preserves ordered instructions and nested choices in help content', function () {
    $input = new HelpContentInput('Adjust stock', 'Record the quantity you counted.', "### Correct a balance\n\n1. Open the lot.\n2. Choose an operation:\n\n   - Set the counted quantity.\n   - Add the extra quantity.\n\n3. Check the unit and save.");

    $result = app(HelpContentRenderer::class)->renderInput($input);

    $document = new DOMDocument;
    $document->loadHTML($result['body_html']);
    $xpath = new DOMXPath($document);
    expect($xpath->query('//h3')->item(0)->textContent)->toBe('Correct a balance');
    expect($xpath->query('//ol/li'))->toHaveCount(3);
    expect($xpath->query('//ol/li[2]/ul/li'))->toHaveCount(2);
    expect($xpath->query('//ol/li[3]')->item(0)->textContent)->toContain('Check the unit and save.');
});

it('rejects unsupported and unsafe authored content', function (string $markdown) {
    app(HelpContentValidator::class)->validate(new HelpContentInput('Help', 'A visible summary.', $markdown));
})->with([
    'html' => '<script>alert(1)</script>',
    'inline html' => 'Hello <span style="color:red">world</span>',
    'image' => '![image](https://example.com/image.png)',
    'unsafe scheme' => '[bad](javascript:alert%281%29)',
    'encoded scheme' => '[bad](jav&#x61;script:alert%281%29)',
    'protocol relative' => '[bad](//example.com)',
    'encoded protocol relative' => '[bad](/%2fexample.com)',
    'http' => '[bad](http://example.com)',
    'code' => '`code`',
    'code block' => "```html\n<p>hello</p>\n```",
    'level one heading' => '# Heading',
    'quote' => '> quoted content',
])->throws(ValidationException::class);

it('normalizes line endings while preserving content and rejects invisible summaries', function () {
    $input = app(HelpContentValidator::class)->validate(new HelpContentInput('  Title  ', ' A summary. ', "Paragraph\r\n\r\nSecond\r\n"));
    expect($input->title)->toBe('Title')->and($input->bodyMarkdown)->toBe("Paragraph\n\nSecond");
    app(HelpContentValidator::class)->validate(new HelpContentInput('Title', "\u{200B}", null));
})->throws(ValidationException::class);

it('enforces help authoring length limits', function () {
    app(HelpContentValidator::class)->validate(new HelpContentInput('Title', str_repeat('a', 601), null));
})->throws(ValidationException::class);

it('supports a concise topic without an expanded body', function () {
    $result = app(HelpContentRenderer::class)->renderInput(new HelpContentInput('Help', 'The complete answer.', null));
    expect($result['body_html'])->toBeNull();
});
