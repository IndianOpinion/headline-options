<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Generation\PromptBuilder;

/**
 * Wires the plugin into WordPress. Registers no hook that can call a model:
 * generation runs only from the REST route or the CLI command (case 8).
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(string $pluginFile): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('rest_api_init', static function (): void {
            (new RestController())->register();
        });
        (new Editor($pluginFile))->register();
        if (is_admin()) {
            (new Admin())->register();
        }
        add_action('init', [self::class, 'registerPromptTemplate'], 20);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('headline-options', Cli::class);
        }
    }

    /** Registers the default template with prompt-library when it is active. */
    public static function registerPromptTemplate(): void
    {
        if (function_exists('promptlib_register_default')) {
            promptlib_register_default(PromptBuilder::CONSUMER, PromptBuilder::definition());
        }
        if (function_exists('promptlib_register_consumer')) {
            promptlib_register_consumer(PromptBuilder::TEMPLATE_SLUG, PromptBuilder::CONSUMER, ['count', 'language', 'max_length', 'current_title', 'excerpt', 'text', 'style_note']);
        }
    }

    /** Test helper: allow boot() again. */
    public static function reset(): void
    {
        self::$booted = false;
    }
}
