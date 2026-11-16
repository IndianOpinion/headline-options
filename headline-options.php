<?php

/**
 * Plugin Name:       Headline options
 * Plugin URI:        https://github.com/IndianOpinion/headline-options
 * Description:       Asks a model for several headline candidates on a click, scores them with plain rules (length, clickbait words, numerals, named entities), and shows them in the editor. Never replaces a headline on its own.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       headline-options
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HEADLINE_OPTIONS_VERSION', '0.1.0');
define('HEADLINE_OPTIONS_FILE', __FILE__);

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\HeadlineOptions\WordPress\Plugin::boot(__FILE__);
