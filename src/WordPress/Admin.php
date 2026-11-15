<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Generation\Generator;
use IndianOpinion\HeadlineOptions\Integration\SpendMeter;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ScriptMatchRule;
use IndianOpinion\HeadlineOptions\Settings;

/**
 * Settings screen under Settings, "Headline options", and admin notices.
 * Saved through the Settings API (nonce and capability checked by core).
 */
final class Admin
{
    public const PAGE = 'headline-options';
    public const GROUP = 'headline_options';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_notices', [$this, 'notices']);
        add_filter('option_page_capability_' . self::GROUP, static fn (): string => PostContext::manageCapability());
    }

    public function menu(): void
    {
        add_options_page('Headline options', 'Headline options', PostContext::manageCapability(), self::PAGE, [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting(self::GROUP, Services::SETTINGS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
            'default' => Settings::defaults(),
            'show_in_rest' => false,
        ]);
        register_setting(self::GROUP, Services::KEY_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeKey'],
            'default' => '',
            'show_in_rest' => false,
        ]);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings($input): array
    {
        $input = is_array($input) ? wp_unslash($input) : [];
        foreach (['length_pass', 'length_warn'] as $key) {
            if (isset($input[$key . '_low'], $input[$key . '_high'])) {
                $input[$key] = [$input[$key . '_low'], $input[$key . '_high']];
            }
        }
        $input['enabled'] = !empty($input['enabled']);
        $input['confirm_use'] = !empty($input['confirm_use']);

        return Settings::sanitize($input);
    }

    /**
     * The key field is write-only: an empty submission keeps the stored key.
     *
     * @param mixed $input
     */
    public function sanitizeKey($input): string
    {
        $old = get_option(Services::KEY_OPTION, '');
        $old = is_string($old) ? $old : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce.
        if (!empty($_POST['headline_options_key_remove'])) {
            return '';
        }
        $new = is_string($input) ? trim(sanitize_text_field(wp_unslash($input))) : '';

        return $new === '' ? $old : $new;
    }

    public function render(): void
    {
        if (!current_user_can(PostContext::manageCapability())) {
            return;
        }
        $services = Services::instance();
        $s = $services->settings();
        $status = RestController::statusData(get_current_user_id());
        $locked = Generator::constantDisabled();
        $keyFromConstant = defined('HEADLINE_OPTIONS_PROVIDER_KEY');
        $key = $services->providerKey();
        $name = Services::SETTINGS_OPTION;
        echo '<div class="wrap"><h1>' . esc_html__('Headline options', 'headline-options') . '</h1>';
        $this->screenNotices();
        echo '<p>'
            . esc_html__(
                'When an editor clicks "Suggest headlines", the article text is sent to the model provider you configure here. '
                . 'No reader data, user email or author name is sent.',
                'headline-options'
            )
            . '</p>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Generation enabled', 'headline-options') . '</th><td>';
        if ($locked) {
            echo '<input type="hidden" name="' . esc_attr($name) . '[enabled]" value="' . ($s->bool('enabled') ? '1' : '') . '">';
            echo '<label><input type="checkbox" disabled> ' . esc_html__('Locked off: HEADLINE_OPTIONS_DISABLED is true in wp-config.php.', 'headline-options') . '</label>';
        } else {
            echo '<label><input type="checkbox" name="'
                . esc_attr($name)
                . '[enabled]" value="1"'
                . checked($s->bool('enabled'), true, false)
                . '> '
                . esc_html__('Allow editors to ask for suggestions (spends money on each click).', 'headline-options')
                . '</label>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="ho-provider">' . esc_html__('Provider', 'headline-options') . '</label></th><td><select id="ho-provider" name="' . esc_attr($name) . '[provider]">';
        foreach (['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'compatible' => 'OpenAI-compatible URL'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($s->string('provider'), $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        $this->textRow('base_url', __('Compatible base URL', 'headline-options'), $s->string('base_url'), 'url');

        echo '<tr><th scope="row"><label for="ho-key">' . esc_html__('Provider key', 'headline-options') . '</label></th><td>';
        if ($keyFromConstant) {
            echo esc_html__('Set by HEADLINE_OPTIONS_PROVIDER_KEY.', 'headline-options') . ' <code>' . esc_html(self::mask($key)) . '</code>';
        } else {
            echo '<input id="ho-key" type="password" autocomplete="off" class="regular-text" name="'
                . esc_attr(Services::KEY_OPTION)
                . '" value="" placeholder="'
                . esc_attr($key === '' ? '' : self::mask($key))
                . '">';
            echo '<p class="description">' . esc_html__('Leave empty to keep the stored key.', 'headline-options') . '</p>';
            if ($key !== '') {
                echo '<label><input type="checkbox" name="headline_options_key_remove" value="1"> ' . esc_html__('Remove the stored key', 'headline-options') . '</label>';
            }
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="ho-models">'
            . esc_html__('Model chain', 'headline-options')
            . '</label></th><td><textarea id="ho-models" rows="3" class="regular-text" name="'
            . esc_attr($name)
            . '[models]">'
            . esc_textarea(implode("\n", $s->list('models')))
            . '</textarea><p class="description">'
            . esc_html__('One to three model ids, one per line, tried in order.', 'headline-options')
            . '</p></td></tr>';

        $labels = [
            'count' => __('Candidates per click', 'headline-options'),
            'site_cap' => __('Site daily cap (generations)', 'headline-options'),
            'user_cap' => __('Per-user daily cap', 'headline-options'),
            'rate_per_minute' => __('Per-user rate limit per minute', 'headline-options'),
            'input_limit' => __('Input limit (characters)', 'headline-options'),
            'min_words' => __('Minimum words', 'headline-options'),
        ];
        foreach ($labels as $key2 => $label) {
            [$min, $max] = Settings::RANGES[$key2];
            echo '<tr><th scope="row"><label for="ho-'
                . esc_attr($key2)
                . '">'
                . esc_html($label)
                . '</label></th><td><input id="ho-'
                . esc_attr($key2)
                . '" type="number" min="'
                . esc_attr((string) $min)
                . '" max="'
                . esc_attr((string) $max)
                . '" name="'
                . esc_attr($name)
                . '['
                . esc_attr($key2)
                . ']" value="'
                . esc_attr((string) $s->int($key2))
                . '"></td></tr>';
        }
        $this->textRow('post_types', __('Post types (comma separated)', 'headline-options'), implode(', ', $s->list('post_types')), 'text');
        echo '<tr><th scope="row"><label for="ho-style">'
            . esc_html__('Style note', 'headline-options')
            . '</label></th><td><textarea id="ho-style" rows="3" class="large-text" maxlength="500" name="'
            . esc_attr($name)
            . '[style_note]">'
            . esc_textarea($s->string('style_note'))
            . '</textarea></td></tr>';
        echo '<tr><th scope="row">'
            . esc_html__('Confirm before Use', 'headline-options')
            . '</th><td><label><input type="checkbox" name="'
            . esc_attr($name)
            . '[confirm_use]" value="1"'
            . checked($s->bool('confirm_use'), true, false)
            . '> '
            . esc_html__('Ask before replacing a headline in the editor (each user can turn this off in the panel).', 'headline-options')
            . '</label></td></tr>';
        foreach (['length_pass' => __('Length pass range', 'headline-options'), 'length_warn' => __('Length warn range', 'headline-options')] as $key3 => $label) {
            [$lo, $hi] = $s->range($key3);
            echo '<tr><th scope="row">'
                . esc_html($label)
                . '</th><td><input type="number" min="10" max="200" name="'
                . esc_attr($name)
                . '['
                . esc_attr($key3)
                . '_low]" value="'
                . esc_attr((string) $lo)
                . '"> to <input type="number" min="10" max="200" name="'
                . esc_attr($name)
                . '['
                . esc_attr($key3)
                . '_high]" value="'
                . esc_attr((string) $hi)
                . '"></td></tr>';
        }
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        /** @var array{site_today: int, site_cap: int, user_today: int, user_cap: int} $usage */
        $usage = $status['usage'];
        /** @var array{code: ?string, time: ?int} $last */
        $last = $status['last_error'];
        echo '<h2>' . esc_html__('Status', 'headline-options') . '</h2><ul>';
        echo '<li>' . esc_html(sprintf('Generations today: %d of %d.', $usage['site_today'], $usage['site_cap'])) . '</li>';
        echo '<li>' . esc_html('Length unit: ' . ($status['length_unit'] === 'grapheme' ? 'grapheme clusters (ext-intl)' : 'code points less combining marks (no ext-intl)') . '.') . '</li>';
        echo '<li>' . esc_html($status['script_check'] ? 'Script check: on (script-share installed).' : 'Script check: off. Install script-share for the script check.') . '</li>';
        echo '<li>' . esc_html('Last error: ' . ($last['code'] === null ? 'none' : $last['code'] . ' at ' . gmdate('Y-m-d H:i', (int) $last['time']) . ' UTC') . '.') . '</li>';
        echo '</ul></div>';
    }

    public function notices(): void
    {
        if (!current_user_can(PostContext::manageCapability())) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && $screen->id === 'settings_page_' . self::PAGE) {
            return; // The settings screen prints its own.
        }
        $this->screenNotices();
    }

    private function screenNotices(): void
    {
        foreach (self::noticeMessages() as [$level, $message]) {
            echo '<div class="notice notice-' . esc_attr($level) . '"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function noticeMessages(): array
    {
        $out = [];
        $services = Services::instance();
        $resolved = $services->resolveCaller();
        if ($resolved['reason'] === 'library_missing') {
            $out[] = ['error', 'Headline options: ' . Generator::notConfiguredMessage('library_missing') . ' Scoring still works.'];
        }
        if ($services->errorLog()->creditNotice()) {
            $out[] = ['warning', 'Headline options: the model provider reported that its credit is exhausted. Suggestions will fail until it is topped up.'];
        }
        if (SpendMeter::newer()) {
            $out[] = ['info', 'Headline options: the installed spend-meter uses a newer contract than this plugin knows, so it is not consulted. This plugin\'s own caps still apply.'];
        }
        return $out;
    }

    private function textRow(string $key, string $label, string $value, string $type): void
    {
        echo '<tr><th scope="row"><label for="ho-'
            . esc_attr($key)
            . '">'
            . esc_html($label)
            . '</label></th><td><input id="ho-'
            . esc_attr($key)
            . '" type="'
            . esc_attr($type)
            . '" class="regular-text" name="'
            . esc_attr(Services::SETTINGS_OPTION)
            . '['
            . esc_attr($key)
            . ']" value="'
            . esc_attr($value)
            . '"></td></tr>';
    }

    public static function mask(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $len = strlen($key);

        return str_repeat('*', max(4, $len - 4)) . ($len > 8 ? substr($key, -4) : '');
    }
}
