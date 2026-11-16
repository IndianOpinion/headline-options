<?php

// phpcs:ignoreFile -- WordPress test doubles, not library code.

declare(strict_types=1);

use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState as S;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('HEADLINE_OPTIONS_VERSION')) {
    define('HEADLINE_OPTIONS_VERSION', '0.1.0');
}

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    S::$hooks[$hook][$priority][] = $cb;

    return true;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function remove_all_filters(string $hook): void
{
    unset(S::$hooks[$hook]);
}

function has_filter(string $hook): bool
{
    return !empty(S::$hooks[$hook]);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(S::$hooks[$hook])) {
        return $value;
    }
    ksort(S::$hooks[$hook]);
    foreach (S::$hooks[$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    S::$actionsFired[$hook] = (S::$actionsFired[$hook] ?? 0) + 1;
    S::$actionArgs[$hook][] = $args;
    if (empty(S::$hooks[$hook])) {
        return;
    }
    ksort(S::$hooks[$hook]);
    foreach (S::$hooks[$hook] as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}

function did_action(string $hook): int
{
    return S::$actionsFired[$hook] ?? 0;
}

function get_option(string $name, mixed $default = false): mixed
{
    return S::$options[S::$blog][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    S::$options[S::$blog][$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, S::$options[S::$blog] ?? [])) {
        return false;
    }
    S::$options[S::$blog][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(S::$options[S::$blog][$name]);

    return true;
}

function get_transient(string $name): mixed
{
    return S::$transients[S::$blog][$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    S::$transients[S::$blog][$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset(S::$transients[S::$blog][$name]);

    return true;
}

function get_site_transient(string $name): mixed
{
    return get_transient('site:' . $name);
}

function set_site_transient(string $name, mixed $value, int $ttl = 0): bool
{
    return set_transient('site:' . $name, $value, $ttl);
}

function delete_site_transient(string $name): bool
{
    return delete_transient('site:' . $name);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function get_post(int $id): ?WP_Post
{
    return S::$posts[$id] ?? null;
}

function wp_is_post_autosave(int $id): int|false
{
    $p = S::$posts[$id] ?? null;

    return ($p !== null && $p->post_type === 'revision' && str_contains($p->post_title, 'autosave')) ? $p->post_parent : false;
}

function wp_is_post_revision(int $id): int|false
{
    $p = S::$posts[$id] ?? null;

    return ($p !== null && $p->post_type === 'revision') ? $p->post_parent : false;
}

function wp_update_post(mixed ...$args): int
{
    S::$calls[] = 'wp_update_post';

    return 0;
}

function wp_insert_post(mixed ...$args): int
{
    S::$calls[] = 'wp_insert_post';

    return 0;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $value = S::$postMeta[S::$blog][$id][$key] ?? null;
    if ($single) {
        return $value ?? '';
    }

    return $value === null ? [] : [$value];
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    if (S::$failMetaWrites) {
        return false;
    }
    S::$postMeta[S::$blog][$id][$key] = $value;

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (S::$postMeta[S::$blog] ?? [] as $id => $meta) {
        unset(S::$postMeta[S::$blog][$id][$key]);
    }

    return true;
}

function register_post_meta(string $type, string $key, array $args): bool
{
    S::$calls[] = 'register_post_meta:' . $key;

    return true;
}

function register_meta(string $type, string $key, array $args): bool
{
    S::$calls[] = 'register_meta:' . $type . ':' . $key;

    return true;
}

function get_user_meta(int $id, string $key = '', bool $single = false): mixed
{
    return S::$userMeta[$id][$key] ?? ($single ? '' : []);
}

function delete_metadata(string $type, int $id, string $key, mixed $value = '', bool $all = false): bool
{
    if ($type === 'user' && $all) {
        foreach (S::$userMeta as $uid => $meta) {
            unset(S::$userMeta[$uid][$key]);
        }
    }

    return true;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    if (S::$userId === 0) {
        return false;
    }
    if ($cap === 'edit_post') {
        return !empty(S::$editable[(int) ($args[0] ?? 0)]);
    }

    return !empty(S::$caps[$cap]);
}

function get_current_user_id(): int
{
    return S::$userId;
}

function is_user_logged_in(): bool
{
    return S::$userId > 0;
}

function post_type_supports(string $type, string $feature): bool
{
    return in_array($type, ['post', 'page', 'book'], true) && $feature === 'title';
}

function taxonomy_exists(string $tax): bool
{
    return in_array($tax, ['post_tag', 'category'], true);
}

function wp_get_post_terms(int $id, string $tax, array $args = []): array
{
    return S::$terms[$id][$tax] ?? [];
}

function get_locale(): string
{
    return S::$locale;
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Kolkata');
}

function wp_generate_uuid4(): string
{
    return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0x8000, 0xbfff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
}

function wp_json_encode(mixed $data, int $flags = 0): string|false
{
    return json_encode($data, $flags);
}

function register_rest_route(string $ns, string $route, array $args): bool
{
    S::$routes[$ns . $route] = $args;

    return true;
}

function is_multisite(): bool
{
    return S::$multisite;
}

function get_sites(array $args = []): array
{
    return S::$sites;
}

function switch_to_blog(int $id): bool
{
    S::$calls[] = 'switch_to_blog:' . $id;
    S::$blog = $id;

    return true;
}

function restore_current_blog(): bool
{
    S::$blog = 1;

    return true;
}

function is_admin(): bool
{
    return false;
}

function esc_html(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

function __(string $t, string $d = 'default'): string
{
    return $t;
}

function esc_html__(string $t, string $d = 'default'): string
{
    return esc_html($t);
}

function checked(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a == $b ? ' checked="checked"' : '';
}

function selected(mixed $a, mixed $b = true, bool $echo = true): string
{
    return $a == $b ? ' selected="selected"' : '';
}

function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
}

function submit_button(): void
{
    echo '<button type="submit">Save</button>';
}

function wp_unslash(mixed $v): mixed
{
    return $v;
}

function sanitize_text_field(string $v): string
{
    return trim(strip_tags($v));
}

function get_current_screen(): ?object
{
    if (S::$screenPostType === null) {
        return null;
    }

    return (object) ['id' => 'post', 'post_type' => S::$screenPostType];
}

function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $footer = false): void
{
    S::$scripts[] = $handle;
}

function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
{
    return true;
}

function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://example.test/wp-content/plugins/headline-options/' . $path;
}

function add_meta_box(string $id, string $title, callable $cb, mixed $screen = null, string $context = 'advanced', string $priority = 'default'): void
{
    S::$metaBoxes[] = $id . ':' . (string) $screen;
}

function use_block_editor_for_post(WP_Post $post): bool
{
    return false;
}

$GLOBALS['wpdb'] = new wpdb();
