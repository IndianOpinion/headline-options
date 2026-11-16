<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Exception\BadInputException;
use IndianOpinion\HeadlineOptions\Exception\CapReachedException;
use IndianOpinion\HeadlineOptions\Exception\HeadlineOptionsException;
use IndianOpinion\HeadlineOptions\Exception\TooLargeException;
use IndianOpinion\HeadlineOptions\Generation\GenerationRequest;
use IndianOpinion\HeadlineOptions\Hooks;
use IndianOpinion\HeadlineOptions\Logger;
use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ScriptMatchRule;
use IndianOpinion\HeadlineOptions\Storage\SetStore;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * REST routes under `headline-options/v1`. Every route needs a logged-in user
 * (cookie auth with the REST nonce, or an application password), the use
 * capability and `edit_post` for the post. Responses send
 * `Cache-Control: no-store`.
 */
final class RestController
{
    public const NS = 'headline-options/v1';
    public const MAX_BODY = 1048576;
    public const MAX_SCORE_HEADLINES = 20;
    public const MAX_SCORE_LENGTH = 1000;

    public function register(): void
    {
        $post = '/posts/(?P<id>\d+)';
        register_rest_route(self::NS, $post . '/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate'],
            'permission_callback' => [$this, 'canUsePost'],
        ]);
        register_rest_route(self::NS, $post . '/score', [
            'methods' => 'POST',
            'callback' => [$this, 'score'],
            'permission_callback' => [$this, 'canUsePost'],
        ]);
        register_rest_route(self::NS, $post . '/sets', [
            'methods' => 'GET',
            'callback' => [$this, 'sets'],
            'permission_callback' => [$this, 'canUsePost'],
        ]);
        register_rest_route(self::NS, $post . '/choice', [
            'methods' => 'POST',
            'callback' => [$this, 'choice'],
            'permission_callback' => [$this, 'canUsePost'],
        ]);
        register_rest_route(self::NS, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => [$this, 'canUse'],
        ]);
    }

    /**
     * @return true|\WP_Error
     */
    public function canUse(\WP_REST_Request $request): bool|\WP_Error
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('rest_not_logged_in', 'You are not signed in.', ['status' => 401]);
        }
        if (!current_user_can(PostContext::capability())) {
            return new \WP_Error('headline_options_forbidden', 'You cannot use headline options.', ['status' => 403]);
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    public function canUsePost(\WP_REST_Request $request): bool|\WP_Error
    {
        $base = $this->canUse($request);
        if ($base !== true) {
            return $base;
        }
        try {
            // Case 71: refuse an oversize body before anything parses it.
            if (strlen((string) $request->get_body()) > self::MAX_BODY) {
                throw new TooLargeException('The request is too large.');
            }
            $post = PostContext::resolve((int) $request->get_param('id'));
            PostContext::assertCanUse($post);
        } catch (HeadlineOptionsException $e) {
            return new \WP_Error($e->code(), $e->getMessage(), ['status' => $e->httpStatus()]);
        }

        return true;
    }

    public function generate(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post = PostContext::resolve((int) $request->get_param('id'));
            $count = $request->get_param('count');
            if ($count !== null && !(is_int($count) || (is_string($count) && ctype_digit($count)))) {
                throw new BadInputException('count must be a whole number.');
            }
            $userId = get_current_user_id();
            $req = new GenerationRequest(
                $post->ID,
                self::stringParam($request, 'title', 2000),
                self::stringParam($request, 'content', self::MAX_BODY),
                self::stringParam($request, 'excerpt', 5000),
                $count === null ? null : (int) $count,
                $userId,
                PostContext::language($post->ID),
                PostContext::termNames($post->ID, 'post_tag'),
                PostContext::termNames($post->ID, 'category'),
            );
            $result = Services::instance()->generator()->run($req);

            return self::ok([
                'set' => $result->set->toArray(),
                'notes' => $result->set->notes,
                'saved' => $result->saved,
                'usage' => $result->usage,
            ]);
        } catch (HeadlineOptionsException $e) {
            return self::error($e);
        }
    }

    public function score(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post = PostContext::resolve((int) $request->get_param('id'));
            $headlines = $request->get_param('headlines');
            if (!is_array($headlines) || $headlines === [] || count($headlines) > self::MAX_SCORE_HEADLINES) {
                throw new BadInputException(sprintf('Send 1 to %d headlines.', self::MAX_SCORE_HEADLINES));
            }
            $clean = [];
            foreach ($headlines as $h) {
                if (!is_string($h) || !Utf8::isValid($h)) {
                    throw new BadInputException('Each headline must be valid UTF-8 text.');
                }
                if (mb_strlen($h, 'UTF-8') > self::MAX_SCORE_LENGTH) {
                    throw new BadInputException('The text is too long to score.');
                }
                $clean[] = $h;
            }
            $content = self::stringParam($request, 'content', self::MAX_BODY);
            $current = $request->get_param('current');
            $current = is_string($current) && Utf8::isValid($current) ? $current : null;
            $results = self::scoreMany($post->ID, $clean, $content, $current);

            return self::ok(array_map(static fn (ScoreResult $r): array => $r->toArray(), $results));
        } catch (HeadlineOptionsException $e) {
            return self::error($e);
        }
    }

    /**
     * @param list<string> $headlines
     * @return list<ScoreResult>
     */
    public static function scoreMany(int $postId, array $headlines, string $content, ?string $current): array
    {
        $services = Services::instance();
        $language = PostContext::language($postId);
        $factory = $services->scorerFactory();
        $scorer = $factory->create($language);
        $plain = $services->plainText()->render($content);
        $article = $factory->article($plain, $language, PostContext::termNames($postId, 'post_tag'), PostContext::termNames($postId, 'category'));
        $out = [];
        foreach ($headlines as $h) {
            $out[] = $scorer->score($h, $article, $current);
        }

        return $out;
    }

    public function sets(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post = PostContext::resolve((int) $request->get_param('id'));
        } catch (HeadlineOptionsException $e) {
            return self::error($e);
        }
        $hash = $request->get_param('content_hash');
        $hash = is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
        $sets = (new SetStore())->all($post->ID);
        foreach ($sets as $i => $set) {
            $sets[$i]['stale'] = $hash === null ? null : ($set['content_hash'] ?? '') !== $hash;
        }

        return self::ok($sets);
    }

    public function choice(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $post = PostContext::resolve((int) $request->get_param('id'));
            $setId = $request->get_param('set_id');
            $index = $request->get_param('index');
            if (!is_string($setId) || preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $setId) !== 1) {
                throw new BadInputException('set_id is not valid.');
            }
            if (!(is_int($index) || (is_string($index) && ctype_digit($index))) || (int) $index > 20) {
                throw new BadInputException('index is not valid.');
            }
        } catch (HeadlineOptionsException $e) {
            return self::error($e);
        }
        $userId = get_current_user_id();
        $recorded = (new SetStore())->choose($post->ID, $setId, (int) $index, $userId, gmdate('Y-m-d\TH:i:s\Z', Services::instance()->clock()->now()->getTimestamp()));
        if ($recorded) {
            Hooks::action('headline_options_chosen', $post->ID, $setId, (int) $index, $userId);
            Logger::log('info', 'choice recorded', ['post_id' => $post->ID, 'user_id' => $userId, 'index' => (int) $index]);
        }

        return self::ok(['recorded' => $recorded]);
    }

    public function status(\WP_REST_Request $request): \WP_REST_Response
    {
        return self::ok(self::statusData(get_current_user_id()));
    }

    /**
     * Shared by `/status` and `wp headline-options status`. Holds no text and no key.
     *
     * @return array<string, mixed>
     */
    public static function statusData(?int $userId): array
    {
        $services = Services::instance();
        $generator = $services->generator();
        $availability = $generator->availability();
        $errors = $services->errorLog()->state();

        return [
            'enabled' => $availability['enabled'],
            'configured' => $availability['configured'],
            'reason' => $availability['reason'],
            'constant_disabled' => $generator::constantDisabled(),
            'usage' => $generator->usage($userId),
            'last_error' => ['code' => $errors['code'], 'time' => $errors['time']],
            'length_unit' => $services->length()->unit(),
            'script_check' => ScriptMatchRule::available(),
        ];
    }

    /**
     * @param mixed $data
     */
    public static function ok($data): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, 200);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function error(HeadlineOptionsException $e): \WP_REST_Response
    {
        $response = new \WP_REST_Response([
            'code' => $e->code(),
            'message' => $e->getMessage(),
            'data' => ['status' => $e->httpStatus()] + $e->data(),
        ], $e->httpStatus());
        $response->header('Cache-Control', 'no-store');
        if ($e instanceof CapReachedException && $e->retryAfter() !== null) {
            $response->header('Retry-After', (string) $e->retryAfter());
        }

        return $response;
    }

    private static function stringParam(\WP_REST_Request $request, string $name, int $maxBytes): string
    {
        $value = $request->get_param($name);
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new BadInputException(sprintf('%s must be text.', $name));
        }
        if (!Utf8::isValid($value)) {
            throw new BadInputException('The text is not valid UTF-8.');
        }
        if (strlen($value) > $maxBytes) {
            throw new BadInputException(sprintf('%s is too long.', $name));
        }

        return $value;
    }
}
