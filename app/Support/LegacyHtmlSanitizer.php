<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Small allow-list sanitizer for HTML entered through the new CMS.
 *
 * Existing legacy content is left untouched unless its `content` field is
 * actually submitted to an update endpoint. New or changed content never
 * accepts scripts, event handlers, inline styles, or unsafe URI schemes.
 */
final class LegacyHtmlSanitizer
{
    /** @var array<string,list<string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel', 'class'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'class'],
        'table' => ['class'],
        'thead' => ['class'],
        'tbody' => ['class'],
        'tr' => ['class'],
        'th' => ['colspan', 'rowspan', 'scope', 'class'],
        'td' => ['colspan', 'rowspan', 'class'],
        'div' => ['class'],
        'span' => ['class'],
        'p' => ['class'],
        'h2' => ['class'],
        'h3' => ['class'],
        'h4' => ['class'],
        'h5' => ['class'],
        'h6' => ['class'],
        'ul' => ['class'],
        'ol' => ['class'],
        'li' => ['class'],
        'blockquote' => ['class'],
        'pre' => ['class'],
        'code' => ['class'],
        'figure' => ['class'],
        'figcaption' => ['class'],
    ];

    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'a', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
        'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'figure', 'figcaption', 'img', 'hr', 'div', 'span',
    ];

    /** @var list<string> */
    private const REMOVE_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'base', 'meta', 'link'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><div data-lppm-root="1">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );

            $root = (new DOMXPath($document))->query('//*[@data-lppm-root="1"]')->item(0);
            if (!$root instanceof DOMElement) {
                return '';
            }

            $this->sanitizeChildren($root);

            $result = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $result .= $document->saveHTML($child);
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, self::REMOVE_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    continue;
                }

                // Unknown formatting tags are unwrapped so their safe text and
                // child nodes are retained rather than silently discarded.
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeAttributes($node, $tag);
            $this->sanitizeChildren($node);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);
                continue;
            }

            if (in_array($name, ['href', 'src'], true) && !$this->isSafeUrl($attribute->value)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tag === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
            $existing = preg_split('/\s+/', trim($element->getAttribute('rel'))) ?: [];
            $element->setAttribute('rel', implode(' ', array_unique([...$existing, 'noopener', 'noreferrer'])));
        }
    }

    private function isSafeUrl(string $url): bool
    {
        $value = trim($url);
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, '/') || str_starts_with($value, './') || str_starts_with($value, '../')) {
            return true;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true);
    }
}
