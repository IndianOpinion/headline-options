<?php

/**
 * Public PHP functions (PRD 5.2). Stable from 0.1.0.
 */

declare(strict_types=1);

use IndianOpinion\HeadlineOptions\Generation\CandidateSet;
use IndianOpinion\HeadlineOptions\Generation\GenerationRequest;
use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;
use IndianOpinion\HeadlineOptions\WordPress\PostContext;
use IndianOpinion\HeadlineOptions\WordPress\Services;

if (!function_exists('headline_options_score')) {
    /**
     * Score one headline. No model call, no cap.
     *
     * @param array{current?: string, tags?: list<string>, categories?: list<string>, language?: string} $context
     */
    function headline_options_score(string $headline, string $content, array $context = []): ScoreResult
    {
        return headline_options_score_many([$headline], $content, $context)[0];
    }

    /**
     * Score several headlines against one article, in the same order.
     *
     * @param list<string>                                                                               $headlines
     * @param array{current?: string, tags?: list<string>, categories?: list<string>, language?: string} $context
     * @return list<ScoreResult>
     */
    function headline_options_score_many(array $headlines, string $content, array $context = []): array
    {
        $services = Services::instance();
        $language = is_string($context['language'] ?? null) ? $context['language'] : (function_exists('get_locale') ? get_locale() : 'en_US');
        $factory = $services->scorerFactory();
        $scorer = $factory->create($language);
        $article = $factory->article(
            $services->plainText()->render($content),
            $language,
            array_values(array_filter($context['tags'] ?? [], 'is_string')),
            array_values(array_filter($context['categories'] ?? [], 'is_string')),
        );
        $current = is_string($context['current'] ?? null) ? $context['current'] : null;
        $out = [];
        foreach ($headlines as $h) {
            $out[] = $scorer->score((string) $h, $article, $current);
        }

        return $out;
    }

    /**
     * Generate candidates with the same checks, caps and hooks as the REST
     * route. Throws typed exceptions. Never writes the title.
     *
     * @param array{title?: string, content?: string, excerpt?: string} $state
     * @param array{count?: int, user_id?: int|null}                    $args
     */
    function headline_options_generate(int $post_id, array $state, array $args = []): CandidateSet
    {
        $post = PostContext::resolve($post_id);
        if (array_key_exists('user_id', $args)) {
            $userId = $args['user_id'];
        } else {
            $current = function_exists('get_current_user_id') ? get_current_user_id() : 0;
            $userId = $current > 0 ? $current : null;
            if ($userId !== null) {
                // Same checks as the REST route for a signed-in user.
                PostContext::assertCanUse($post);
            }
        }
        $req = new GenerationRequest(
            $post->ID,
            (string) ($state['title'] ?? $post->post_title),
            (string) ($state['content'] ?? $post->post_content),
            (string) ($state['excerpt'] ?? $post->post_excerpt),
            isset($args['count']) ? (int) $args['count'] : null,
            $userId,
            PostContext::language($post->ID),
            PostContext::termNames($post->ID, 'post_tag'),
            PostContext::termNames($post->ID, 'category'),
        );

        return Services::instance()->generator()->run($req)->set;
    }

    /** Fresh read of the switch, the constant, the key and the caller. */
    function headline_options_is_enabled(): bool
    {
        return Services::instance()->generator()->availability()['enabled'];
    }

    /**
     * Today's counts and caps for the current user.
     *
     * @return array{site_today: int, site_cap: int, user_today: int, user_cap: int}
     */
    function headline_options_usage(): array
    {
        $uid = function_exists('get_current_user_id') ? get_current_user_id() : 0;

        return Services::instance()->generator()->usage($uid > 0 ? $uid : null);
    }
}
