<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Integration;

use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Tests\TestCase;
use IndianOpinion\HeadlineOptions\WordPress\Editor;

/**
 * T1, T2, T3, T32, T69, T71: who sees what, and what leaves the server.
 */
final class AccessTest extends TestCase
{
    public function testT1PanelForPostNotPage(): void
    {
        $this->configure();
        WpState::loginEditor();
        self::assertTrue(Editor::enabledFor('post'));
        self::assertFalse(Editor::enabledFor('page'));

        $editor = new Editor(dirname(__DIR__, 2) . '/headline-options.php');
        WpState::$screenPostType = 'post';
        $editor->blockAssets();
        self::assertContains('headline-options-panel', WpState::$scripts);
        WpState::$scripts = [];
        WpState::$screenPostType = 'page';
        $editor->blockAssets();
        self::assertSame([], WpState::$scripts);

        $editor->metaBox('page', null);
        self::assertSame([], WpState::$metaBoxes);
        $editor->metaBox('post', null);
        self::assertSame(['headline-options:post'], WpState::$metaBoxes);

        $this->configure(['post_types' => ['post', 'page']]);
        self::assertTrue(Editor::enabledFor('page'));
    }

    public function testT2EditPostsButNotThisPostGets403(): void
    {
        $this->configure();
        WpState::addPost(10, 'Title', $this->article());
        WpState::loginEditor(7, []);
        foreach (['generate', 'score'] as $route) {
            $r = $this->dispatch('POST', '/headline-options/v1/posts/10/' . $route, ['headlines' => ['x'], 'content' => 'y']);
            self::assertSame(403, $r->status, $route);
        }
        self::assertSame([], $this->transport->requests());
    }

    public function testT3NotLoggedInGets401(): void
    {
        $this->configure();
        WpState::addPost(10, 'Title', $this->article());
        foreach ([['POST', 'generate'], ['POST', 'score'], ['GET', 'sets'], ['POST', 'choice']] as [$m, $route]) {
            self::assertSame(401, $this->dispatch($m, '/headline-options/v1/posts/10/' . $route)->status);
        }
        self::assertSame(401, $this->dispatch('GET', '/headline-options/v1/status')->status);
    }

    public function testT32ScoreAcceptsTwentyRejectsTwentyOne(): void
    {
        $this->editorPost();
        $twenty = array_fill(0, 20, 'Reserve Bank holds rates');
        $ok = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => $twenty, 'content' => 'The Reserve Bank held rates.']);
        self::assertSame(200, $ok->status);
        self::assertCount(20, $ok->data);
        $bad = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => array_fill(0, 21, 'x'), 'content' => 'y']);
        self::assertSame(400, $bad->status);
        self::assertSame([], $this->transport->requests());
    }

    public function testT69ScoreTextOverThousandRejected(): void
    {
        $this->editorPost();
        $r = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => [str_repeat('a', 1001)], 'content' => 'y']);
        self::assertSame(400, $r->status);
        self::assertSame('The text is too long to score.', $r->data['message']);
    }

    public function testT64ScoreRouteDecodesEntities(): void
    {
        $this->editorPost();
        $r = $this->dispatch('POST', '/headline-options/v1/posts/10/score', ['headlines' => ['Tata &amp; Sons'], 'content' => 'Tata &amp; Sons']);
        self::assertSame('Tata & Sons', $r->data[0]['headline']);
    }

    public function testT71LogsAndStatusCarryNoTextOrKey(): void
    {
        $this->configure();
        $this->editorPost();
        $logs = [];
        add_action('headline_options_log', static function (string $level, string $message, array $context) use (&$logs): void {
            $logs[] = $message . ' ' . json_encode($context);
        });
        $this->transport->push($this->reply(['Reserve Bank holds repo rate at 6.5%']));
        $this->dispatch('POST', '/headline-options/v1/posts/10/generate', ['title' => 'Secret draft title', 'content' => WpState::$posts[10]->post_content]);
        $this->transport->push(\IndianOpinion\LlmJson\Testing\MockTransport::status(500), \IndianOpinion\LlmJson\Testing\MockTransport::status(500));
        $this->dispatch('POST', '/headline-options/v1/posts/10/generate', ['title' => 'Secret draft title', 'content' => WpState::$posts[10]->post_content]);
        $status = json_encode($this->dispatch('GET', '/headline-options/v1/status')->data);
        $all = implode("\n", $logs) . "\n" . $status . "\n" . json_encode(get_option('headline_options_last_error'));
        self::assertNotEmpty($logs);
        foreach (['Secret draft title', 'Reserve Bank holds', 'Malhotra', 'test-key-123456'] as $needle) {
            self::assertStringNotContainsString($needle, $all);
        }
        self::assertStringContainsString('"length_unit"', $status);
        self::assertStringContainsString('"script_check":true', $status);
    }

    public function testStatusRoute(): void
    {
        $this->configure();
        WpState::loginEditor();
        $s = $this->dispatch('GET', '/headline-options/v1/status')->data;
        self::assertTrue($s['enabled']);
        self::assertTrue($s['configured']);
        self::assertSame(['site_today' => 0, 'site_cap' => 50, 'user_today' => 0, 'user_cap' => 15], $s['usage']);
    }
}
