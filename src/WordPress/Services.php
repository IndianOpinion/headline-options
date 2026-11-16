<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Clock;
use IndianOpinion\HeadlineOptions\Generation\ErrorLog;
use IndianOpinion\HeadlineOptions\Generation\Generator;
use IndianOpinion\HeadlineOptions\Generation\LlmJsonCaller;
use IndianOpinion\HeadlineOptions\Generation\ModelCaller;
use IndianOpinion\HeadlineOptions\Generation\PromptBuilder;
use IndianOpinion\HeadlineOptions\Hooks;
use IndianOpinion\HeadlineOptions\Integration\SpendMeter;
use IndianOpinion\HeadlineOptions\Scoring\ScorerFactory;
use IndianOpinion\HeadlineOptions\Settings;
use IndianOpinion\HeadlineOptions\Storage\Mutex;
use IndianOpinion\HeadlineOptions\Storage\OptionTable;
use IndianOpinion\HeadlineOptions\Storage\PostLock;
use IndianOpinion\HeadlineOptions\Storage\SetStore;
use IndianOpinion\HeadlineOptions\Storage\UsageStore;
use IndianOpinion\HeadlineOptions\Storage\WpdbOptionTable;
use IndianOpinion\HeadlineOptions\SystemClock;
use IndianOpinion\HeadlineOptions\Text\Length;
use IndianOpinion\HeadlineOptions\Text\PlainText;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;

/**
 * Builds the plugin's objects from WordPress state. Settings are read fresh
 * on every call, so a change on the settings screen applies at once.
 */
final class Services
{
    public const SETTINGS_OPTION = 'headline_options_settings';
    public const KEY_OPTION = 'headline_options_key';

    private static ?self $instance = null;

    private readonly OptionTable $table;
    private readonly Clock $clock;
    /** @var callable(): ?float */
    private $timeRemaining;
    /** @var (callable(int): void)|null */
    private $sleep;

    /**
     * @param (callable(): ?float)|null  $timeRemaining
     * @param (callable(int): void)|null $sleep
     */
    public function __construct(
        ?OptionTable $table = null,
        ?Clock $clock = null,
        ?callable $timeRemaining = null,
        ?callable $sleep = null,
        private readonly ?Length $length = null,
    ) {
        if ($table === null) {
            global $wpdb;
            $table = new WpdbOptionTable($wpdb);
        }
        $this->table = $table;
        $this->clock = $clock ?? new SystemClock();
        $this->timeRemaining = $timeRemaining ?? [self::class, 'requestTimeRemaining'];
        $this->sleep = $sleep;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Replace the shared instance (tests). Null resets it. */
    public static function set(?self $services): void
    {
        self::$instance = $services;
    }

    public function settings(): Settings
    {
        $raw = get_option(self::SETTINGS_OPTION, []);

        return new Settings(is_array($raw) ? $raw : []);
    }

    public function length(): Length
    {
        return $this->length ?? new Length();
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function providerKey(): string
    {
        if (defined('HEADLINE_OPTIONS_PROVIDER_KEY') && is_string(constant('HEADLINE_OPTIONS_PROVIDER_KEY'))) {
            return trim((string) constant('HEADLINE_OPTIONS_PROVIDER_KEY'));
        }
        $key = get_option(self::KEY_OPTION, '');

        return is_string($key) ? trim($key) : '';
    }

    public function usageStore(): UsageStore
    {
        return new UsageStore($this->table, new Mutex($this->table, $this->sleep), $this->clock);
    }

    public function postLock(): PostLock
    {
        return new PostLock(new Mutex($this->table, $this->sleep), $this->table);
    }

    public function scorerFactory(?Settings $settings = null): ScorerFactory
    {
        return new ScorerFactory($settings ?? $this->settings(), $this->length());
    }

    public function plainText(): PlainText
    {
        return new PlainText(function_exists('strip_shortcodes') ? 'strip_shortcodes' : null);
    }

    public function errorLog(): ErrorLog
    {
        return new ErrorLog();
    }

    /**
     * The model caller, or the reason there is none.
     *
     * @return array{caller: ?ModelCaller, reason: string}
     */
    public function resolveCaller(?Settings $settings = null): array
    {
        $settings ??= $this->settings();
        $custom = Hooks::filter('headline_options_caller', null, $settings->all());
        if ($custom instanceof ModelCaller) {
            return ['caller' => $custom, 'reason' => ''];
        }
        if (!LlmJsonCaller::available()) {
            return ['caller' => null, 'reason' => 'library_missing'];
        }
        $chain = Hooks::filter('headline_options_chain', null, $settings->all());
        if (!$chain instanceof Chain) {
            $key = $this->providerKey();
            $provider = $settings->string('provider');
            if ($key === '' && $provider !== 'compatible') {
                return ['caller' => null, 'reason' => 'no_key'];
            }
            if ($provider === 'compatible' && $settings->string('base_url') === '') {
                return ['caller' => null, 'reason' => 'no_url'];
            }
            if ($settings->list('models') === []) {
                return ['caller' => null, 'reason' => 'no_model'];
            }
            try {
                $chain = LlmJsonCaller::chainFromSettings($provider, $key, $settings->list('models'), $settings->string('base_url'));
            } catch (\Throwable $e) {
                return ['caller' => null, 'reason' => 'invalid_provider'];
            }
        }
        $transportClass = 'IndianOpinion\\LlmJson\\Transport\\WordPressTransport';
        $storeClass = 'IndianOpinion\\LlmJson\\Store\\WordPressTransientStore';
        $transport = (function_exists('wp_remote_post') && class_exists($transportClass)) ? new $transportClass() : null;
        $client = Client::create($chain, $transport);
        if (class_exists($storeClass) && function_exists('get_transient')) {
            try {
                $client = $client->withStore(new $storeClass());
            } catch (\Throwable $e) {
                // Cooldowns then last for this request only; the call still works.
            }
        }
        $client = Hooks::filter('headline_options_llm_client', $client, $settings->all());
        if (!$client instanceof Client) {
            return ['caller' => null, 'reason' => 'invalid_provider'];
        }

        return ['caller' => new LlmJsonCaller($client, $chain), 'reason' => ''];
    }

    public function generator(?Settings $settings = null): Generator
    {
        $settings ??= $this->settings();

        return new Generator(
            $settings,
            $this->usageStore(),
            $this->postLock(),
            new SetStore(),
            fn (): array => $this->resolveCaller($settings),
            new SpendMeter(),
            new PromptBuilder([self::class, 'promptWarningGate']),
            $this->scorerFactory($settings),
            $this->plainText(),
            $this->clock,
            $this->timeRemaining,
            static fn (): string => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16)),
            $this->errorLog(),
            $this->length(),
        );
    }

    /** Seconds left before PHP's time limit, or null when there is none. */
    public static function requestTimeRemaining(): ?float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit <= 0) {
            return null;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);

        return $limit - (microtime(true) - $start);
    }

    /** True at most once an hour: limits prompt-library warnings. */
    public static function promptWarningGate(): bool
    {
        if (get_transient('headline_options_promptlib_warned') !== false) {
            return false;
        }
        set_transient('headline_options_promptlib_warned', 1, 3600);

        return true;
    }
}
