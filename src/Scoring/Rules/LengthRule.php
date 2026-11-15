<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 36: length in graphemes (or code points less marks without ext-intl).
 */
final class LengthRule extends AbstractRule
{
    /**
     * @param array{pass: array{0: int, 1: int}, warn: array{0: int, 1: int}} $bounds
     */
    public function __construct(int $weight, private readonly array $bounds = ['pass' => [30, 70], 'warn' => [20, 90]])
    {
        parent::__construct($weight);
    }

    public function id(): string
    {
        return 'length';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $n = $headline->length;
        [$passLow, $passHigh] = $this->bounds['pass'];
        [$warnLow, $warnHigh] = $this->bounds['warn'];
        $unit = $headline->lengthUnit === 'grapheme' ? 'characters' : 'characters (code points less marks)';
        $data = ['length' => $n, 'unit' => $headline->lengthUnit, 'words' => count($headline->words)];
        if ($n >= $passLow && $n <= $passHigh) {
            return $this->result(Status::Pass, sprintf('%d %s, inside %d to %d.', $n, $unit, $passLow, $passHigh), $data);
        }
        if ($n >= $warnLow && $n <= $warnHigh) {
            $side = $n < $passLow ? 'short' : 'long';

            return $this->result(Status::Warn, sprintf('%d %s: a little %s (aim for %d to %d).', $n, $unit, $side, $passLow, $passHigh), $data);
        }
        $side = $n < $warnLow ? 'short' : 'long';

        return $this->result(Status::Fail, sprintf('%d %s: too %s (aim for %d to %d).', $n, $unit, $side, $passLow, $passHigh), $data);
    }
}
