<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The model call failed: timeout, chain exhausted, credit, refusal or output
 * that failed the schema.
 */
final class ProviderException extends HeadlineOptionsException
{
    public const FAILED = 'headline_options_provider_failed';
    public const CREDIT = 'headline_options_provider_credit';
    public const REFUSED = 'headline_options_refused';

    /**
     * @param string $errorCode One of the class constants.
     * @param string $reason    Short machine reason, for example `invalid_output` or `timeout`.
     * @param bool   $attempted True when at least one request reached the provider.
     * @param bool   $billed    True when a response came back that may have been billed.
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = self::FAILED,
        private readonly string $reason = 'failures',
        private readonly bool $attempted = true,
        private readonly bool $billed = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, ['reason' => $reason], $previous);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function attempted(): bool
    {
        return $this->attempted;
    }

    public function billed(): bool
    {
        return $this->billed;
    }
}
