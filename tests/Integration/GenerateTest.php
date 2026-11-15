<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Integration;

use IndianOpinion\HeadlineOptions\Generation\CandidateSet;
use IndianOpinion\HeadlineOptions\Generation\OutputSchema;
use IndianOpinion\HeadlineOptions\Storage\SetStore;
use IndianOpinion\HeadlineOptions\Storage\UsageStore;
use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Tests\TestCase;
use IndianOpinion\HeadlineOptions\WordPress\Services;
use IndianOpinion\LlmJson\Testing\MockTransport;

/**
 * Generation through the REST route with llm-json-php's mock transport.
 */
final class GenerateTest extends TestCase
{
    private const GOOD = [
        'Reserve Bank holds repo rate at 6.5%',
        'Malhotra keeps repo rate at 6.5% as inflation eases',
        'Repo rate stays at 6.5% after Wednesday meeting',
        'Reserve Bank of India leaves rates alone on Wednesday',
        'Inflation eases and the repo rate holds at 6.5%',
    ];

    private function generate(array $state = [], int $post = 10): \WP_REST_Response
    {
        $p = WpState::$posts[$post] ?? null;

        return $this->dispatch('POST', '/headline-options/v1/posts/' . $post . '/generate', $state + [
            'title' => $p?->post_title ?? '',
            'content' => $p?->post_content ?? '',
            'excerpt' => '',
        ]);
    }

    private function usage(): array
    {
        return json_decode($this->table->rows[UsageStore::OPTION] ?? '{"site":0,"users":[]}', true);
    }

    public function testHappyPath(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $r = $this->generate();
        self::assertSame(200, $r->status, json_encode($r->data));
        self::assertSame('no-store', $r->headers['Cache-Control']);
        self::assertCount(5, $r->data['set']['candidates']);
        self::assertTrue($r->data['saved']);
        self::assertSame(['site_today' => 1, 'site_cap' => 50, 'user_today' => 1, 'user_cap' => 15], $r->data['usage']);
        self::assertSame('Repo rate unchanged', $r->data['set']['current']['headline']);
        self::assertSame(1, did_action('headline_options_generated'));
        self::assertInstanceOf(CandidateSet::class, WpState::$actionArgs['headline_options_generated'][0][0]);
        self::assertArrayNotHasKey(UsageStore::LOCK, $this->table->rows);
        self::assertArrayNotHasKey('headline_options_lock_10', $this->table->rows);
    }

