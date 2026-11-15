<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Trims text to a limit at the last sentence end, or the last word boundary
 * when there is no sentence end.
 */
final class Trimmer
{
    public function __construct(private readonly Length $length)
    {
    }

    public function trim(string $text, int $limit): string
    {
        if ($this->length->count($text) <= $limit) {
            return $text;
        }
        $prefix = $this->length->prefix($text, $limit);
        $rest = substr($text, strlen($prefix));
        $restStartsWithSpace = $rest !== '' && preg_match('/^\s/u', $rest) === 1;
        $probe = $prefix . ($restStartsWithSpace ? ' ' : '');
        // Sentence ends: . ! ? and the danda family, optionally followed by
        // closing quotes or brackets, then whitespace or the cut.
        $pattern = '/[.!?\x{0964}\x{0965}\x{3002}\x{061F}\x{06D4}][\'"\x{2019}\x{201D})\]]*(?=\s)/u';
        if (preg_match_all($pattern, $probe, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = $m[0][count($m[0]) - 1];
            $cut = substr($prefix, 0, $last[1] + strlen($last[0]));
            if (trim($cut) !== '') {
                return rtrim($cut);
            }
        }
        // The text after the prefix tells whether the prefix ends inside a word.
        if ($restStartsWithSpace) {
            return rtrim($prefix);
        }
        $space = preg_match('/^(.*)\s\S*$/su', $prefix, $w);
        if ($space === 1 && trim($w[1]) !== '') {
            return rtrim($w[1]);
        }

        return $prefix;
    }
}
