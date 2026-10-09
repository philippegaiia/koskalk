<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

class FormulaShareContentSanitizer
{
    private const HTML_ELEMENTS = ['html', 'body', 'p', 'div', 'span', 'a', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'mark', 'small', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'br', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'hr'];

    private const JSON_ELEMENTS = ['doc', 'paragraph', 'heading', 'text', 'bulletList', 'orderedList', 'listItem', 'hardBreak', 'blockquote', 'codeBlock', 'table', 'tableRow', 'tableCell', 'tableHeader', 'horizontalRule'];

    public function plainText(mixed $content): string
    {
        $excluded = false;

        return $this->normalize($this->read($content, $excluded));
    }

    public function containsExcludedMedia(mixed $content): bool
    {
        $excluded = false;
        $this->read($content, $excluded);

        return $excluded;
    }

    public function richText(string $text): string
    {
        return collect(explode("\n\n", $text))->map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>')->implode('');
    }

    private function read(mixed $content, bool &$excluded): string
    {
        if ($content === null || $content === '') {
            return '';
        }
        if (is_string($content)) {
            if (strlen($content) > (int) config('workspaces.formula_sharing.limits.bytes', 2097152)) {
                throw ValidationException::withMessages(['content' => __('sharing.validation.snapshot_size')]);
            }
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['type'])) {
                $content = $decoded;
            }
        }
        $count = 0;
        if (is_array($content)) {
            return $this->jsonText($content, $excluded, $count);
        }
        if (! is_string($content)) {
            throw ValidationException::withMessages(['content' => __('sharing.validation.content')]);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$content.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);

            return $body === null ? '' : $this->htmlText($body, $excluded, $count);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function htmlText(DOMNode $node, bool &$excluded, int &$count, int $depth = 0): string
    {
        $this->assertBounded(++$count, $depth);
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $node->nodeValue ?? '';
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $name = strtolower($node->tagName);
        $attachment = $node->hasAttribute('data-attachment-id') || $node->hasAttribute('data-file-id')
            || preg_match('/attachment|image|file/i', $node->getAttribute('data-type')) === 1;
        if (! in_array($name, self::HTML_ELEMENTS, true) || $attachment) {
            $excluded = true;

            return '';
        }
        if ($name === 'br') {
            return "\n";
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->htmlText($child, $excluded, $count, $depth + 1);
        }
        if (in_array($name, ['li', 'tr', 'td', 'th'], true)) {
            $text = rtrim($text);
        }

        return $text.$this->boundary($name);
    }

    /** @param array<string, mixed> $node */
    private function jsonText(array $node, bool &$excluded, int &$count, int $depth = 0): string
    {
        $this->assertBounded(++$count, $depth);
        $type = $node['type'] ?? null;
        if (! in_array($type, self::JSON_ELEMENTS, true)) {
            $excluded = true;

            return '';
        }
        if ($type === 'text') {
            return is_string($node['text'] ?? null) ? $node['text'] : '';
        }
        if ($type === 'hardBreak') {
            return "\n";
        }
        $text = '';
        $children = $node['content'] ?? [];
        if (! is_array($children)) {
            throw ValidationException::withMessages(['content' => __('sharing.validation.content')]);
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $text .= $this->jsonText($child, $excluded, $count, $depth + 1);
            }
        }
        if (in_array($type, ['listItem', 'tableRow', 'tableCell', 'tableHeader'], true)) {
            $text = rtrim($text);
        }

        return $text.$this->boundary($type);
    }

    private function boundary(string $type): string
    {
        return match ($type) {
            'td', 'th', 'tableCell', 'tableHeader' => ' ',
            'li', 'listItem', 'tr', 'tableRow' => "\n",
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'ul', 'ol', 'paragraph', 'heading', 'bulletList', 'orderedList', 'codeBlock', 'table', 'hr', 'horizontalRule' => "\n\n",
            default => '',
        };
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */u', "\n", $text) ?? '';

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? '');
    }

    private function assertBounded(int $count, int $depth): void
    {
        if ($count > 10000 || $depth > 100) {
            throw ValidationException::withMessages(['content' => __('sharing.validation.snapshot_size')]);
        }
    }
}
