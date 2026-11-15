<?php

/**
 * Loads headline-options without Composer: one require of this file.
 *
 * Uses vendor/autoload.php when present (Composer install or a release zip).
 * Otherwise registers a PSR-4 autoloader for this plugin and, when found,
 * loads llm-json-php from lib/llm-json-php/bootstrap.php. Without that
 * library, scoring works and generation stays disabled with a notice.
 */

declare(strict_types=1);

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\HeadlineOptions\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    });
    if (is_readable(__DIR__ . '/lib/llm-json-php/bootstrap.php')) {
        require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';
    }
}

require_once __DIR__ . '/src/functions.php';
