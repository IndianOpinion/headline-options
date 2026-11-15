<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

use IndianOpinion\HeadlineOptions\Clock;
use IndianOpinion\HeadlineOptions\Exception\BadInputException;
use IndianOpinion\HeadlineOptions\Exception\CapReachedException;
use IndianOpinion\HeadlineOptions\Exception\DisabledException;
use IndianOpinion\HeadlineOptions\Exception\HeadlineOptionsException;
use IndianOpinion\HeadlineOptions\Exception\MeterRefusedException;
use IndianOpinion\HeadlineOptions\Exception\NoCandidatesException;
use IndianOpinion\HeadlineOptions\Exception\NotConfiguredException;
use IndianOpinion\HeadlineOptions\Exception\NoTimeException;
use IndianOpinion\HeadlineOptions\Exception\ProviderException;
use IndianOpinion\HeadlineOptions\Exception\TooShortException;
use IndianOpinion\HeadlineOptions\Hooks;
use IndianOpinion\HeadlineOptions\Integration\SpendMeter;
use IndianOpinion\HeadlineOptions\Logger;
use IndianOpinion\HeadlineOptions\Scoring\ScorerFactory;
use IndianOpinion\HeadlineOptions\Settings;
use IndianOpinion\HeadlineOptions\Storage\PostLock;
use IndianOpinion\HeadlineOptions\Storage\SetStore;
use IndianOpinion\HeadlineOptions\Storage\UsageStore;
use IndianOpinion\HeadlineOptions\Text\Cleaner;
use IndianOpinion\HeadlineOptions\Text\Length;
use IndianOpinion\HeadlineOptions\Text\PlainText;
use IndianOpinion\HeadlineOptions\Text\Trimmer;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * One generation, from a click to a scored candidate set.
 *
 * Order (fixed): switch and constant, configuration, input checks, minimum
 * words, time left, post lock, own caps and rate limit, spend-meter, the one
 * model call, cleaning and scoring, saving. Every refusal before the call
 * spends nothing. A call is counted once attempted, success or not.
 *
 * Never writes the post title or any other post field.
 */
final class Generator
{
    public const MAX_OUTPUT_TOKENS = 1500;

    /** @var callable(): array{caller: ?ModelCaller, reason: string} */
    private $callerResolver;

    /** @var callable(): ?float */
    private $timeRemaining;

    /** @var callable(): string */
    private $uuid;

    /**
     * @param callable(): array{caller: ?ModelCaller, reason: string} $callerResolver
     * @param callable(): ?float                                    $timeRemaining seconds left in this request, null when unlimited
     * @param callable(): string                                    $uuid
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly UsageStore $usage,
        private readonly PostLock $lock,
        private readonly SetStore $sets,
        callable $callerResolver,
        private readonly SpendMeter $meter,
        private readonly PromptBuilder $prompts,
        private readonly ScorerFactory $scorers,
        private readonly PlainText $plain,
        private readonly Clock $clock,
        callable $timeRemaining,
        callable $uuid,
        private readonly ErrorLog $errors,
        private readonly Length $length = new Length(),
    ) {
        $this->callerResolver = $callerResolver;
        $this->timeRemaining = $timeRemaining;
        $this->uuid = $uuid;
    }

    public static function constantDisabled(): bool
    {
        return defined('HEADLINE_OPTIONS_DISABLED') && constant('HEADLINE_OPTIONS_DISABLED') === true;
    }

    /**
     * @return array{enabled: bool, configured: bool, reason: string}
     */
    public function availability(): array
    {
        $resolved = ($this->callerResolver)();
        $configured = $resolved['caller'] instanceof ModelCaller;
        $reason = $configured ? '' : $resolved['reason'];
        $enabled = true;
        if (self::constantDisabled()) {
            $enabled = false;
            $reason = 'constant';
        } elseif (!$this->settings->bool('enabled')) {
            $enabled = false;
            $reason = 'setting';
        } elseif ($this->siteCap() === 0 || $this->userCap() === 0) {
            $enabled = false;
            $reason = 'cap_zero';
        }

        return ['enabled' => $enabled && $configured, 'configured' => $configured, 'reason' => $reason];
    }

    public function siteCap(): int
    {
        return self::lowerOnly(Hooks::filter('headline_options_daily_cap', $this->settings->int('site_cap')), $this->settings->int('site_cap'));
    }

