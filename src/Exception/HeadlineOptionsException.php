<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * Base class for every exception this plugin throws.
 *
 * Each subclass carries a stable error code that matches the REST error code
 * and an HTTP status for the REST layer.
 */
abstract class HeadlineOptionsException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data Extra fields for the REST error body. Never article or headline text.
     */
    public function __construct(string $message, array $data = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->data = $data;
    }

    /** The stable error code, for example `headline_options_disabled`. */
    abstract public function code(): string;

    /** The HTTP status the REST layer returns. */
    abstract public function httpStatus(): int;

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }
}
