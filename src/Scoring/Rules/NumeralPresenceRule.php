<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 39: a specific number earns a small bonus. No number is `na`, not a
 * fail: many good headlines carry none.
 */
final class NumeralPresenceRule extends AbstractRule
{
    public function id(): string
    {
        return 'numeral_presence';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $numbers = array_merge($headline->digitNumbers, $headline->numberWords);
        if ($numbers === []) {
            return $this->na('No number in the headline.');
        }

        return $this->result(Status::Pass, 'Carries a specific number: ' . implode(', ', $numbers) . '.', ['numbers' => $numbers]);
    }
}
