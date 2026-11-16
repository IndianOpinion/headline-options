<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Rule;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Shared plumbing for the built-in rules.
 */
abstract class AbstractRule implements Rule
{
    public function __construct(private readonly int $weight)
    {
    }

    public function weight(): int
    {
        return $this->weight;
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $data
     */
    protected function result(Status $status, string $reason, array $data = []): RuleResult
    {
        return RuleResult::of($this->id(), $status, $this->weight, $reason, $data);
    }

    protected function na(string $reason): RuleResult
    {
        return RuleResult::na($this->id(), $reason);
    }
}
