<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Turns post content into the plain text a model and the rules see.
 *
 * Blocks are reduced to their saved HTML (block comments removed), shortcodes
 * are stripped and never executed, tags are removed, entities decoded and
 * whitespace collapsed.
 */
final class PlainText
{
    /** @var (callable(string): string)|null */
    private $shortcodeStripper;

    /**
     * @param (callable(string): string)|null $shortcodeStripper Strips registered shortcodes without running them
     *                                                           (WordPress `strip_shortcodes`). Null uses a generic pattern.
     */
    public function __construct(?callable $shortcodeStripper = null)
    {
        $this->shortcodeStripper = $shortcodeStripper;
    }

    public function render(string $content): string
    {
        if ($content === '') {
            return '';
        }
        // Block delimiters and other comments.
        $text = preg_replace('/<!--.*?-->/su', ' ', $content) ?? $content;
        // Script, style and similar elements carry no prose.
        $text = preg_replace('#<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1\s*>#isu', ' ', $text) ?? $text;
        if ($this->shortcodeStripper !== null) {
            $text = ($this->shortcodeStripper)($text);
        }
        $text = self::stripShortcodeTags($text);
        // Block-level tags become spaces so words do not run together.
        $text = preg_replace('#</?(p|div|br|li|ul|ol|h[1-6]|figure|figcaption|blockquote|tr|td|th|table|section|article|header|footer|pre|hr)\b[^>]*>#iu', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = Utf8::decodeEntities($text);

        return Utf8::collapseWhitespace($text);
    }

    /**
     * Remove anything shaped like a shortcode tag: `[name ...]`, `[/name]`,
     * `[name /]`. The text between an opening and closing tag is kept.
     */
    public static function stripShortcodeTags(string $text): string
    {
        return preg_replace('/\[\[?\/?[a-zA-Z][\w-]*(?:\s[^\[\]]*)?\/?\]\]?/u', ' ', $text) ?? $text;
    }

    public static function wordCount(string $text): int
    {
        return count(Utf8::words($text));
    }
}
