<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Cleans one model-written candidate (or angle label) before it is scored.
 *
 * Model output is untrusted text. It arrives numbered, quoted, in Markdown,
 * with HTML, and with a "Headline:" prefix. The steps run in a fixed order and
 * never invent text: they only remove.
 */
final class Cleaner
{
    /** @var list<string> */
    private array $prefixes;

    /**
     * @param list<string> $prefixes Labels removed from the start, compared case-insensitively.
     */
    public function __construct(?array $prefixes = null)
    {
        $this->prefixes = $prefixes ?? self::defaultPrefixes();
    }

    /** @return list<string> */
    public static function defaultPrefixes(): array
    {
        return ['headline', 'title', 'option', 'candidate', 'suggestion', 'alternative'];
    }

    public function clean(string $text): string
    {
        // a. Unicode NFC when ext-intl is present.
        $text = Utf8::nfc($text);
        // e. HTML tags removed, entities decoded (early, so quotes written as entities are seen).
        $text = strip_tags($text);
        $text = Utf8::decodeEntities($text);
        // g. Control and format characters, whitespace.
        $text = self::removeControls($text);
        $text = Utf8::collapseWhitespace($text);

        // b, d, f, c: list marker, Markdown, label prefix, wrapping quotes.
        // Two passes catch "1. **Headline:** "Text"" in any nesting order.
        for ($pass = 0; $pass < 2; ++$pass) {
            $text = self::removeListMarker($text);
            $text = self::removeMarkdown($text);
            $text = $this->removePrefix($text);
            $text = self::removeWrappingQuotes($text);
            $text = trim($text);
        }

        // h. One trailing full stop; ? and ! are kept; an ellipsis is kept.
        $text = self::removeTrailingStop($text);

        return Utf8::collapseWhitespace($text);
    }

    public static function removeControls(string $text): string
    {
        // Line breaks and tabs become spaces first.
        $text = preg_replace('/[\t\r\n\x{000B}\x{000C}\x{0085}\x{2028}\x{2029}]+/u', ' ', $text) ?? $text;
        // ZWJ and ZWNJ survive only between two letters or marks (Indic conjuncts need them).
        $text = preg_replace('/(?<![\p{L}\p{M}])[\x{200C}\x{200D}]|[\x{200C}\x{200D}](?![\p{L}\p{M}])/u', '', $text) ?? $text;
        // Every other control and format character goes.
        $text = preg_replace('/(?![\x{200C}\x{200D}])[\p{Cc}\p{Cf}]/u', '', $text) ?? $text;

        return $text;
    }

    public static function removeListMarker(string $text): string
    {
        return preg_replace('/^\s*(?:\d{1,2}[.)]\s+|\(\d{1,2}\)\s+|[-*#]+\s+|[\x{2022}\x{2023}\x{25E6}\x{2043}\x{2219}]\s*)/u', '', $text) ?? $text;
    }

    public static function removeMarkdown(string $text): string
    {
        $text = str_replace(['**', '__', '`'], '', $text);
        // A single pair of * or _ wrapping the whole line.
        if (preg_match('/^([*_])(.+)\1$/su', $text, $m) === 1) {
            $text = $m[2];
        }

        return $text;
    }

    public function removePrefix(string $text): string
    {
        if ($this->prefixes === []) {
            return $text;
        }
        $alternatives = implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), $this->prefixes));
        $pattern = '/^(?:' . $alternatives . ')(?:\s*\d{1,2})?\s*[:\x{FF1A}\-\x{2013}]\s*/iu';

        return preg_replace($pattern, '', $text) ?? $text;
    }

    public static function removeWrappingQuotes(string $text): string
    {
        $pairs = [
            ['"', '"'], ["'", "'"], ["\u{201C}", "\u{201D}"], ["\u{2018}", "\u{2019}"],
            ["\u{201E}", "\u{201C}"], ["\u{201A}", "\u{2018}"], ["\u{00AB}", "\u{00BB}"],
            ["\u{00BB}", "\u{00AB}"], ["\u{2039}", "\u{203A}"], ["\u{300C}", "\u{300D}"],
            ["\u{300E}", "\u{300F}"], ["\u{201D}", "\u{201D}"], ["\u{201C}", "\u{201C}"],
        ];
        foreach ($pairs as [$open, $close]) {
            $lo = strlen($open);
            $lc = strlen($close);
            if (strlen($text) >= $lo + $lc && str_starts_with($text, $open) && str_ends_with($text, $close)) {
                $inner = substr($text, $lo, -$lc);
                // Do not unwrap "A" and "B" style lines: the inner text must not hold the closing quote.
                if (!str_contains($inner, $close)) {
                    return trim($inner);
                }
            }
        }

        return $text;
    }

    public static function removeTrailingStop(string $text): string
    {
        if (!str_ends_with($text, '.') || str_ends_with($text, '..')) {
            return $text;
        }
        // Keep the stop of a dotted abbreviation such as "U.S.".
        if (preg_match('/(?:^|\s)(?:\p{Lu}\.){2,}$/u', $text) === 1) {
            return $text;
        }

        return rtrim(substr($text, 0, -1));
    }
}
