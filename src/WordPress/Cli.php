<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\WordPress;

use IndianOpinion\HeadlineOptions\Exception\CapReachedException;
use IndianOpinion\HeadlineOptions\Exception\DisabledException;
use IndianOpinion\HeadlineOptions\Exception\HeadlineOptionsException;
use IndianOpinion\HeadlineOptions\Generation\GenerationRequest;
use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;
use IndianOpinion\HeadlineOptions\Storage\SetStore;

/**
 * Operate headline options from WP-CLI.
 *
 * Exit codes: 0 success, 1 error, 3 disabled, 4 capped.
 */
final class Cli
{
    public const EXIT_DISABLED = 3;
    public const EXIT_CAPPED = 4;

    /**
     * Show the switch, constant, key presence, caps, today's counts, last error, length unit and script check.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $data = RestController::statusData(null);
        $data['key_present'] = Services::instance()->providerKey() !== '';
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode($data));

            return;
        }
        foreach (self::flatten($data) as $key => $value) {
            \WP_CLI::line(sprintf('%-22s %s', $key, $value));
        }
    }

    /**
     * Score a headline with no model call.
     *
     * <headline>
     * : The headline to score.
     *
     * [--post=<id>]
     * : Score against this post's saved content, tags and categories.
     *
     * [--content-file=<file>]
     * : Score against the text of this file.
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function score(array $args, array $assoc): void
    {
        $headline = (string) ($args[0] ?? '');
        $postId = isset($assoc['post']) ? (int) $assoc['post'] : 0;
        $content = '';
        if (isset($assoc['content-file'])) {
            $file = (string) $assoc['content-file'];
            if (!is_readable($file) || !is_file($file)) {
                \WP_CLI::error('Cannot read the content file.');
            }
            $content = (string) file_get_contents($file);
        } elseif ($postId > 0) {
            try {
                $content = PostContext::resolve($postId)->post_content;
            } catch (HeadlineOptionsException $e) {
                \WP_CLI::error($e->getMessage());
            }
        }
        $results = RestController::scoreMany($postId, [$headline], $content, null);
        self::printScores($results, $assoc['format'] ?? 'table');
    }

    /**
     * Generate candidates from the saved post. Counted against the site cap. Never writes the title.
     *
     * <post-id>
     * : The post.
     *
     * [--count=<n>]
     * : Candidates to ask for (3 to 10).
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        try {
            $post = PostContext::resolve((int) ($args[0] ?? 0));
            $req = new GenerationRequest(
                $post->ID,
                $post->post_title,
                $post->post_content,
                $post->post_excerpt,
                isset($assoc['count']) ? (int) $assoc['count'] : null,
                null,
                PostContext::language($post->ID),
                PostContext::termNames($post->ID, 'post_tag'),
                PostContext::termNames($post->ID, 'category'),
            );
            $result = Services::instance()->generator()->run($req);
        } catch (DisabledException $e) {
            \WP_CLI::warning($e->getMessage());
            \WP_CLI::halt(self::EXIT_DISABLED);
        } catch (CapReachedException $e) {
            \WP_CLI::warning($e->getMessage());
            \WP_CLI::halt(self::EXIT_CAPPED);
        } catch (HeadlineOptionsException $e) {
            \WP_CLI::error($e->code() . ': ' . $e->getMessage());

            return;
        }
        if (($assoc['format'] ?? 'table') === 'json') {
            \WP_CLI::line((string) wp_json_encode(['set' => $result->set->toArray(), 'saved' => $result->saved, 'usage' => $result->usage]));

            return;
        }
        foreach ($result->set->candidates as $i => $c) {
            \WP_CLI::line(sprintf('%2d  %3s  %-5s  %s', $i, $c->score->total ?? '-', $c->score->band ?? '-', $c->text));
        }
        foreach ($result->set->notes as $note) {
            \WP_CLI::line('note: ' . $note['message']);
        }
    }

    /**
     * List stored sets for a post, newest first.
     *
     * <post-id>
     * : The post.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function sets(array $args, array $assoc): void
    {
        try {
            $post = PostContext::resolve((int) ($args[0] ?? 0));
        } catch (HeadlineOptionsException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        foreach ((new SetStore())->all($post->ID) as $set) {
            $candidates = is_array($set['candidates'] ?? null) ? $set['candidates'] : [];
            \WP_CLI::line(sprintf(
                '%s  %s  %d candidates  chosen=%s',
                is_string($set['id'] ?? null) ? $set['id'] : '?',
                is_string($set['created_at'] ?? null) ? $set['created_at'] : '?',
                count($candidates),
                isset($set['chosen_index']) && is_int($set['chosen_index']) ? (string) $set['chosen_index'] : '-',
            ));
        }
    }

    /**
     * Zero today's counters.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function reset_usage(array $args, array $assoc): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        \WP_CLI::confirm('Zero today\'s generation counters?', $assoc);
        try {
            Services::instance()->usageStore()->reset();
        } catch (HeadlineOptionsException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI::success('Counters zeroed.');
    }

    /**
     * @param list<ScoreResult> $results
     */
    private static function printScores(array $results, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode(array_map(static fn (ScoreResult $r): array => $r->toArray(), $results)));

            return;
        }
        foreach ($results as $r) {
            \WP_CLI::line(sprintf('total %s (%s), %d %s, %d words', $r->total ?? '-', $r->band ?? '-', $r->length, $r->lengthUnit, $r->words));
            foreach ($r->results as $rule) {
                \WP_CLI::line(sprintf('  %-18s %-4s %2d/%-2d %s', $rule->ruleId, $rule->status->value, $rule->earned, $rule->possible, $rule->reason));
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value)) {
                $out += self::flatten($value, $name . '.');
            } else {
                $out[$name] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) (is_scalar($value) ? $value : '-');
            }
        }

        return $out;
    }
}
