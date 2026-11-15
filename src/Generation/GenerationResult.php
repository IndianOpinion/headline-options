<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * A finished generation: the set, whether it was saved to post meta, and
 * today's usage.
 */
final class GenerationResult
{
    /**
     * @param array{site_today: int, site_cap: int, user_today: int, user_cap: int} $usage
     */
    public function __construct(
        public readonly CandidateSet $set,
        public readonly bool $saved,
        public readonly array $usage,
    ) {
    }
}
