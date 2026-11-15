<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * A daily cap or the per-minute rate limit refused the generation.
 *
 * The scope is `site`, `user` or `rate`. No model call was made.
 */
final class CapReachedException extends HeadlineOptionsException
{
    public const SITE = 'site';
    public const USER = 'user';
    public const RATE = 'rate';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $message,
        private readonly string $scope,
        private readonly int $count,
        private readonly int $cap,
        private readonly ?int $retryAfter = null,
        array $data = [],
    ) {
        parent::__construct($message, $data);
    }

    public function code(): string
    {
        return match ($this->scope) {
            self::SITE => 'headline_options_daily_cap',
            self::USER => 'headline_options_user_cap',
            default => 'headline_options_rate_limited',
        };
    }

    public function httpStatus(): int
    {
        return 429;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function cap(): int
    {
        return $this->cap;
    }

    /** Seconds until a retry can pass, for the `Retry-After` header. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
