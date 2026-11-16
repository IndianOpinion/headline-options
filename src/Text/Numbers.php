<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Finds numbers in text and turns them into comparable digit strings.
 *
 * Native digits are mapped to ASCII first. Grouping separators are removed
 * only in three patterns: Western (`1,200,000`), Indian (`12,00,000`) and
 * thin or narrow no-break space (`1 200`). Everything else is compared as
 * written, so a decimal comma (`3,5`) stays `3,5` and never becomes `35`.
 * Numbers are never parsed as floats.
 */
final class Numbers
{
    private const PATTERN = '/(?<![\d])(?:'
        . '(?<indian>\d{1,2}(?:,\d{2})+,\d{3})'
        . '|(?<western>\d{1,3}(?:,\d{3})+)'
        . '|(?<spaced>\d{1,3}(?:[\x{2009}\x{202F}]\d{3})+)'
        . '|(?<plain>\d+)'
        . ')(?<decimal>[.,]\d+)?(?![\d])/u';

    /**
     * Digit tokens found in the text, in order, normalised.
     *
     * @return list<string>
     */
    public static function digitTokens(string $text): array
    {
        $text = Utf8::asciiDigits($text);
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $integer = '';
            foreach (['indian', 'western', 'spaced', 'plain'] as $group) {
                if (isset($m[$group]) && $m[$group] !== '') {
                    $integer = $m[$group];
                    break;
                }
            }
            $integer = str_replace([',', "\u{2009}", "\u{202F}"], '', $integer);
            $out[] = $integer . ($m['decimal'] ?? '');
        }

        return $out;
    }

    /**
     * Number words found in the text, as the lower-case words.
     *
     * @param array<string, string> $words word => digit value
     * @return list<string>
     */
    public static function wordTokens(string $text, array $words): array
    {
        if ($words === []) {
            return [];
        }
        $out = [];
        foreach (Utf8::words(Utf8::fold($text)) as $word) {
            if (isset($words[$word])) {
                $out[] = $word;
            }
        }

        return $out;
    }

    /** True for a four-digit year from 1800 to 2100. */
    public static function isYear(string $token): bool
    {
        return preg_match('/^\d{4}$/', $token) === 1 && (int) $token >= 1800 && (int) $token <= 2100;
    }
}
