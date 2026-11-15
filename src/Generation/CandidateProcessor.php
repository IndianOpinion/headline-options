<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Scorer;
use IndianOpinion\HeadlineOptions\Text\Cleaner;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * Cases 17 to 25: clean, drop, de-duplicate, keep N, score and sort.
 */
final class CandidateProcessor
{
    public const HARD_CAP = 200;
    public const ANGLE_CAP = 60;

    public function __construct(
        private readonly Cleaner $cleaner,
        private readonly Scorer $scorer,
        private readonly int $hardCap = self::HARD_CAP,
    ) {
    }

    /**
     * @param array<string, mixed> $data the schema-valid model output
     * @return array{candidates: list<Candidate>, notes: list<array{code: string, message: string}>, returned: int}
     */
    public function process(array $data, Article $article, string $current, int $count): array
    {
        $raw = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        $length = $this->scorer->length();
        $currentKey = Utf8::comparisonKey(Scorer::prepare($current));
        $seen = [];
        $kept = [];
        $notes = [];
        $repeatedCurrent = false;
        $tooLong = 0;
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['headline'] ?? null)) {
                continue;
            }
            $text = $this->cleaner->clean($item['headline']);
            if ($text === '') {
                continue; // Case 18.
            }
            if ($length->count($text) > $this->hardCap) {
                ++$tooLong; // Case 21: dropped, never truncated.
                continue;
            }
            $key = Utf8::comparisonKey($text);
            if ($key === '') {
                continue;
            }
            if ($currentKey !== '' && $key === $currentKey) {
                $repeatedCurrent = true; // Case 20.
                continue;
            }
            if (isset($seen[$key])) {
                continue; // Case 19: the first is kept.
            }
            $seen[$key] = true;
            $angle = is_string($item['angle'] ?? null) ? $this->cleaner->clean($item['angle']) : '';
            $angle = $length->prefix($angle, self::ANGLE_CAP);
            $kept[] = ['text' => $text, 'angle' => $angle];
            if (count($kept) === $count) {
                break; // Case 22: the first N after cleaning.
            }
        }
        if ($repeatedCurrent) {
            $notes[] = ['code' => 'repeated_current', 'message' => 'The model repeated the current headline; it was left out.'];
        }
        if ($tooLong > 0) {
            $notes[] = ['code' => 'too_long', 'message' => sprintf('%d candidate(s) over %d characters were left out.', $tooLong, $this->hardCap)];
        }
        $returned = count($kept);
        if ($returned > 0 && $returned < $count) {
            $notes[] = ['code' => 'fewer', 'message' => sprintf('The model returned %d of %d.', $returned, $count)];
        }

        $candidates = [];
        foreach ($kept as $i => $k) {
            $candidates[] = new Candidate($k['text'], $k['angle'], $this->scorer->score($k['text'], $article, $current), $i);
        }

        return ['candidates' => self::sort($candidates), 'notes' => $notes, 'returned' => $returned];
    }

    /**
     * Highest score first; null scores last; ties keep the model's order.
     *
     * @param list<Candidate> $candidates
     * @return list<Candidate>
     */
    public static function sort(array $candidates): array
    {
        usort($candidates, static function (Candidate $a, Candidate $b): int {
            $sa = $a->score->total ?? -1;
            $sb = $b->score->total ?? -1;

            return $sb <=> $sa ?: $a->modelOrder <=> $b->modelOrder;
        });

        return $candidates;
    }
}
