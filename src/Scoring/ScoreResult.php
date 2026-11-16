<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

/**
 * The score of one headline: total, band, length and every rule result.
 */
final class ScoreResult
{
    /**
     * @param list<RuleResult> $results
     */
    public function __construct(
        public readonly string $headline,
        public readonly ?int $total,
        public readonly ?string $band,
        public readonly int $length,
        public readonly string $lengthUnit,
        public readonly int $words,
        public readonly array $results,
        public readonly ?float $closeness,
    ) {
    }

    /**
     * The score object of PRD 5.4.
     *
     * @return array{
     *     headline: string,
     *     total: int|null,
     *     band: string|null,
     *     length: int,
     *     length_unit: string,
     *     words: int,
     *     closeness: float|null,
     *     rules: list<array{id: string, status: string, earned: int, possible: int, reason: string, data: array<string, scalar|list<scalar>|null>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline,
            'total' => $this->total,
            'band' => $this->band,
            'length' => $this->length,
            'length_unit' => $this->lengthUnit,
            'words' => $this->words,
            'closeness' => $this->closeness,
            'rules' => array_map(static fn (RuleResult $r): array => $r->toArray(), $this->results),
        ];
    }
}
