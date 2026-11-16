<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Exception\NotConfiguredException;
use IndianOpinion\HeadlineOptions\Exception\ProviderException;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\ContentFilterException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\GuardRejectedException;
use IndianOpinion\LlmJson\Exception\MalformedOutputException;
use IndianOpinion\LlmJson\Exception\RefusalException;
use IndianOpinion\LlmJson\Exception\SchemaViolationException;
use IndianOpinion\LlmJson\Exception\TruncatedOutputException;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;

/**
 * The default caller, through indianopinion/llm-json-php. Schema validation,
 * retries and provider fallback are that library's job.
 */
final class LlmJsonCaller implements ModelCaller
{
    public const CLIENT_CLASS = 'IndianOpinion\\LlmJson\\Client';
    public const PURPOSE = 'headline-options.generate';

    public function __construct(private readonly Client $client, private readonly Chain $chain)
    {
    }

    public static function available(): bool
    {
        return class_exists(self::CLIENT_CLASS);
    }

    /**
     * Build a chain from settings.
     *
     * @param list<string> $models
     */
    public static function chainFromSettings(string $provider, string $key, array $models, string $baseUrl = ''): Chain
    {
        if ($models === []) {
            throw new NotConfiguredException('No model is chosen.');
        }
        try {
            $p = match ($provider) {
                'openai' => Provider::openAi($key),
                'compatible' => Provider::generic('compatible', $baseUrl, $key, $key === ''),
                default => Provider::openRouter($key, null, 'headline-options'),
            };
            $routes = array_map(static fn (string $m): Route => Route::to($p, $m), $models);

            return Chain::of(...$routes);
        } catch (\Throwable $e) {
            throw new NotConfiguredException('The provider settings are not valid.', [], $e);
        }
    }

    public function describe(): array
    {
        $routes = $this->chain->routes();
        if ($routes === []) {
            return ['provider' => '', 'model' => ''];
        }

        return ['provider' => $routes[0]->provider()->name(), 'model' => $routes[0]->model()];
    }

    public function call(Prompt $prompt, array $schema, float $deadlineSeconds): CallResult
    {
        try {
            $request = Request::for(self::PURPOSE, Schema::fromArray(OutputSchema::NAME, $schema))
                ->withChain($this->chain)
                ->withSystem($prompt->system)
                ->withInput($prompt->user)
                ->withTokenCap(1500)
                ->withDeadline(max(1.0, $deadlineSeconds));
        } catch (\Throwable $e) {
            throw new ProviderException('The request could not be built.', ProviderException::FAILED, 'configuration', false, false, $e);
        }

        try {
            $result = $this->client->complete($request);
        } catch (CreditExhaustedException $e) {
            throw new ProviderException('The provider is out of credit.', ProviderException::CREDIT, 'credit', $e->attempts() !== [], self::billed($e), $e);
        } catch (ChainExhaustedException $e) {
            throw self::fromChain($e);
        } catch (ConfigurationException $e) {
            throw new ProviderException('The provider settings are not valid.', ProviderException::FAILED, 'configuration', false, false, $e);
        } catch (\Throwable $e) {
            throw new ProviderException('The model call failed.', ProviderException::FAILED, 'failures', true, false, $e);
        }

        $usage = $result->totalUsage();

        return new CallResult(
            $result->data(),
            $result->provider(),
            $result->reportedModel() ?? $result->model(),
            (int) ($usage->promptTokens ?? 0),
            (int) ($usage->completionTokens ?? 0),
            $usage->cost,
            200,
        );
    }

    private static function fromChain(ChainExhaustedException $e): ProviderException
    {
        $attempted = $e->attempts() !== [];
        $billed = self::billed($e);
        $last = $e->lastError();
        if ($e->allRefused() || $last instanceof RefusalException || $last instanceof ContentFilterException) {
            return new ProviderException('The provider declined this article.', ProviderException::REFUSED, 'refused', $attempted, $billed, $e);
        }
        if (
            $last instanceof SchemaViolationException || $last instanceof MalformedOutputException
            || $last instanceof TruncatedOutputException || $last instanceof GuardRejectedException
        ) {
            return new ProviderException('The model output failed the schema.', ProviderException::FAILED, 'invalid_output', $attempted, $billed, $e);
        }
        if ($e->isDeadline()) {
            return new ProviderException('The provider did not answer in time.', ProviderException::FAILED, 'timeout', $attempted, $billed, $e);
        }

        return new ProviderException('Every model route failed.', ProviderException::FAILED, 'failures', $attempted, $billed, $e);
    }

    private static function billed(ChainExhaustedException $e): bool
    {
        foreach ($e->attempts() as $attempt) {
            if ($attempt->httpStatus === 200) {
                return true;
            }
        }

        return false;
    }
}
