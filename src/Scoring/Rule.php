<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

/**
 * One scoring rule. Add, remove or replace rules through the
 * `headline_options_rules` filter.
 *
 * A rule must be deterministic: the same headline and article give the same
 * result on every run. A rule that cannot judge a headline (wrong language,
 * no data) returns `Status::Na`, never a pass.
 */
interface Rule
{
    /** Stable id, for example `length`. */
    public function id(): string;

    /** Points possible when the rule applies. */
    public function weight(): int;

    public function evaluate(Headline $headline, Article $article): RuleResult;
}
