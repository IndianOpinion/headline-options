<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * Generation is switched off by the setting, the constant or a cap of 0.
 */
final class DisabledException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_disabled';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
