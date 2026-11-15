<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests;

use IndianOpinion\HeadlineOptions\Storage\MemoryOptionTable;
use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\WordPress\Services;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Testing\MockTransport;
use IndianOpinion\LlmJson\Testing\RecordingSleeper;
use IndianOpinion\LlmJson\Transport\HttpResponse;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MemoryOptionTable $table;
    protected FixedClock $clock;
    protected MockTransport $transport;
    protected ?float $timeLeft = null;

    protected function setUp(): void
    {
        parent::setUp();
        WpState::reset();
        \WP_CLI::reset();
        $this->table = new MemoryOptionTable();
        $this->clock = new FixedClock();
        $this->transport = new MockTransport();
        $this->timeLeft = null;
        Services::set(new Services($this->table, $this->clock, fn (): ?float => $this->timeLeft, static function (int $us): void {
        }));
        $transport = $this->transport;
        add_filter('headline_options_llm_client', static function (Client $client) use ($transport): Client {
            return $client->withTransport($transport)->withSleeper(new RecordingSleeper());
        });
    }

    protected function tearDown(): void
    {
        Services::set(null);
        parent::tearDown();
    }

    /** Settings with generation on, a key and a model. */
    protected function configure(array $overrides = []): void
    {
        update_option('headline_options_settings', array_merge([
            'enabled' => true,
            'models' => ['test/model-a'],
        ], $overrides));
        update_option('headline_options_key', 'test-key-123456');
    }

    /** A reply with the given headlines, as the model would send it. */
    protected function reply(array $headlines, array $entities = []): HttpResponse
    {
        $candidates = [];
        foreach ($headlines as $h) {
            $candidates[] = is_array($h) ? $h : ['headline' => $h, 'angle' => 'the news'];
        }

        return MockTransport::completion(['candidates' => $candidates, 'entities' => $entities]);
    }

    protected function article(int $words = 60): string
    {
        $base = 'The Reserve Bank of India kept the repo rate at 6.5 per cent on Wednesday. Governor Sanjay Malhotra said inflation had eased to 4.2 per cent in October. '
            . 'He said the bank would watch food prices closely. Twelve economists polled earlier had expected no change. ';
        $text = '';
        while (str_word_count($text) < $words) {
            $text .= $base;
        }
        $all = explode(' ', trim($text));

        return '<!-- wp:paragraph --><p>' . implode(' ', array_slice($all, 0, $words)) . '</p><!-- /wp:paragraph -->';
    }

    protected function editorPost(int $id = 10, string $title = 'Repo rate unchanged', string $type = 'post'): \WP_Post
    {
        $post = WpState::addPost($id, $title, $this->article(), $type);
        WpState::loginEditor(7, [$id]);

        return $post;
    }

    /**
     * Run permission callback then handler, as the REST server would.
     */
    protected function dispatch(string $method, string $path, array $params = [], ?string $body = null): \WP_REST_Response
    {
        $controller = new \IndianOpinion\HeadlineOptions\WordPress\RestController();
        $controller->register();
        foreach (WpState::$routes as $pattern => $args) {
            $regex = '#^' . str_replace('headline-options/v1', '/headline-options/v1', $pattern) . '$#';
            if (($args['methods'] ?? '') !== $method || preg_match($regex, $path, $m) !== 1) {
                continue;
            }
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[$k] = $v;
                }
            }
            $request = new \WP_REST_Request($method, $path, $params, $body ?? (string) json_encode($params));
            $allowed = ($args['permission_callback'])($request);
            if ($allowed instanceof \WP_Error) {
                return new \WP_REST_Response(['code' => $allowed->code, 'message' => $allowed->message, 'data' => $allowed->data], (int) $allowed->data['status']);
            }

            return ($args['callback'])($request);
        }
        self::fail('No route for ' . $method . ' ' . $path);
    }

    protected function request(string $method, string $route, array $params = [], string $body = ''): \WP_REST_Request
    {
        return new \WP_REST_Request($method, $route, $params, $body === '' ? (string) json_encode($params) : $body);
    }
}
