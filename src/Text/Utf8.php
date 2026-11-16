<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Small Unicode helpers that need no extension beyond mbstring and PCRE.
 */
final class Utf8
{
    public static function isValid(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8');
    }

    /** NFC when ext-intl is present, unchanged otherwise. */
    public static function nfc(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                return $normal;
            }
        }

        return $text;
    }

    /** Decode HTML entities, including numeric ones, to UTF-8 text. */
    public static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function collapseWhitespace(string $text): string
    {
        $out = preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text);

        return trim($out ?? $text);
    }

    /**
     * Fold curly quotes and apostrophes to straight ones.
     */
    public static function foldQuotes(string $text): string
    {
        return strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{2032}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"', "\u{2033}" => '"',
            "\u{00AB}" => '"', "\u{00BB}" => '"',
        ]);
    }

    /**
     * Map every decimal digit (General_Category Nd) in any script to ASCII.
     */
    public static function asciiDigits(string $text): string
    {
        if (preg_match('/[^\x{0}-\x{7F}]/u', $text) !== 1) {
            return $text;
        }
        $out = preg_replace_callback('/(?![0-9])\p{Nd}/u', static function (array $m): string {
            return (string) self::digitValue($m[0]);
        }, $text);

        return $out ?? $text;
    }

    /**
     * The value of one Nd digit. Unicode keeps every Nd digit in ascending
     * runs of ten that start at zero, so the value is the offset inside the
     * run when IntlChar is missing.
     */
    public static function digitValue(string $char): int
    {
        if (class_exists(\IntlChar::class)) {
            $value = \IntlChar::charDigitValue($char);
            if (is_int($value) && $value >= 0) {
                return $value;
            }
        }
        $cp = mb_ord($char, 'UTF-8');
        if ($cp === false) {
            return 0;
        }
        $start = $cp;
        while ($start > 0 && preg_match('/^\p{Nd}$/u', (string) mb_chr($start - 1, 'UTF-8')) === 1) {
            --$start;
        }

        return ($cp - $start) % 10;
    }

    /** Case folding for comparisons. */
    public static function fold(string $text): string
    {
        return mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * A key for "the same headline": case folded, punctuation removed,
     * whitespace collapsed.
     */
    public static function comparisonKey(string $text): string
    {
        $folded = self::fold(self::foldQuotes($text));
        $noPunct = preg_replace('/\p{P}+/u', ' ', $folded);

        return self::collapseWhitespace($noPunct ?? $folded);
    }

    /**
     * Words as runs of letters, marks and digits. Apostrophes and hyphens
     * inside a word are kept.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        if (preg_match_all('/[\p{L}\p{M}\p{Nd}]+(?:[\'\x{2019}\-.&][\p{L}\p{M}\p{Nd}]+)*/u', $text, $m) === false) {
            return [];
        }

        return $m[0];
    }
}
