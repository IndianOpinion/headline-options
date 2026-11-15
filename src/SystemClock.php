<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions;

/**
 * The real clock, in the site timezone when WordPress is loaded.
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        $zone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');

        return new \DateTimeImmutable('now', $zone);
    }
}
