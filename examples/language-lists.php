<?php

/**
 * Plugin Name: Headline options, extra language lists (example)
 * Description: Adds clickbait phrases, number words and known names for another language through the plugin's filters.
 *
 * The phrases below are placeholders to show the shape. Write your own list for your newsroom.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Keyed by primary language subtag: "fr" covers fr_FR and fr_CA.
add_filter('headline_options_clickbait_terms', static function (array $lists): array {
    $lists['fr'] = ['vous ne croirez jamais', 'incroyable', 'ce qui se passe ensuite'];

    return $lists;
});

add_filter('headline_options_number_words', static function (array $lists): array {
    $lists['fr'] = ['deux' => '2', 'trois' => '3', 'dix' => '10', 'cent' => '100', 'mille' => '1000'];

    return $lists;
});

// Names the entity rule should look for in scripts without case, or anywhere.
add_filter('headline_options_known_entities', static function (array $names, string $language): array {
    return array_merge($names, ['Banque de France']);
}, 10, 2);
