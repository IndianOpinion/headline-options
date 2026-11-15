<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

use IndianOpinion\HeadlineOptions\Text\Length;
use IndianOpinion\HeadlineOptions\Text\Numbers;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * A cleaned headline with the facts every rule needs.
 */
final class Headline
{
    public readonly int $length;
    public readonly string $lengthUnit;
    /** @var list<string> */
    public readonly array $words;
    /** @var list<string> */
    public readonly array $digitNumbers;
    /** @var list<string> */
    public readonly array $numberWords;
    public readonly bool $caseless;

    /**
     * @param array<string, string> $numberWords word => digit value, for the headline's language
     */
    public function __construct(public readonly string $text, Length $length, array $numberWords = [])
    {
        $this->length = $length->count($text);
        $this->lengthUnit = $length->unit();
        $this->words = Utf8::words($text);
        $this->digitNumbers = Numbers::digitTokens($text);
        $this->numberWords = Numbers::wordTokens($text, $numberWords);
        $this->caseless = self::isMostlyCaseless($text);
    }

    /**
     * True when most letters are in a script without upper and lower case
     * (Devanagari, Bengali, Tamil, Arabic and the like).
     */
    public static function isMostlyCaseless(string $text): bool
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters === false || $letters === 0) {
            return false;
        }
        $caseless = preg_match_all('/\p{Lo}/u', $text);

        return is_int($caseless) && $caseless * 2 > $letters;
    }

    /**
     * Entity candidates found by capitalisation and acronyms (case 41).
     *
     * @return list<string>
     */
    public function capitalisedEntities(): array
    {
        $out = [];
        $run = [];
        foreach ($this->words as $index => $word) {
            $first = mb_substr($word, 0, 1, 'UTF-8');
            $isCap = preg_match('/^[\p{Lu}\p{Lt}]$/u', $first) === 1;
            if ($isCap && $index > 0) {
                $run[] = $word;
                continue;
            }
            if ($run !== []) {
                $out[] = implode(' ', $run);
                $run = [];
            }
        }
        if ($run !== []) {
            $out[] = implode(' ', $run);
        }
        // Acronyms anywhere, the first word included.
        foreach ($this->words as $word) {
            if (preg_match('/^\p{Lu}{2,6}$/u', $word) === 1 && !in_array($word, $out, true)) {
                $isInsideRun = false;
                foreach ($out as $entity) {
                    if (in_array($word, explode(' ', $entity), true)) {
                        $isInsideRun = true;
                        break;
                    }
                }
                if (!$isInsideRun) {
                    $out[] = $word;
                }
            }
        }

        return $out;
    }

    /**
     * Latin-script words, for headlines in caseless scripts (case 42).
     *
     * @return list<string>
     */
    public function latinWords(): array
    {
        $out = [];
        foreach ($this->words as $word) {
            if (preg_match('/^[\p{Latin}\p{M}\d\'\x{2019}\-.&]+$/u', $word) === 1 && preg_match('/\p{Latin}/u', $word) === 1) {
                $out[] = $word;
            }
        }

        return $out;
    }
}
