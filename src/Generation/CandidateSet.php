<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;

/**
 * One generation: the candidates, sorted by score, and how they were made.
 * Stores hashes, never the article or prompt text.
 */
final class CandidateSet
{
    /**
     * @param list<Candidate>                          $candidates sorted by score, ties in model order
     * @param list<array{code: string, message: string}> $notes
     */
    public function __construct(
        public readonly string $id,
        public readonly int $postId,
        public readonly string $createdAt,
        public readonly int $userId,
        public readonly string $contentHash,
        public readonly string $language,
        public readonly string $model,
        public readonly string $promptHash,
        public readonly array $candidates,
        public readonly int $requested,
        public readonly int $returned,
        public readonly array $notes,
        public readonly ?ScoreResult $current = null,
    ) {
    }

    /**
     * The set object of PRD 5.4, in snake case.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->postId,
            'created_at' => $this->createdAt,
            'user_id' => $this->userId,
            'content_hash' => $this->contentHash,
            'language' => $this->language,
            'model' => $this->model,
            'prompt_hash' => $this->promptHash,
            'current' => $this->current?->toArray(),
            'candidates' => array_map(static fn (Candidate $c): array => $c->toArray(), $this->candidates),
            'requested' => $this->requested,
            'returned' => $this->returned,
            'notes' => $this->notes,
            'chosen_index' => null,
            'chosen_by' => null,
            'chosen_at' => null,
        ];
    }
}
