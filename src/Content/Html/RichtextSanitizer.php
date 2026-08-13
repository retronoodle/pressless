<?php

declare(strict_types=1);

namespace Stead\Content\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side allowlist HTML sanitizer for the `richtext` field type.
 *
 * The Tiptap admin editor constrains the markup a well-behaved browser can
 * send, but the server never trusts the client. Every submitted `richtext`
 * value is run through this sanitizer before persistence.
 *
 * The allowlist is intentionally narrow and matches the toolbar's tag set
 * exactly. Keep it paired with the editor's StarterKit configuration in
 * `public/assets/js/vendor/tiptap.bundle.js` — if either side is widened,
 * the other must be widened in lockstep.
 *
 * Allowed tags:    p, strong, em, h2, h3, ul, ol, li, blockquote
 * Allowed attrs:   a[href], img[src|alt]
 * URL schemes:     http, https, mailto (no `javascript:`, no `data:`)
 */
final class RichtextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'strong', 'em', 'h2', 'h3',
        'ul', 'ol', 'li', 'blockquote',
    ];

    private const ALLOWED_ATTR = [
        'a' => ['href'],
        'img' => ['src', 'alt'],
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

    private ?HTMLPurifier $purifier = null;

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        return $this->purifier()->purify($html);
    }

    /**
     * Length of the visible text content of the sanitized HTML, measured
     * with `strip_tags()` so a configured `max_length` of 500 means 500
     * characters of content, not 500 characters including `<strong>` tags.
     */
    public function plainTextLength(string $sanitizedHtml): int
    {
        $text = strip_tags($sanitizedHtml);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return mb_strlen($text);
    }

    private function purifier(): HTMLPurifier
    {
        if ($this->purifier !== null) {
            return $this->purifier;
        }
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'HTML 4.01 Strict');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', $this->allowedHtmlString());
        $config->set('HTML.SafeIframe', false);
        $config->set('URI.AllowedSchemes', self::ALLOWED_URL_SCHEMES);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('Attr.AllowedRel', []);
        $config->set('Output.CommentScriptContents', false);
        $this->purifier = new HTMLPurifier($config);
        return $this->purifier;
    }

    private function allowedHtmlString(): string
    {
        $tagList = implode(',', self::ALLOWED_TAGS);
        $attrParts = [];
        foreach (self::ALLOWED_ATTR as $tag => $attrs) {
            $attrParts[] = $tag . '[' . implode('|', $attrs) . ']';
        }
        return $tagList . ',' . implode(',', $attrParts);
    }
}
