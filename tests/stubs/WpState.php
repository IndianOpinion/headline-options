<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Stubs;

/**
 * In-memory WordPress state for the stub layer.
 */
final class WpState
{
    public static int $blog = 1;
    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<string, mixed>> */
    public static array $transients = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $postMeta = [];
    /** @var array<int, array<string, mixed>> */
    public static array $userMeta = [];
    /** @var array<int, \WP_Post> */
    public static array $posts = [];
    /** @var array<string, array<int, list<callable>>> */
    public static array $hooks = [];
    /** @var array<string, int> */
    public static array $actionsFired = [];
    /** @var array<string, list<array<int, mixed>>> */
    public static array $actionArgs = [];
    public static int $userId = 0;
    /** @var array<string, bool> */
    public static array $caps = [];
    /** @var array<int, bool> post ids the user may edit */
    public static array $editable = [];
    /** @var array<int, array<string, list<string>>> */
    public static array $terms = [];
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    public static string $locale = 'en_US';
    public static bool $multisite = false;
    /** @var list<int> */
    public static array $sites = [1];
    public static bool $failMetaWrites = false;
    /** @var list<string> */
    public static array $calls = [];
    /** @var list<string> */
    public static array $scripts = [];
    /** @var list<string> */
    public static array $metaBoxes = [];
    public static ?string $screenPostType = null;

    public static function reset(): void
    {
        self::$blog = 1;
        self::$options = [];
        self::$transients = [];
        self::$postMeta = [];
        self::$userMeta = [];
        self::$posts = [];
        self::$hooks = [];
        self::$actionsFired = [];
        self::$actionArgs = [];
        self::$userId = 0;
        self::$caps = [];
        self::$editable = [];
        self::$terms = [];
        self::$routes = [];
        self::$locale = 'en_US';
        self::$multisite = false;
        self::$sites = [1];
        self::$failMetaWrites = false;
        self::$calls = [];
        self::$scripts = [];
        self::$metaBoxes = [];
        self::$screenPostType = null;
        \wpdb::$queries = [];
    }

    public static function addPost(int $id, string $title, string $content, string $type = 'post', string $status = 'draft', int $parent = 0, string $excerpt = ''): \WP_Post
    {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_title = $title;
        $post->post_content = $content;
        $post->post_excerpt = $excerpt;
        $post->post_type = $type;
        $post->post_status = $status;
        $post->post_parent = $parent;
        self::$posts[$id] = $post;

        return $post;
    }

    /** Log in a user who can use the plugin and edit the given posts. */
    public static function loginEditor(int $userId = 7, array $postIds = []): void
    {
        self::$userId = $userId;
        self::$caps = ['edit_posts' => true];
        foreach ($postIds as $id) {
            self::$editable[$id] = true;
        }
    }
}
