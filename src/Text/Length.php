<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Counts text length in what a reader sees.
 *
 * With ext-intl the unit is the grapheme cluster. Without it the unit is the
 * code point, not counting combining marks (General_Category M) or format
 * characters (Cf). Both give the same answer for Latin text without marks.
 */
final class Length
{
    public const GRAPHEME = 'grapheme';
    public const CODEPOINT = 'codepoint';

    private readonly bool $intl;

    /**
     * @param bool|null $useIntl Null detects ext-intl. False forces the fallback (for tests and parity checks).
     */
    public function __construct(?bool $useIntl = null)
    {
        $available = function_exists('grapheme_strlen');
        $this->intl = $useIntl === null ? $available : ($useIntl && $available);
    }

    public function unit(): string
    {
        return $this->intl ? self::GRAPHEME : self::CODEPOINT;
    }

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if ($this->intl) {
            $n = grapheme_strlen($text);
            if (is_int($n)) {
                return $n;
            }
        }
        $stripped = preg_replace('/[\p{M}\p{Cf}]/u', '', $text);

        return mb_strlen($stripped ?? $text, 'UTF-8');
    }

    /**
     * The longest prefix of $text that is at most $units long.
     */
    public function prefix(string $text, int $units): string
    {
        if ($units <= 0) {
            return '';
        }
        if ($this->count($text) <= $units) {
            return $text;
        }
        if ($this->intl) {
            $cut = grapheme_substr($text, 0, $units);
            if (is_string($cut)) {
                return $cut;
            }
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return '';
        }
        $out = '';
        $seen = 0;
        foreach ($chars as $char) {
            $isMark = preg_match('/^[\p{M}\p{Cf}]$/u', $char) === 1;
            if (!$isMark) {
                if ($seen === $units) {
                    break;
                }
                ++$seen;
            }
            $out .= $char;
        }

        return $out;
    }
}
