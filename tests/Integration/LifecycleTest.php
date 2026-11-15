<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Integration;

use IndianOpinion\HeadlineOptions\Storage\UsageStore;
use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Tests\TestCase;
use IndianOpinion\HeadlineOptions\WordPress\Cli;
use IndianOpinion\HeadlineOptions\WordPress\Uninstaller;

/**
 * T31, T70, T72, and the settings sanitiser.
 */
final class LifecycleTest extends TestCase
{
    public function testT31NoCodePathWritesThePostTitle(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/*.php') ?: [],
            glob($root . '/src/*/*.php') ?: [],
            glob($root . '/src/*/*/*.php') ?: [],
            [$root . '/headline-options.php', $root . '/uninstall.php', $root . '/bootstrap.php'],
        );
        self::assertGreaterThan(30, count($files));
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['wp_update_post', 'wp_insert_post', 'wp_save_post_revision', "'post_title' =>", '"post_title" =>', '->post_title =', 'post_name', 'post_excerpt ='] as $needle) {
                self::assertStringNotContainsString($needle, $code, basename($file) . ' contains ' . $needle);
            }
        }
        foreach (glob($root . '/assets/*.js') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringNotContainsString('savePost', $code, basename($file));
            self::assertStringNotContainsString('innerHTML', $code, basename($file));
        }

        // Runtime spy: a full generate, choice and CLI run call neither.
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(['Reserve Bank holds repo rate at 6.5%']));
        $set = $this->dispatch('POST', '/headline-options/v1/posts/10/generate', ['title' => 'Repo rate unchanged', 'content' => WpState::$posts[10]->post_content])->data['set'];
        $this->dispatch('POST', '/headline-options/v1/posts/10/choice', ['set_id' => $set['id'], 'index' => 0]);
        self::assertNotContains('wp_update_post', WpState::$calls);
        self::assertNotContains('wp_insert_post', WpState::$calls);
        self::assertSame('Repo rate unchanged', WpState::$posts[10]->post_title);
    }

    public function testT70UninstallRemovesEverything(): void
    {
        $this->configure();
        update_option(UsageStore::OPTION, 'x');
        update_option('headline_options_last_error', ['code' => 'x']);
        update_option('headline_options_lock_10', '1');
        update_option('unrelated_option', 'keep');
        set_transient('headline_options_promptlib_warned', 1);
        update_post_meta(10, '_headline_options_sets', [['id' => 'a']]);
        update_post_meta(10, 'other_meta', 'keep');
        WpState::$userMeta[7]['headline_options_skip_confirm'] = '1';
        Uninstaller::run();
        foreach (['headline_options_settings', 'headline_options_key', UsageStore::OPTION, 'headline_options_last_error', 'headline_options_lock_10'] as $o) {
            self::assertFalse(get_option($o), $o);
        }
        self::assertSame('keep', get_option('unrelated_option'));
        self::assertFalse(get_transient('headline_options_promptlib_warned'));
        self::assertSame('', get_post_meta(10, '_headline_options_sets', true));
        self::assertSame('keep', get_post_meta(10, 'other_meta', true));
        self::assertArrayNotHasKey('headline_options_skip_confirm', WpState::$userMeta[7]);
    }

    public function testT70UninstallRunsForEverySite(): void
    {
        WpState::$multisite = true;
        WpState::$sites = [1, 2, 3];
        foreach ([1, 2, 3] as $blog) {
            WpState::$blog = $blog;
            update_option('headline_options_settings', ['enabled' => true]);
        }
        WpState::$blog = 1;
        Uninstaller::run();
        foreach ([1, 2, 3] as $blog) {
            self::assertArrayNotHasKey('headline_options_settings', WpState::$options[$blog] ?? []);
        }
        self::assertContains('switch_to_blog:3', WpState::$calls);
    }

    public function testT72CliExitCodesAndTitleUntouched(): void
    {
        $this->editorPost();
        $cli = new Cli();
        try {
            $cli->generate(['10'], []);
            self::fail('expected exit');
        } catch (\WpCliExit $e) {
            self::assertSame(3, $e->exitCode);
        }
        $this->configure(['site_cap' => 1]);
        $this->transport->push($this->reply(['Reserve Bank holds repo rate at 6.5%']));
        $cli->generate(['10'], ['format' => 'json']);
        $json = json_decode((string) end(\WP_CLI::$lines), true);
        self::assertSame(0, $json['set']['user_id']);
        try {
            $cli->generate(['10'], []);
            self::fail('expected exit');
        } catch (\WpCliExit $e) {
            self::assertSame(4, $e->exitCode);
        }
        self::assertSame('Repo rate unchanged', WpState::$posts[10]->post_title);
        self::assertNotContains('wp_update_post', WpState::$calls);
    }

    public function testCliStatusScoreSetsReset(): void
    {
        $this->configure();
        $this->editorPost();
        $cli = new Cli();
        $cli->status([], ['format' => 'json']);
        $status = json_decode((string) end(\WP_CLI::$lines), true);
        self::assertTrue($status['key_present']);
        self::assertStringNotContainsString('test-key', implode("\n", \WP_CLI::$lines));
        $cli->score(['Reserve Bank holds repo rate at 6.5%'], ['post' => '10', 'format' => 'json']);
        $score = json_decode((string) end(\WP_CLI::$lines), true);
        self::assertIsInt($score[0]['total']);
        $this->transport->push($this->reply(['Reserve Bank holds repo rate at 6.5%']));
        $cli->generate(['10'], []);
        \WP_CLI::reset();
        $cli->sets(['10'], []);
        self::assertCount(1, \WP_CLI::$lines);
        $cli->reset_usage([], ['yes' => true]);
        self::assertSame(0, \IndianOpinion\HeadlineOptions\WordPress\Services::instance()->usageStore()->snapshot(null)['site']);
    }

    public function testSettingsSanitiser(): void
    {
        $s = \IndianOpinion\HeadlineOptions\Settings::sanitize([
            'count' => '42', 'site_cap' => '', 'user_cap' => '-3', 'models' => "a/b\n\nc/d\ne/f\ng/h\nbad model!",
            'style_note' => '<b>Short</b> lines', 'length_pass' => [80, 40], 'post_types' => 'post, Book!, page',
            'provider' => 'evil', 'base_url' => 'javascript:alert(1)',
        ]);
        self::assertSame(10, $s['count']);
        self::assertSame(50, $s['site_cap']);
        self::assertSame(0, $s['user_cap']);
        self::assertSame(['a/b', 'c/d', 'e/f'], $s['models']);
        self::assertSame('Short lines', $s['style_note']);
        self::assertSame([30, 70], $s['length_pass']);
        self::assertSame(['post', 'page'], $s['post_types']);
        self::assertSame('openrouter', $s['provider']);
        self::assertSame('', $s['base_url']);
        self::assertFalse($s['enabled']);
    }
}
