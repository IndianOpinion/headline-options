<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Generation\ErrorLog;
use IndianOpinion\HeadlineOptions\Storage\PostLock;
use IndianOpinion\HeadlineOptions\Storage\SetStore;
use IndianOpinion\HeadlineOptions\Storage\UsageStore;

/**
 * Removes every option, lock row, transient, post meta and user meta key the
 * plugin created, on every site, unless HEADLINE_OPTIONS_KEEP_DATA is true.
 * Deactivation deletes nothing.
 */
final class Uninstaller
{
    public static function keepData(): bool
    {
        return defined('HEADLINE_OPTIONS_KEEP_DATA') && constant('HEADLINE_OPTIONS_KEEP_DATA') === true;
    }

    public static function run(): void
    {
        if (self::keepData()) {
            return;
        }
        if (is_multisite()) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($sites) ? $sites : [] as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
        } else {
            self::site();
        }
        delete_metadata('user', 0, Editor::USER_META_SKIP_CONFIRM, '', true);
    }

    private static function site(): void
    {
        foreach ([Services::SETTINGS_OPTION, Services::KEY_OPTION, UsageStore::OPTION, UsageStore::LOCK, ErrorLog::OPTION] as $option) {
            delete_option($option);
        }
        delete_transient('headline_options_promptlib_warned');
        delete_post_meta_by_key(SetStore::META_KEY);
        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $like = $wpdb->esc_like(PostLock::PREFIX) . '%';
            $sql = $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like);
            if (is_string($sql)) {
                $wpdb->query($sql);
            }
        }
    }
}
