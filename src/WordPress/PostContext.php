<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Exception\ForbiddenException;
use IndianOpinion\HeadlineOptions\Exception\NotFoundException;
use IndianOpinion\HeadlineOptions\Hooks;

/**
 * Post lookups and permission checks shared by REST, CLI and the editor.
 */
final class PostContext
{
    /** @return list<string> */
    public static function postTypes(?\IndianOpinion\HeadlineOptions\Settings $settings = null): array
    {
        $settings ??= Services::instance()->settings();
        $types = Hooks::filter('headline_options_post_types', $settings->list('post_types'));
        $out = [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_string($type) && post_type_supports($type, 'title') && !in_array($type, $out, true)) {
                $out[] = $type;
            }
        }

        return $out;
    }

    public static function capability(): string
    {
        $cap = Hooks::filter('headline_options_capability', 'edit_posts');

        return is_string($cap) && $cap !== '' ? $cap : 'edit_posts';
    }

    public static function manageCapability(): string
    {
        $cap = Hooks::filter('headline_options_manage_capability', 'manage_options');

        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /**
     * Resolve an id to the post being edited: autosaves and revisions map to
     * their parent; missing or trashed posts are 404.
     */
    public static function resolve(int $postId): \WP_Post
    {
        if ($postId <= 0) {
            throw new NotFoundException('No such post.');
        }
        $parent = wp_is_post_autosave($postId) ?: wp_is_post_revision($postId);
        if (is_int($parent) && $parent > 0) {
            $postId = $parent;
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status === 'trash') {
            throw new NotFoundException('No such post.');
        }

        return $post;
    }

    public static function assertCanUse(\WP_Post $post): void
    {
        if (!current_user_can(self::capability()) || !current_user_can('edit_post', $post->ID)) {
            throw new ForbiddenException('You cannot edit this post.');
        }
        if (!in_array($post->post_type, self::postTypes(), true)) {
            throw new ForbiddenException('Headline options is not enabled for this post type.');
        }
    }

    /** @return list<string> */
    public static function termNames(int $postId, string $taxonomy): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }
        $names = wp_get_post_terms($postId, $taxonomy, ['fields' => 'names']);
        if (!is_array($names)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($n): string => is_string($n) ? html_entity_decode($n, ENT_QUOTES, 'UTF-8') : '', $names), static fn (string $n): bool => $n !== ''));
    }

    public static function language(int $postId): string
    {
        $locale = function_exists('get_locale') ? get_locale() : 'en_US';
        $lang = Hooks::filter('headline_options_post_language', $locale, $postId);

        return is_string($lang) && $lang !== '' ? $lang : $locale;
    }
}