    public function testT4SettingOffNoRequestScoreStillWorks(): void
    {
        $this->configure(['enabled' => false]);
        $this->editorPost();
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_disabled', $r->data['code']);
        self::assertSame([], $this->transport->requests());
        $s = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => ['Reserve Bank holds rates'], 'content' => 'The Reserve Bank held rates.']);
        self::assertSame(200, $s->status);
        self::assertCount(1, $s->data);
        self::assertSame(1, did_action('headline_options_failed'));
    }

    public function testT6NoKeyNotConfiguredScoreWorks(): void
    {
        update_option('headline_options_settings', ['enabled' => true, 'models' => ['m']]);
        $this->editorPost();
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_not_configured', $r->data['code']);
        self::assertSame('No model provider is set up.', $r->data['message']);
        $s = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => ['x'], 'content' => 'y']);
        self::assertSame(200, $s->status);
    }

    public function testT7SaveAutosaveAndPublishMakeNoRequest(): void
    {
        $this->configure();
        \IndianOpinion\HeadlineOptions\WordPress\Plugin::reset();
        \IndianOpinion\HeadlineOptions\WordPress\Plugin::boot(dirname(__DIR__, 2) . '/headline-options.php');
        $post = $this->editorPost();
        foreach (['save_post', 'wp_insert_post', 'transition_post_status', 'publish_post', 'post_updated', 'init', 'wp_after_insert_post'] as $hook) {
            do_action($hook, $post->ID, $post, true);
        }
        self::assertSame([], $this->transport->requests());
        foreach (array_keys(WpState::$hooks) as $hook) {
            self::assertNotContains($hook, ['save_post', 'wp_insert_post', 'transition_post_status', 'publish_post', 'post_updated', 'wp_after_insert_post'], 'plugin hooks ' . $hook);
        }
    }

    public function testT8UsesSuppliedUnsavedState(): void
    {
        $this->configure();
        $this->editorPost(10, 'Old saved title');
        $this->transport->push($this->reply(self::GOOD));
        $unsaved = '<p>' . str_repeat('Unsaved paragraph about the monsoon arriving in Kerala early this year. ', 8) . '</p>';
        $r = $this->generate(['title' => 'Monsoon arrives early', 'content' => $unsaved]);
        self::assertSame(200, $r->status);
        $body = (string) $this->transport->lastRequest()?->body;
        self::assertStringContainsString('monsoon arriving in Kerala', $body);
        self::assertStringContainsString('Monsoon arrives early', $body);
        self::assertStringNotContainsString('Old saved title', $body);
        self::assertSame(hash('sha256', $unsaved), $r->data['set']['content_hash']);
    }

    public function testT11ThirtyNineWordsRefusedFortyProceed(): void
    {
        $this->configure();
        $this->editorPost();
        $r = $this->generate(['content' => '<p>' . trim(str_repeat('word ', 39)) . '</p>']);
        self::assertSame(422, $r->status);
        self::assertSame('headline_options_too_short', $r->data['code']);
        self::assertSame('Write a little more of the article first.', $r->data['message']);
        self::assertSame([], $this->transport->requests());
        self::assertSame(0, $this->usage()['site']);
        $this->transport->push($this->reply(self::GOOD));
        $ok = $this->generate(['content' => '<p>' . trim(str_repeat('word ', 40)) . '</p>']);
        self::assertSame(200, $ok->status);
    }

    public function testT12LanguageFilterReachesPrompt(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD), $this->reply(self::GOOD));
        $this->generate();
        self::assertStringContainsString('in en_US', (string) $this->transport->lastRequest()?->body);
        add_filter('headline_options_post_language', static fn (): string => 'hi_IN');
        $this->generate();
        self::assertStringContainsString('in hi_IN', (string) $this->transport->lastRequest()?->body);
    }

    public function testT14SchemaPassedToLibrary(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $this->generate();
        $body = (string) $this->transport->lastRequest()?->body;
        $schema = OutputSchema::toArray();
        self::assertSame(10, $schema['properties']['candidates']['maxItems']);
        self::assertSame(['headline', 'angle'], $schema['properties']['candidates']['items']['required']);
        self::assertFalse($schema['properties']['candidates']['items']['additionalProperties']);
        self::assertSame(300, $schema['properties']['candidates']['items']['properties']['headline']['maxLength']);
        self::assertSame(80, $schema['properties']['candidates']['items']['properties']['angle']['maxLength']);
        self::assertSame(['candidates', 'entities'], $schema['required']);
        self::assertStringContainsString('candidates', $body);
        self::assertStringContainsString('maxItems', $body);
    }

    public function testRuling7ModelEntityListChecksCaselessHeadlines(): void
    {
        $this->configure();
        add_filter('headline_options_post_language', static fn (): string => 'hi_IN');
        $hindi = '<p>' . str_repeat('रिज़र्व बैंक ने बुधवार को रेपो दर में कोई बदलाव नहीं किया और कहा कि महंगाई कम हुई है। ', 6) . '</p>';
        WpState::addPost(10, 'रेपो दर स्थिर', $hindi);
        WpState::loginEditor(7, [10]);
        $this->transport->push($this->reply([
            'रिज़र्व बैंक ने रेपो दर नहीं बदली',
            'अमित शाह ने रेपो दर पर बयान दिया',
        ], ['रिज़र्व बैंक', 'अमित शाह']));
        $r = $this->generate(['title' => 'रेपो दर स्थिर', 'content' => $hindi]);
        self::assertSame(200, $r->status, json_encode($r->data));
        $byText = [];
        foreach ($r->data['set']['candidates'] as $c) {
            foreach ($c['score']['rules'] as $rule) {
                if ($rule['id'] === 'entity_support') {
                    $byText[$c['text']] = $rule['status'];
                }
            }
        }
        self::assertSame('pass', $byText['रिज़र्व बैंक ने रेपो दर नहीं बदली']);
        self::assertSame('fail', $byText['अमित शाह ने रेपो दर पर बयान दिया']);
    }

    public function testT15LockHeldReturns409AndExpiryFreesIt(): void
    {
        $this->configure();
        $this->editorPost();
        $now = $this->clock->now()->getTimestamp();
        $this->table->rows['headline_options_lock_10'] = (string) ($now + 60);
        $r = $this->generate();
        self::assertSame(409, $r->status);
        self::assertSame('headline_options_busy', $r->data['code']);
        self::assertSame([], $this->transport->requests());
        $this->clock->advance(120);
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
    }

    public function testT16ProviderFailureStillCounted(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push(MockTransport::status(500, 'err'), MockTransport::status(500, 'err'));
        $r = $this->generate();
        self::assertSame(502, $r->status);
        self::assertSame('headline_options_provider_failed', $r->data['code']);
        self::assertSame(1, $this->usage()['site']);
        self::assertSame(1, $this->usage()['users']['7']);
        self::assertSame('headline_options_provider_failed', get_option('headline_options_last_error')['code']);
    }

    public function testT22AllCleanedAwayIs502AndCounted(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(['"Repo rate unchanged"', '**', '  ""  ']));
        $r = $this->generate();
        self::assertSame(502, $r->status);
        self::assertSame('headline_options_no_candidates', $r->data['code']);
        self::assertSame(1, $this->usage()['site']);
    }

    public function testT29ChoiceRecordedTitleUnchanged(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $set = $this->generate()->data['set'];
        $r = $this->dispatch('POST', '/headline-options/v1/posts/10/choice', ['set_id' => $set['id'], 'index' => 2]);
        self::assertSame(200, $r->status);
        self::assertTrue($r->data['recorded']);
        $stored = (new SetStore())->all(10)[0];
        self::assertSame(2, $stored['chosen_index']);
        self::assertSame(7, $stored['chosen_by']);
        self::assertSame('Repo rate unchanged', WpState::$posts[10]->post_title);
        self::assertSame([], WpState::$calls === [] ? [] : array_values(array_filter(WpState::$calls, static fn (string $c): bool => str_starts_with($c, 'wp_'))));
        self::assertSame(1, did_action('headline_options_chosen'));
        $bad = $this->dispatch('POST', '/headline-options/v1/posts/10/choice', ['set_id' => 'nope', 'index' => 0]);
        self::assertFalse($bad->data['recorded']);
    }

    public function testT30SetsShownAgainWithStaleFlag(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $this->generate();
        $hash = hash('sha256', WpState::$posts[10]->post_content);
        $same = $this->dispatch('GET', '/headline-options/v1/posts/10/sets', ['content_hash' => $hash]);
        self::assertFalse($same->data[0]['stale']);
        $other = $this->dispatch('GET', '/headline-options/v1/posts/10/sets', ['content_hash' => str_repeat('a', 64)]);
        self::assertTrue($other->data[0]['stale']);
    }

    public function testSetsKeepLastFive(): void
    {
        $this->configure(['site_cap' => 100, 'user_cap' => 100, 'rate_per_minute' => 30]);
        $this->editorPost();
        for ($i = 0; $i < 7; ++$i) {
            $this->transport->push($this->reply(self::GOOD));
            self::assertSame(200, $this->generate()->status);
        }
        self::assertCount(5, (new SetStore())->all(10));
    }

    public function testT48SiteCapRefusedWithResetTimeNoRequest(): void
    {
        $this->configure(['site_cap' => 1, 'user_cap' => 10]);
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        $r = $this->generate();
        self::assertSame(429, $r->status);
        self::assertSame('headline_options_daily_cap', $r->data['code']);
        self::assertSame('2026-11-16T00:00:00+05:30', $r->data['data']['resets_at']);
        self::assertCount(1, $this->transport->requests());
        self::assertSame(1, did_action('headline_options_cap_reached'));
        self::assertSame(['site', 1, 1], WpState::$actionArgs['headline_options_cap_reached'][0]);
    }

    public function testT49UserCapAndRateLimitWithRetryAfter(): void
    {
        $this->configure(['user_cap' => 1]);
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $this->generate();
        $r = $this->generate();
        self::assertSame('headline_options_user_cap', $r->data['code']);

        $this->configure(['user_cap' => 10, 'rate_per_minute' => 1]);
        $this->clock->advance(86400);
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        $rate = $this->generate();
        self::assertSame(429, $rate->status);
        self::assertSame('headline_options_rate_limited', $rate->data['code']);
        self::assertSame('60', $rate->headers['Retry-After']);
    }

    public function testT51CapZeroDisablesAndEmptyFallsBack(): void
    {
        $this->configure(['site_cap' => 0]);
        $this->editorPost();
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_disabled', $r->data['code']);
        $this->configure(['site_cap' => '']);
        self::assertSame(50, \IndianOpinion\HeadlineOptions\WordPress\Services::instance()->settings()->int('site_cap'));
    }

    public function testCapFilterCanOnlyLower(): void
    {
        $this->configure(['site_cap' => 5]);
        add_filter('headline_options_daily_cap', static fn (): int => 500);
        self::assertSame(5, \IndianOpinion\HeadlineOptions\WordPress\Services::instance()->generator()->siteCap());
        add_filter('headline_options_daily_cap', static fn (): int => 2);
        self::assertSame(2, \IndianOpinion\HeadlineOptions\WordPress\Services::instance()->generator()->siteCap());
    }

    public function testT54CreditExhaustedCodeAndNotice(): void
    {
        $this->configure();
        $this->editorPost();
        $this->transport->push(MockTransport::json(['error' => ['message' => 'Insufficient credits', 'code' => 402]], 402));
        $r = $this->generate();
        self::assertSame(502, $r->status);
        self::assertSame('headline_options_provider_credit', $r->data['code']);
        self::assertTrue(\IndianOpinion\HeadlineOptions\WordPress\Services::instance()->errorLog()->creditNotice());
        $messages = array_column(\IndianOpinion\HeadlineOptions\WordPress\Admin::noticeMessages(), 1);
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'credit')));
        // The next success clears it.
        $this->clock->advance(10);
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        self::assertFalse(\IndianOpinion\HeadlineOptions\WordPress\Services::instance()->errorLog()->creditNotice());
    }

    public function testT55SchemaFailureAndRefusalCodes(): void
    {
        $this->configure();
        $this->editorPost();
        $bad = MockTransport::completion(['wrong' => true]);
        $this->transport->push($bad, $bad);
        $r = $this->generate();
        self::assertSame('headline_options_provider_failed', $r->data['code']);
        self::assertSame('invalid_output', $r->data['data']['reason']);

        $this->clock->advance(3600);
        $refusal = MockTransport::json([
            'id' => 'x', 'object' => 'chat.completion', 'model' => 'm',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'], 'finish_reason' => 'stop']],
        ]);
        $this->transport->push($refusal, $refusal);
        $r2 = $this->generate();
        self::assertSame('headline_options_refused', $r2->data['code'], json_encode($r2->data));
    }

    public function testT56CustomCallerRunsWithoutLibraryAndMissingLibraryReason(): void
    {
        $this->configure();
        $this->editorPost();
        $caller = new class () implements \IndianOpinion\HeadlineOptions\Generation\ModelCaller {
            public int $calls = 0;

            public function describe(): array
            {
                return ['provider' => 'own', 'model' => 'own-model'];
            }

            public function call(\IndianOpinion\HeadlineOptions\Generation\Prompt $prompt, array $schema, float $deadlineSeconds): \IndianOpinion\HeadlineOptions\Generation\CallResult
            {
                ++$this->calls;

                return new \IndianOpinion\HeadlineOptions\Generation\CallResult(['candidates' => [['headline' => 'Reserve Bank holds repo rate at 6.5%', 'angle' => 'x']]], 'own', 'own-model');
            }
        };
        add_filter('headline_options_caller', static fn () => $caller);
        $r = $this->generate();
        self::assertSame(200, $r->status);
        self::assertSame(1, $caller->calls);
        self::assertSame('own-model', $r->data['set']['model']);
        self::assertSame([], $this->transport->requests());
        self::assertStringContainsString('llm-json-php', \IndianOpinion\HeadlineOptions\Generation\Generator::notConfiguredMessage('library_missing'));
    }

    public function testT61BadInputs(): void
    {
        $this->configure();
        $this->editorPost();
        $r = $this->generate(['content' => "bad \xC3\x28 utf8"]);
        self::assertSame(400, $r->status);
        self::assertSame('headline_options_bad_input', $r->data['code']);
        WpState::addPost(11, 'T', 'c', 'post', 'trash');
        WpState::$editable[11] = true;
        self::assertSame(404, $this->generate([], 11)->status);
        self::assertSame(404, $this->generate([], 999)->status);
        $big = $this->dispatch('POST', '/headline-options/v1/posts/10/generate', [], str_repeat('x', 1048577));
        self::assertSame(413, $big->status);
    }

    public function testT62TooLittleTimeRefusesBeforeCall(): void
    {
        $this->configure();
        $this->editorPost();
        $this->timeLeft = 12.0;
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_no_time', $r->data['code']);
        self::assertSame([], $this->transport->requests());
        self::assertSame(0, $this->usage()['site']);
    }

    public function testT60MetaWriteFailureStillReturnsCandidates(): void
    {
        $this->configure();
        $this->editorPost();
        WpState::$failMetaWrites = true;
        $this->transport->push($this->reply(self::GOOD));
        $r = $this->generate();
        self::assertSame(200, $r->status);
        self::assertFalse($r->data['saved']);
        self::assertCount(5, $r->data['set']['candidates']);
    }

    public function testT59CounterWriteFailureRefuses(): void
    {
        $this->configure();
        $this->editorPost();
        $this->table->failWrites = true;
        $r = $this->generate();
        self::assertSame(503, $r->status);
        self::assertSame('headline_options_storage', $r->data['code']);
        self::assertSame([], $this->transport->requests());
    }

    public function testT67AutosaveResolvesToParent(): void
    {
        $this->configure();
        $this->editorPost();
        WpState::addPost(55, 'autosave of 10', 'x', 'revision', 'inherit', 10);
        $this->transport->push($this->reply(self::GOOD));
        $r = $this->generate(['title' => 'Repo rate unchanged', 'content' => WpState::$posts[10]->post_content], 55);
        self::assertSame(200, $r->status);
        self::assertSame(10, $r->data['set']['post_id']);
        self::assertCount(1, (new SetStore())->all(10));
    }

    public function testT73GenQueueNotUsed(): void
    {
        $this->configure();
        $this->editorPost();
        $enqueued = 0;
        add_filter('gen_queue_enqueue', static function ($v) use (&$enqueued) {
            ++$enqueued;

            return $v;
        });
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        self::assertSame(0, $enqueued);
        self::assertSame(0, did_action('gen_queue_enqueue'));
    }

    public function testT74GenerationOffOnFreshInstall(): void
    {
        update_option('headline_options_key', 'k');
        $this->editorPost();
        self::assertFalse(\IndianOpinion\HeadlineOptions\WordPress\Services::instance()->settings()->bool('enabled'));
        self::assertFalse(headline_options_is_enabled());
        self::assertSame('headline_options_disabled', $this->generate()->data['code']);
    }

    public function testT75SetSavedAndCountedWhateverTheBrowserDoes(): void
    {
        // The server does not depend on the client staying connected: the set is saved and counted before the response.
        $this->configure();
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        $set = headline_options_generate(10, ['title' => 'Repo rate unchanged']);
        self::assertCount(1, (new SetStore())->all(10));
        self::assertSame($set->id, (new SetStore())->all(10)[0]['id']);
        self::assertSame(1, $this->usage()['site']);
    }

    public function testT76PostLockedByAnotherUserStillWorks(): void
    {
        $this->configure();
        $this->editorPost();
        update_post_meta(10, '_edit_lock', time() . ':99');
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        self::assertSame(200, $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => ['x'], 'content' => 'y'])->status);
    }

    public function testT66MultisitePerSiteAndConstant(): void
    {
        WpState::$multisite = true;
        $this->configure(['site_cap' => 1]);
        $this->editorPost();
        $this->transport->push($this->reply(self::GOOD));
        self::assertSame(200, $this->generate()->status);
        self::assertSame('headline_options_daily_cap', $this->generate()->data['code']);
        // Site 2 has its own settings (off by default) and its own counters.
        switch_to_blog(2);
        Services::set(new Services(new \IndianOpinion\HeadlineOptions\Storage\MemoryOptionTable(), $this->clock, static fn (): ?float => null));
        self::assertFalse(\IndianOpinion\HeadlineOptions\WordPress\Services::instance()->settings()->bool('enabled'));
        update_option('headline_options_settings', ['enabled' => true, 'models' => ['m']]);
        update_option('headline_options_key', 'k');
        self::assertSame(0, \IndianOpinion\HeadlineOptions\WordPress\Services::instance()->usageStore()->snapshot(7)['site']);
        restore_current_blog();
    }
}
