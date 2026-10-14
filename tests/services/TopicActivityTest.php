<?php

namespace YesWiki\Test\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use YesWiki\Discourse\Service\ActivityPresenter;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Discourse\Service\TopicActivity;
use YesWiki\Kernel\Service\Journal;
use YesWiki\Render\Service\TemplateEngine;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** A topic's activity: read off Discourse, kept in cache, drawn as weekly bars. */
class TopicActivityTest extends YesWikiTestCase
{
    private string $cacheDir = '';
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
        $this->cacheDir = sys_get_temp_dir() . '/discourse-activity-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/*.json') ?: []);
            rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public static function topicJson(): array
    {
        return [
            'posts_count' => 8,
            'views' => 120,
            'like_count' => 4,
            'participant_count' => 3,
            'last_posted_at' => '2026-10-14T09:00:00.000Z',
            'post_stream' => ['stream' => [11, 12, 13, 14, 15, 16, 17, 18]],
            'timeline_lookup' => [[1, 30], [2, 10], [5, 3], [6, 0]],
        ];
    }

    /** @param list<MockResponse> $responses */
    private function api(array $responses): DiscourseApiService
    {
        $this->requests = 0;
        $client = new MockHttpClient(function () use (&$responses): MockResponse {
            $this->requests++;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DiscourseApiService(new ParameterBag(['discourse_connect' => [
            'url' => 'https://forum.example.org',
            'api_key' => 'k3y',
        ]]), $client);
    }

    private function activity(DiscourseApiService $api): TopicActivity
    {
        $services = $this->getWiki()->services;

        return new TopicActivity(
            $api,
            new ParameterBag(['discourse_connect' => ['url' => 'https://forum.example.org', 'api_key' => 'k3y']]),
            $services->get(Journal::class),
            $this->cacheDir
        );
    }

    public function testEachDayHoldsThePostsUpToTheNextIndex(): void
    {
        $this->assertSame(
            [30 => 1, 10 => 3, 3 => 1, 0 => 3],
            DiscourseApiService::postsByDaysAgo([[1, 30], [2, 10], [5, 3], [6, 0]], 8)
        );
        $this->assertSame([], DiscourseApiService::postsByDaysAgo([], 0));
        $this->assertSame([0 => 1], DiscourseApiService::postsByDaysAgo([[1, 0], 'junk'], 1));
    }

    public function testTheTopicCountsRepliesWithoutTheFirstPost(): void
    {
        $topic = $this->api([new MockResponse(json_encode(self::topicJson()))])->topic('42');

        $this->assertSame(7, $topic['replies']);
        $this->assertSame(120, $topic['views']);
        $this->assertSame([30 => 1, 10 => 3, 3 => 1, 0 => 3], $topic['postsByDaysAgo']);
    }

    public function testAPageOfCardsAsksDiscourseOnce(): void
    {
        $activity = $this->activity($this->api([new MockResponse(json_encode(self::topicJson()))]));

        $this->assertNull($activity->get('42', false));
        $this->assertSame(7, $activity->get('42')['replies'] ?? null);
        $this->assertSame(7, $activity->get('42')['replies'] ?? null);
        $this->assertTrue($activity->isCached('42'));
        $this->assertSame(1, $this->requests);
        $this->assertSame([30 => 1, 10 => 3, 3 => 1, 0 => 3], $activity->get('42')['postsByDaysAgo']);
    }

    public function testAFailureIsRememberedForAWhileToo(): void
    {
        $activity = $this->activity($this->api([new MockResponse('', ['http_code' => 404])]));

        $this->assertNull($activity->get('42'));
        $this->assertNull($activity->get('42'));
        $this->assertSame(1, $this->requests);
    }

    public function testATopicIdThatIsNotANumberIsNeverAskedFor(): void
    {
        $activity = $this->activity($this->api([]));

        $this->assertNull($activity->get('../42'));
        $this->assertSame(0, $this->requests);
    }

    public function testPostsFallIntoRollingWeeksOldestFirst(): void
    {
        $presenter = new ActivityPresenter($this->getWiki()->services->get(TemplateEngine::class));

        $this->assertSame(
            [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 3, 4],
            $presenter->weeks([30 => 1, 10 => 3, 3 => 1, 0 => 3, 200 => 9])
        );
    }

    public function testTheSparklineHasABarPerWeekAndSaysWhatItShows(): void
    {
        $presenter = new ActivityPresenter($this->getWiki()->services->get(TemplateEngine::class));
        $svg = $presenter->sparkline(['postsByDaysAgo' => [30 => 1, 10 => 3, 3 => 1, 0 => 3], 'fetchedAt' => time()]);

        $this->assertSame(12, substr_count($svg, '<rect '));
        $this->assertStringContainsString('fill="currentColor"', $svg);
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString(htmlspecialchars(_t('DISCOURSE_ACTIVITY_SPARKLINE_LABEL', ['posts' => 8, 'weeks' => 12, 'last' => 4])), $svg);
        $this->assertSame(1, substr_count($svg, 'opacity="1"'));

        $quiet = $presenter->sparkline(['postsByDaysAgo' => []]);
        $this->assertSame(12, substr_count($quiet, 'height="1"'));
    }

    public function testTheFragmentShowsTheRepliesNextToTheSparkline(): void
    {
        $presenter = new ActivityPresenter($this->getWiki()->services->get(TemplateEngine::class));
        $html = $presenter->fragment(['replies' => 7, 'postsByDaysAgo' => [0 => 3]]);

        $this->assertStringContainsString(_t('DISCOURSE_ACTIVITY_REPLIES', ['n' => 7]), $html);
        $this->assertStringContainsString('<svg class="discourse-sparkline"', $html);
    }
}
