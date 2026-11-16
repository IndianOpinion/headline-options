<?php

/**
 * Runs when the plugin is deleted from the Plugins screen. Keeps data when
 * HEADLINE_OPTIONS_KEEP_DATA is true.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\HeadlineOptions\WordPress\Uninstaller::run();
