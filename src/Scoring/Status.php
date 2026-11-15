<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

/**
 * The outcome of one rule. `Na` (not applicable) adds nothing to either the
 * earned or the possible points; it never counts as a pass.
 */
enum Status: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Na = 'na';
}
