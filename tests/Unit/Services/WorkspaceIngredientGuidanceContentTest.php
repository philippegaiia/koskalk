<?php

use App\Services\WorkspaceIngredientGuidanceContent;
use Tests\TestCase;

uses(TestCase::class);

function guidanceContent(): WorkspaceIngredientGuidanceContent
{
    return new WorkspaceIngredientGuidanceContent;
}

it('converts platform markdown into restricted rich html', function (): void {
    $html = guidanceContent()->fromPlatformMarkdown(
        "## Overview\n\nUse **softening** oils.\n\n- One\n- Two\n\n[Read more](https://example.com)",
    );

    expect($html)
        ->toContain('<h2>Overview</h2>')
        ->toContain('<strong>softening</strong>')
        ->toContain('<ul>')
        ->toContain('<a href="https://example.com">Read more</a>')
        ->not->toContain('##');
});

it('strips unsupported and dangerous html while keeping allowed formatting', function (): void {
    $html = guidanceContent()->sanitize(
        '<h2 class="x" style="color:red">Title</h2><p><strong>Bold</strong> <em>italic</em></p>'.
        '<table><tr><td>Hidden table</td></tr></table>'.
        '<img src="https://example.com/x.png"><script>alert(1)</script>'.
        '<p><a href="javascript:alert(1)" onclick="alert(2)">Bad</a> '.
        '<a href="https://example.com">Good</a></p>',
    );

    expect($html)
        ->toBe('<h2>Title</h2><p><strong>Bold</strong> <em>italic</em></p><p>Bad <a href="https://example.com">Good</a></p>')
        ->not->toContain('script')
        ->not->toContain('style')
        ->not->toContain('<table')
        ->not->toContain('<img')
        ->not->toContain('onclick');
});

it('counts visible unicode text and treats markup-only content as blank', function (): void {
    $content = guidanceContent();

    expect($content->text('<h2>Été</h2><p>界<strong>!</strong></p>'))->toBe("Été\n\n界\n\n!")
        ->and($content->length('<p>Été界!</p>'))->toBe(5)
        ->and($content->text('<p><br></p>'))->toBe('')
        ->and($content->sanitize('<p><br></p>'))->toBeNull();
});

it('drops non-http link schemes during markdown conversion', function (): void {
    $html = guidanceContent()->fromPlatformMarkdown(
        '[Mail](mailto:test@example.com) [Phone](tel:+33123456789) [Safe](http://example.com)',
    );

    expect($html)
        ->toContain('<a>Mail</a>')
        ->toContain('<a>Phone</a>')
        ->toContain('<a href="http://example.com">Safe</a>');
});
