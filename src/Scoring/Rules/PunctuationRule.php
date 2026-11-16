<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 44: `!!`, `?!`, `!?`, three or more dots, an ellipsis, or a trailing
 * `!`. A trailing `?` alone belongs to the withheld-subject rule.
 */
final class PunctuationRule extends AbstractRule
{
    public function id(): string
    {
        return 'punctuation';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $text = trim($headline->text);
        $found = [];
        $checks = [
            '!!' => '/!!/u',
            '?!' => '/\?!/u',
            '!?' => '/!\?/u',
            '...' => '/\.{3,}/u',
            "\u{2026}" => '/\x{2026}/u',
        ];
        foreach ($checks as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $label;
            }
        }
        if ($found === [] && preg_match('/!$/u', $text) === 1) {
            $found[] = 'trailing !';
        }
        if ($found === []) {
            return $this->result(Status::Pass, 'Plain punctuation.');
        }

        return $this->result(Status::Warn, 'Loud punctuation: ' . implode(', ', $found) . '.', ['found' => $found]);
    }
}
