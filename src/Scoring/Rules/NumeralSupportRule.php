<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;
use IndianOpinion\HeadlineOptions\Text\Numbers;

/**
 * Cases 40 and 83: every number in the headline must appear in the article,
 * compared as digit strings after native digits are mapped and grouping is
 * removed. A number word matches the same word or its digits.
 */
final class NumeralSupportRule extends AbstractRule
{
    /**
     * @param array<string, array<string, string>> $numberWords word => digits, keyed by language
     */
    public function __construct(int $weight, private readonly array $numberWords)
    {
        parent::__construct($weight);
    }

    public function id(): string
    {
        return 'numeral_support';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        if ($headline->digitNumbers === [] && $headline->numberWords === []) {
            return $this->na('No number to check.');
        }
        $map = $this->numberWords[$article->languageCode()] ?? [];
        $byValue = [];
        foreach ($map as $word => $value) {
            $byValue[$value][] = $word;
        }
        $missing = [];
        foreach ($headline->digitNumbers as $token) {
            if (isset($article->digitNumbers[$token])) {
                continue;
            }
            $asWord = false;
            foreach ($byValue[$token] ?? [] as $word) {
                if (isset($article->numberWords[$word])) {
                    $asWord = true;
                    break;
                }
            }
            if (!$asWord) {
                $missing[] = $token;
            }
        }
        foreach ($headline->numberWords as $word) {
            if (isset($article->numberWords[$word])) {
                continue;
            }
            $value = $map[$word] ?? null;
            if ($value !== null && isset($article->digitNumbers[$value])) {
                continue;
            }
            $missing[] = $word;
        }
        $missing = array_values(array_unique($missing));
        if ($missing === []) {
            return $this->result(Status::Pass, 'Every number appears in the article.');
        }
        $labels = array_map(static fn (string $n): string => Numbers::isYear($n) ? $n . ' (year)' : $n, $missing);

        return $this->result(Status::Fail, 'Not in the article: ' . implode(', ', $labels) . '.', ['missing' => $missing]);
    }
}
