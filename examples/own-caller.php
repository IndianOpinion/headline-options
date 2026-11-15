<?php

/**
 * Plugin Name: Headline options, own model caller (example)
 * Description: Runs headline-options without llm-json-php by supplying a caller through the headline_options_caller filter.
 *
 * Copy into wp-content/plugins/ to try it. Replace the body of call() with your own client.
 */

declare(strict_types=1);

use IndianOpinion\HeadlineOptions\Exception\ProviderException;
use IndianOpinion\HeadlineOptions\Generation\CallResult;
use IndianOpinion\HeadlineOptions\Generation\ModelCaller;
use IndianOpinion\HeadlineOptions\Generation\Prompt;

if (!defined('ABSPATH')) {
    exit;
}

add_filter('headline_options_caller', static function ($caller) {
    if (!interface_exists(ModelCaller::class)) {
        return $caller;
    }

    return new class () implements ModelCaller {
        public function describe(): array
        {
            return ['provider' => 'my-gateway', 'model' => 'my-model'];
        }

        public function call(Prompt $prompt, array $schema, float $deadlineSeconds): CallResult
        {
            $response = wp_remote_post('https://gateway.example/v1/json', [
                'timeout' => $deadlineSeconds,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => (string) wp_json_encode(['system' => $prompt->system, 'user' => $prompt->user, 'schema' => $schema]),
            ]);
            if (is_wp_error($response)) {
                throw new ProviderException('Gateway unreachable.', ProviderException::FAILED, 'transport', false);
            }
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($data) || !is_array($data['candidates'] ?? null)) {
                // Validate against the schema yourself: the plugin trusts a caller's data shape.
                throw new ProviderException('Gateway output did not match the schema.', ProviderException::FAILED, 'invalid_output', true, true);
            }

            return new CallResult($data, 'my-gateway', 'my-model');
        }
    };
});
