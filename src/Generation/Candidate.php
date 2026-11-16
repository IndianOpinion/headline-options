<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;

/**
 * One cleaned candidate with its score.
 */
final class Candidate
{
    public function __construct(
        public readonly string $text,
        public readonly string $angle,
        public readonly ScoreResult $score,
        /** Position in the model's answer, from 0 (for stable tie order). */
        public readonly int $modelOrder,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'angle' => $this->angle,
            'model_order' => $this->modelOrder,
            'score' => $this->score->toArray(),
        ];
    }
}
