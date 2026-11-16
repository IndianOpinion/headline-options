<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Exception\ProviderException;

/**
 * Makes the one model call of a generation.
 *
 * The default implementation uses llm-json-php. A site can return its own
 * implementation from the `headline_options_caller` filter and run without
 * that library.
 */
interface ModelCaller
{
    /**
     * Provider and model of the first route, for the spend meter.
     *
     * @return array{provider: string, model: string}
     */
    public function describe(): array;

    /**
     * @param array<string, mixed> $schema the output schema of PRD 5.6
     * @return CallResult data that already matched the schema
     *
     * @throws ProviderException on any failure; `attempted()` says whether a request reached the provider
     */
    public function call(Prompt $prompt, array $schema, float $deadlineSeconds): CallResult;
}
