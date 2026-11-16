<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 45: emoji, pictographs and decorative symbols (General_Category So,
 * except the degree sign; currency signs are Sc and pass) fail, as do styled
 * "mathematical" letters.
 */
final class EmojiRule extends AbstractRule
{
    public function id(): string
    {
        return 'emoji';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        if (preg_match('/[\x{1D400}-\x{1D7FF}]/u', $headline->text) === 1) {
            return $this->result(Status::Fail, 'Styled letters (mathematical bold or italic).');
        }
        if (preg_match('/(?!\x{00B0})[\p{So}\x{1F1E6}-\x{1F1FF}]/u', $headline->text, $m) === 1) {
            return $this->result(Status::Fail, 'Emoji or decorative symbol.', ['symbol' => sprintf('U+%04X', (int) mb_ord($m[0], 'UTF-8'))]);
        }

        return $this->result(Status::Pass, 'No emoji or decoration.');
    }
}
