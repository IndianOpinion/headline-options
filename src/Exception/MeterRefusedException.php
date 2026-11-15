<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The site-wide spend meter denied the call. No model call was made.
 */
final class MeterRefusedException extends HeadlineOptionsException
{
    public function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message, ['reason' => $reason]);
    }

    public function code(): string
    {
        return 'headline_options_meter_refused';
    }

    public function httpStatus(): int
    {
        return 429;
    }

    /** The meter's own reason code, for example `cap_site_day`. */
    public function reason(): string
    {
        return $this->reason;
    }
}
