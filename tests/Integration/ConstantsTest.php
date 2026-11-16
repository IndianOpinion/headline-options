<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Integration;

use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Tests\TestCase;
use IndianOpinion\HeadlineOptions\WordPress\Admin;
use IndianOpinion\HeadlineOptions\WordPress\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that define constants run in their own process.
 * T5, T52, T53, T57, T66 (constant), T70 (keep data).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConstantsTest extends TestCase
{
    private const REPLY = ['Reserve Bank holds repo rate at 6.5%', 'Malhotra keeps repo rate at 6.5% as inflation eases'];

    private function generate(): \WP_REST_Response
    {
        return $this->dispatch('POST', '/headline-options/v1/posts/10/generate', ['title' => 'Repo rate unchanged', 'content' => WpState::$posts[10]->post_content]);
    }

    public function testT5ConstantWinsAndScreenIsLocked(): void
    {
        define('HEADLINE_OPTIONS_DISABLED', true);
        $this->configure(['enabled' => true]);
        $this->editorPost();
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_disabled', $r->data['code']);
        self::assertSame([], $this->transport->requests());
        WpState::$caps['manage_options'] = true;
        ob_start();
        (new Admin())->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Locked off: HEADLINE_OPTIONS_DISABLED is true', $html);
        self::assertStringContainsString('<input type="checkbox" disabled>', $html);
        self::assertFalse(headline_options_is_enabled());
    }

    public function testT66ConstantSwitchesOffEverySite(): void
    {
        define('HEADLINE_OPTIONS_DISABLED', true);
        WpState::$multisite = true;
        foreach ([1, 2] as $blog) {
            switch_to_blog($blog);
            $this->configure();
            self::assertFalse(Services::instance()->generator()->availability()['enabled']);
            self::assertSame('constant', Services::instance()->generator()->availability()['reason']);
        }
        restore_current_blog();
    }

    public function testT52MeterDenies(): void
    {
        define('SPEND_METER_API', 1);
        $this->configure();
        $this->editorPost();
        $seen = null;
        add_filter('spend_meter_pre_spend', static function ($d, array $args) use (&$seen) {
            $seen = $args;

            return ['allowed' => false, 'reason' => 'cap_site_day'];
        }, 10, 2);
        $r = $this->generate();
        self::assertSame(429, $r->status);
        self::assertSame('headline_options_meter_refused', $r->data['code']);
        self::assertSame('cap_site_day', $r->data['data']['reason']);
        self::assertSame([], $this->transport->requests());
        self::assertSame('headline-options.generate', $seen['purpose']);
        self::assertSame('headline-options', $seen['caller']);
        self::assertSame('openrouter', $seen['provider']);
        self::assertSame('test/model-a', $seen['model']);
        self::assertGreaterThan(0, $seen['est_input_tokens']);
        // Not attempted: our own counter is given back.
        self::assertSame(0, Services::instance()->usageStore()->snapshot(7)['site']);
    }

    public function testT52MeterAllowsRecordFollowsSuccessReleaseFollowsPreCallFailure(): void
    {
        define('SPEND_METER_API', 1);
        $this->configure();
        $this->editorPost();
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => true, 'reason' => 'ok', 'reservation_id' => 'r-1']);
        $this->transport->push($this->reply(self::REPLY));
        self::assertSame(200, $this->generate()->status);
        self::assertSame(1, did_action('spend_meter_record'));
        self::assertSame(0, did_action('spend_meter_release'));
        $record = WpState::$actionArgs['spend_meter_record'][0][0];
        self::assertSame('r-1', $record['reservation_id']);
        self::assertSame('ok', $record['status']);
        self::assertSame(100, $record['input_tokens']);

        // A caller that fails before sending anything: release, not record.
        add_filter('headline_options_caller', static fn () => new class () implements \IndianOpinion\HeadlineOptions\Generation\ModelCaller {
            public function describe(): array
            {
                return ['provider' => 'p', 'model' => 'm'];
            }

            public function call(\IndianOpinion\HeadlineOptions\Generation\Prompt $prompt, array $schema, float $deadlineSeconds): \IndianOpinion\HeadlineOptions\Generation\CallResult
            {
                throw new \IndianOpinion\HeadlineOptions\Exception\ProviderException('bad config', \IndianOpinion\HeadlineOptions\Exception\ProviderException::FAILED, 'configuration', false);
            }
        });
        $this->generate();
        self::assertSame(1, did_action('spend_meter_record'));
        self::assertSame(1, did_action('spend_meter_release'));
        self::assertSame(['r-1'], WpState::$actionArgs['spend_meter_release'][0]);
    }

    public function testT53OwnCapFirstMeterNeverAsked(): void
    {
        define('SPEND_METER_API', 1);
        $this->configure(['site_cap' => 1]);
        $this->editorPost();
        $asked = 0;
        add_filter('spend_meter_pre_spend', static function () use (&$asked) {
            ++$asked;

            return ['allowed' => true, 'reason' => 'ok'];
        });
        $this->transport->push($this->reply(self::REPLY));
        $this->generate();
        self::assertSame(1, $asked);
        self::assertSame('headline_options_daily_cap', $this->generate()->data['code']);
        self::assertSame(1, $asked);
    }

    public function testT53NewerContractTreatedAsAbsentWithNotice(): void
    {
        define('SPEND_METER_API', 2);
        $this->configure();
        $this->editorPost();
        $asked = 0;
        add_filter('spend_meter_pre_spend', static function () use (&$asked) {
            ++$asked;

            return ['allowed' => false, 'reason' => 'x'];
        });
        $this->transport->push($this->reply(self::REPLY));
        self::assertSame(200, $this->generate()->status);
        self::assertSame(0, $asked);
        $messages = implode(' ', array_column(Admin::noticeMessages(), 1));
        self::assertStringContainsString('newer contract', $messages);
    }

    public function testT57UnknownReasonAndThrowingFilterDeny(): void
    {
        define('SPEND_METER_API', 1);
        $this->configure(['rate_per_minute' => 30]);
        $this->editorPost();
        add_filter('spend_meter_pre_spend', static fn () => ['allowed' => 'yes', 'reason' => 'brand_new_code']);
        $r = $this->generate();
        self::assertSame('headline_options_meter_refused', $r->data['code']);
        self::assertSame('brand_new_code', $r->data['data']['reason']);
        remove_all_filters('spend_meter_pre_spend');
        add_filter('spend_meter_pre_spend', static function (): never {
            throw new \RuntimeException('meter broke');
        });
        $r2 = $this->generate();
        self::assertSame('headline_options_meter_refused', $r2->data['code']);
        self::assertSame([], $this->transport->requests());
    }

    public function testT70KeepDataConstant(): void
    {
        define('HEADLINE_OPTIONS_KEEP_DATA', true);
        $this->configure();
        update_post_meta(10, '_headline_options_sets', [['id' => 'a']]);
        \IndianOpinion\HeadlineOptions\WordPress\Uninstaller::run();
        self::assertNotFalse(get_option('headline_options_settings'));
        self::assertSame([['id' => 'a']], get_post_meta(10, '_headline_options_sets', true));
    }

    public function testKeyConstantPreferredAndMasked(): void
    {
        define('HEADLINE_OPTIONS_PROVIDER_KEY', 'constant-key-abcdef');
        update_option('headline_options_key', 'option-key');
        self::assertSame('constant-key-abcdef', Services::instance()->providerKey());
        self::assertStringEndsWith('cdef', Admin::mask('constant-key-abcdef'));
        self::assertStringNotContainsString('constant', Admin::mask('constant-key-abcdef'));
    }
}