    public function userCap(): int
    {
        return self::lowerOnly(Hooks::filter('headline_options_user_cap', $this->settings->int('user_cap')), $this->settings->int('user_cap'));
    }

    /**
     * @return array{site_today: int, site_cap: int, user_today: int, user_cap: int}
     */
    public function usage(?int $userId): array
    {
        $snap = $this->usage->snapshot($userId);

        return ['site_today' => $snap['site'], 'site_cap' => $this->siteCap(), 'user_today' => $snap['user'], 'user_cap' => $this->userCap()];
    }

    /**
     * @throws HeadlineOptionsException
     */
    public function run(GenerationRequest $req): GenerationResult
    {
        try {
            return $this->doRun($req);
        } catch (HeadlineOptionsException $e) {
            Hooks::action('headline_options_failed', $req->postId, $e->code(), $e->getMessage());
            Logger::log('info', 'generation refused or failed', ['post_id' => $req->postId, 'user_id' => $req->userId, 'code' => $e->code()]);

            throw $e;
        }
    }

    private function doRun(GenerationRequest $req): GenerationResult
    {
        // Switches first: no work and no spend when generation is off.
        if (self::constantDisabled()) {
            throw new DisabledException('Suggestions are switched off by the site administrator.', ['reason' => 'constant']);
        }
        if (!$this->settings->bool('enabled')) {
            throw new DisabledException('Suggestions are switched off by the site administrator.', ['reason' => 'setting']);
        }
        $siteCap = $this->siteCap();
        $userCap = $this->userCap();
        if ($siteCap === 0 || ($req->userId !== null && $userCap === 0)) {
            throw new DisabledException('Suggestions are switched off by the site administrator.', ['reason' => 'cap_zero']);
        }
        $resolved = ($this->callerResolver)();
        $caller = $resolved['caller'];
        if (!$caller instanceof ModelCaller) {
            throw new NotConfiguredException(self::notConfiguredMessage($resolved['reason']), ['reason' => $resolved['reason']]);
        }

        foreach ([$req->title, $req->content, $req->excerpt] as $field) {
            if (!Utf8::isValid($field)) {
                throw new BadInputException('The text is not valid UTF-8.');
            }
        }
        [$min, $max] = \IndianOpinion\HeadlineOptions\Settings::RANGES['count'];
        $count = $req->count ?? $this->settings->int('count');
        if ($count < $min || $count > $max) {
            throw new BadInputException(sprintf('Ask for %d to %d candidates.', $min, $max));
        }

        // Case 10: what the model sees.
        $plain = $this->plain->render($req->content);
        $filtered = Hooks::filter('headline_options_input_text', $plain, $req->postId);
        $plain = is_string($filtered) ? $filtered : $plain;
        if (PlainText::wordCount($plain) < $this->settings->int('min_words')) {
            throw new TooShortException('Write a little more of the article first.');
        }
        $input = (new Trimmer($this->length))->trim($plain, $this->settings->int('input_limit'));
        $title = Utf8::collapseWhitespace(Utf8::decodeEntities($req->title));
        $excerpt = Utf8::collapseWhitespace(Utf8::decodeEntities(strip_tags($req->excerpt)));

        // Case 72: enough request time left?
        $remaining = ($this->timeRemaining)();
        $margin = 5.0;
        $minimum = 8.0;
        $deadline = $remaining === null ? 60.0 : min(60.0, $remaining - $margin);
        if ($deadline < $minimum) {
            throw new NoTimeException('Not enough time is left in this request to ask the model. Try again.');
        }

        $now = $this->clock->now()->getTimestamp();
        $this->lock->acquire($req->postId, (int) ceil($deadline) + 30, $now);
        try {
            try {
                $reservation = $this->usage->reserve($req->userId, $siteCap, $userCap, $this->settings->int('rate_per_minute'));
            } catch (CapReachedException $e) {
                if ($e->scope() !== CapReachedException::RATE && ($e->data()['first_refusal'] ?? false) === true) {
                    Hooks::action('headline_options_cap_reached', $e->scope(), $e->count(), $e->cap());
                }
                throw $e;
            }

            $languageName = $req->language;
            $prompt = $this->prompts->build(new PromptInput(
                $count,
                $languageName,
                $this->settings->range('length_pass')[1],
                $title,
                $excerpt,
                $input,
                $this->settings->string('style_note'),
            ));

            $route = $caller->describe();
            try {
                $reservationId = $this->meter->preSpend($route['provider'], $route['model'], $prompt->estimateTokens(), self::MAX_OUTPUT_TOKENS);
            } catch (MeterRefusedException $e) {
                $this->usage->release($req->userId, $reservation['day']);
                throw $e;
            }

            try {
                $result = $caller->call($prompt, OutputSchema::toArray(), $deadline);
            } catch (ProviderException $e) {
                if (!$e->attempted()) {
                    $this->usage->release($req->userId, $reservation['day']);
                    $this->meter->release($reservationId);
                } else {
                    $this->meter->record($reservationId, $route['provider'], $route['model'], $e->billed() ? 'error_billed' : 'error_unbilled', $e->billed() ? 200 : 0, 0, 0, null);
                }
                $this->errors->failure($e->code(), $now);
                throw $e;
            } catch (\Throwable $e) {
                // A custom caller broke its contract: count it (it may have spent) and fail closed.
                $this->meter->record($reservationId, $route['provider'], $route['model'], 'error_unbilled', 0, 0, 0, null);
                $this->errors->failure(ProviderException::FAILED, $now);
                throw new ProviderException('The model call failed.', ProviderException::FAILED, 'caller_error', true, false, $e);
            }
            $this->meter->record($reservationId, $result->provider, $result->model, 'ok', $result->httpStatus, $result->inputTokens, $result->outputTokens, $result->cost);
            $this->errors->success($now);

            $scorer = $this->scorers->create($req->language);
            $article = $this->scorers->article($plain, $req->language, $req->tags, $req->categories, hash('sha256', $req->content), self::entities($result->data));
            $processed = (new CandidateProcessor(new Cleaner(), $scorer))->process($result->data, $article, $title, $count);
            if ($processed['candidates'] === []) {
                $this->errors->failure('headline_options_no_candidates', $now);
                throw new NoCandidatesException('No usable headline came back from the model. Try again.');
            }
            $current = $title === '' ? null : $scorer->score($title, $article, null);
            $set = new CandidateSet(
                ($this->uuid)(),
                $req->postId,
                gmdate('Y-m-d\TH:i:s\Z', $now),
                $req->userId ?? 0,
                hash('sha256', $req->content),
                $req->language,
                $result->model,
                $prompt->hash(),
                $processed['candidates'],
                $count,
                $processed['returned'],
                $processed['notes'],
                $current,
            );
            $keep = Hooks::filter('headline_options_sets_kept', 5);
            $saved = $this->sets->add($req->postId, $set->toArray(), is_int($keep) && $keep > 0 ? $keep : 5);
            if (!$saved) {
                Logger::log('warning', 'candidate set not saved to post meta', ['post_id' => $req->postId]);
            }
            Hooks::action('headline_options_generated', $set);
            Logger::log('info', 'generated', [
                'post_id' => $req->postId,
                'user_id' => $req->userId,
                'returned' => $processed['returned'],
                'requested' => $count,
                'site_today' => $reservation['site'],
            ]);

            return new GenerationResult($set, $saved, [
                'site_today' => $reservation['site'],
                'site_cap' => $siteCap,
                'user_today' => $reservation['user'],
                'user_cap' => $userCap,
            ]);
        } finally {
            $this->lock->release($req->postId);
        }
    }

    public static function notConfiguredMessage(string $reason): string
    {
        return match ($reason) {
            'library_missing' => 'The model library indianopinion/llm-json-php is missing. Run composer install in the plugin folder, or install a release zip.',
            default => 'No model provider is set up.',
        };
    }

    /**
     * Names the model listed with its candidates, cleaned. Used only as extra
     * entity candidates, each checked against the article.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function entities(array $data): array
    {
        $out = [];
        $cleaner = new Cleaner();
        foreach (is_array($data['entities'] ?? null) ? $data['entities'] : [] as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = $cleaner->clean($name);
            if ($name !== '' && mb_strlen($name, 'UTF-8') <= 80 && !in_array($name, $out, true)) {
                $out[] = $name;
            }
            if (count($out) >= 30) {
                break;
            }
        }

        return $out;
    }

    private static function lowerOnly(mixed $filtered, int $setting): int
    {
        return is_int($filtered) ? max(0, min($filtered, $setting)) : $setting;
    }
}
