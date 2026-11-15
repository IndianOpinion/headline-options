<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

/**
 * What one rule said about one headline.
 */
final class RuleResult
{
    /**
     * @param array<string, scalar|list<scalar>|null> $data For example the matched phrase. Never the article text.
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly Status $status,
        public readonly int $earned,
        public readonly int $possible,
        public readonly string $reason,
        public readonly array $data = [],
    ) {
    }

    /**
     * Points for a status: pass earns all, warn half (rounded down), fail and na nothing.
     *
     * @param array<string, scalar|list<scalar>|null> $data
     */
    public static function of(string $ruleId, Status $status, int $weight, string $reason, array $data = []): self
    {
        $weight = max(0, $weight);
        $earned = match ($status) {
            Status::Pass => $weight,
            Status::Warn => intdiv($weight, 2),
            Status::Fail, Status::Na => 0,
        };

        return new self($ruleId, $status, $earned, $status === Status::Na ? 0 : $weight, $reason, $data);
    }

    public static function na(string $ruleId, string $reason): self
    {
        return new self($ruleId, Status::Na, 0, 0, $reason);
    }

    /**
     * @return array{id: string, status: string, earned: int, possible: int, reason: string, data: array<string, scalar|list<scalar>|null>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->ruleId,
            'status' => $this->status->value,
            'earned' => $this->earned,
            'possible' => $this->possible,
            'reason' => $this->reason,
            'data' => $this->data,
        ];
    }
}
