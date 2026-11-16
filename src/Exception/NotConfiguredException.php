<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * No model provider, model or caller is set up, or the model library is missing.
 */
final class NotConfiguredException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_not_configured';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
