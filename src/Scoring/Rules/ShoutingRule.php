<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 43: capitals used for shouting. Acronyms that also appear in capitals
 * in the article are exempt.
 */
final class ShoutingRule extends AbstractRule
{
    public function id(): string
    {
        return 'shouting';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $upper = preg_match_all('/[\p{Lu}\p{Lt}]/u', $headline->text);
        $lower = preg_match_all('/\p{Ll}/u', $headline->text);
        $upper = is_int($upper) ? $upper : 0;
        $lower = is_int($lower) ? $lower : 0;
        if ($upper + $lower === 0) {
            return $this->na('No letters with case.');
        }
        $shouted = [];
        foreach ($headline->words as $word) {
            if (preg_match('/^\p{Lu}{4,}$/u', $word) === 1 && !$article->containsExact($word)) {
                $shouted[] = $word;
            }
        }
        if ($upper * 2 > $upper + $lower && ($upper + $lower) >= 8) {
            return $this->result(Status::Fail, 'More than half the letters are capitals.', ['shouted' => $shouted]);
        }
        if (count($shouted) >= 2) {
            return $this->result(Status::Warn, 'Words in capitals: ' . implode(', ', $shouted) . '.', ['shouted' => $shouted]);
        }

        return $this->result(Status::Pass, 'No shouting.');
    }
}
