<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests;

use IndianOpinion\HeadlineOptions\Clock;

final class FixedClock implements Clock
{
    public \DateTimeImmutable $now;

    public function __construct(string $time = '2026-11-15 10:00:00')
    {
        $this->now = new \DateTimeImmutable($time, new \DateTimeZone('Asia/Kolkata'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }
}
