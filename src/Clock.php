<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions;

/**
 * The current time in the site's timezone. Replaceable in tests.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
