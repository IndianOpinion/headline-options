<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

/**
 * Loads the block editor panel and the reduced classic editor meta box, only
 * on enabled post types and only for users who may use them. Nothing loads
 * on public pages.
 */
final class Editor
{
    public const USER_META_SKIP_CONFIRM = 'headline_options_skip_confirm';

    public function __construct(private readonly string $pluginFile)
    {
    }

    public function register(): void
    {
        add_action('enqueue_block_editor_assets', [$this, 'blockAssets']);
        add_action('add_meta_boxes', [$this, 'metaBox'], 10, 2);
        add_action('init', [self::class, 'registerMeta']);
    }

    public static function registerMeta(): void
    {
        register_post_meta('', \IndianOpinion\HeadlineOptions\Storage\SetStore::META_KEY, [
            'type' => 'array',
            'single' => true,
            'show_in_rest' => false,
            'revisions_enabled' => false,
            'auth_callback' => static fn (): bool => false,
        ]);
        register_meta('user', self::USER_META_SKIP_CONFIRM, [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'show_in_rest' => true,
            'auth_callback' => static fn ($allowed, $key, $userId): bool => (int) $userId === get_current_user_id(),
        ]);
    }

    /** Case 1: only enabled types that support a title. */
    public static function enabledFor(string $postType): bool
    {
        return in_array($postType, PostContext::postTypes(), true);
    }

    public function blockAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $type = $screen !== null ? (string) $screen->post_type : '';
        if ($type === '' || !self::enabledFor($type) || !current_user_can(PostContext::capability())) {
            return;
        }
        $this->enqueueCore();
        wp_enqueue_script(
            'headline-options-panel',
            plugins_url('assets/panel.js', $this->pluginFile),
            ['headline-options-core', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n'],
            HEADLINE_OPTIONS_VERSION,
            true
        );
    }

    /**
     * Ruling 8: the classic editor gets a reduced meta box with the list and
     * a Use button.
     */
    public function metaBox(string $postType, mixed $post = null): void
    {
        if (!self::enabledFor($postType) || !current_user_can(PostContext::capability())) {
            return;
        }
        if ($post instanceof \WP_Post && function_exists('use_block_editor_for_post') && use_block_editor_for_post($post)) {
            return;
        }
        add_meta_box('headline-options', 'Headline options', [$this, 'renderMetaBox'], $postType, 'side', 'high');
        $this->enqueueCore();
        wp_enqueue_script(
            'headline-options-classic',
            plugins_url('assets/classic.js', $this->pluginFile),
            ['headline-options-core', 'wp-api-fetch'],
            HEADLINE_OPTIONS_VERSION,
            true
        );
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        echo '<div id="headline-options-classic" data-post-id="' . esc_attr((string) $post->ID) . '">';
        echo '<p><button type="button" class="button" data-action="suggest">' . esc_html__('Suggest headlines', 'headline-options') . '</button></p>';
        echo '<p class="headline-options-message" role="status" aria-live="polite"></p>';
        echo '<ol class="headline-options-list"></ol>';
        echo '</div>';
    }

    private function enqueueCore(): void
    {
        wp_enqueue_script('headline-options-core', plugins_url('assets/core.js', $this->pluginFile), [], HEADLINE_OPTIONS_VERSION, true);
        $settings = Services::instance()->settings();
        $skip = get_user_meta(get_current_user_id(), self::USER_META_SKIP_CONFIRM, true);
        $config = [
            'namespace' => RestController::NS,
            'confirmUse' => $settings->bool('confirm_use') && !($skip === true || $skip === '1' || $skip === 1),
            'count' => $settings->int('count'),
            'debounceMs' => 400,
        ];
        wp_add_inline_script('headline-options-core', 'window.headlineOptionsConfig = ' . wp_json_encode($config) . ';', 'before');
    }
}
