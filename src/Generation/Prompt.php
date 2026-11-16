<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * The rendered prompt: a system part and a user part.
 */
final class Prompt
{
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly string $source = 'built-in',
    ) {
    }

    /** sha256 of the prompt text. The text itself is never stored. */
    public function hash(): string
    {
        return hash('sha256', $this->system . "\n\n" . $this->user);
    }

    /** Rough input token estimate: characters divided by four, rounded up. */
    public function estimateTokens(): int
    {
        return (int) ceil(mb_strlen($this->system . $this->user, 'UTF-8') / 4);
    }
}
