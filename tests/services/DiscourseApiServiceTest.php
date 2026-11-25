<?php

namespace YesWiki\Test\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use YesWiki\Discourse\Exception\TopicRefusedException;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** Requests sent to the Discourse API, against a fake Discourse. */
class DiscourseApiServiceTest extends YesWikiTestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
    }

    /** @param list<MockResponse> $responses */
    private function service(array $responses, string $url = 'https://forum.example.org/'): DiscourseApiService
    {
        $this->sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DiscourseApiService(new ParameterBag(['discourse_connect' => [
            'url' => $url,
            'api_key' => 'k3y',
            'api_username' => 'wikibot',
        ]]), $client);
    }

    /** @param list<string> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    public function testWithoutUrlOrKeyItIsNotConfigured(): void
    {
        $this->assertTrue($this->service([])->isConfigured());
        $this->assertFalse($this->service([], '')->isConfigured());
        $this->assertFalse((new DiscourseApiService(new ParameterBag([])))->isConfigured());
    }

    public function testTheTopicIsPostedAsTheAuthorsDiscourseAccount(): void
    {
        $topic = $this->service([
            new MockResponse(json_encode(['user' => ['username' => 'jane_doe']])),
            new MockResponse(json_encode(['id' => 7, 'topic_id' => 42, 'topic_slug' => 'atelier-velo'])),
        ])->createTopic('Atelier vélo du samedi', 'Discussion', '5', 'JaneDoe');

        $this->assertSame(['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/atelier-velo/42'], $topic);
        $this->assertSame('https://forum.example.org/u/by-external/JaneDoe.json', $this->sent[0]['url']);
        $post = $this->sent[1];
        $this->assertSame('POST', $post['method']);
        $this->assertSame('https://forum.example.org/posts.json', $post['url']);
        $this->assertSame('jane_doe', $this->header($post['options']['headers'], 'Api-Username'));
        $this->assertSame('k3y', $this->header($post['options']['headers'], 'Api-Key'));
        $this->assertSame(['title' => 'Atelier vélo du samedi', 'raw' => 'Discussion', 'category' => 5], json_decode($post['options']['body'], true));
    }

    public function testAnAuthorWithoutDiscourseAccountFallsBackToTheConfiguredOne(): void
    {
        $service = $this->service([
            new MockResponse('{"errors":["not found"]}', ['http_code' => 404]),
            new MockResponse(json_encode(['topic_id' => 3, 'topic_slug' => 's'])),
        ]);
        $service->createTopic('Un titre assez long', 'Discussion', '', 'Nobody');

        $this->assertSame('wikibot', $this->header($this->sent[1]['options']['headers'], 'Api-Username'));
        $this->assertArrayNotHasKey('category', json_decode($this->sent[1]['options']['body'], true));
    }

    public function testAnAnonymousAuthorDoesNotLookAnyoneUp(): void
    {
        $this->service([new MockResponse(json_encode(['topic_id' => 3, 'topic_slug' => 's']))])
            ->createTopic('Un titre assez long', 'Discussion', '', '');

        $this->assertCount(1, $this->sent);
        $this->assertSame('wikibot', $this->header($this->sent[0]['options']['headers'], 'Api-Username'));
    }

    public function testTagsAreSetByTheConfiguredAccount(): void
    {
        $this->service([new MockResponse('{}')])->tagTopic('42', ['atelier', 'vélo']);

        $this->assertSame('PUT', $this->sent[0]['method']);
        $this->assertSame('https://forum.example.org/t/-/42.json', $this->sent[0]['url']);
        $this->assertSame('wikibot', $this->header($this->sent[0]['options']['headers'], 'Api-Username'));
        $this->assertSame(['tags' => ['atelier', 'vélo']], json_decode($this->sent[0]['options']['body'], true));
    }

    public function testRefusedTagsThrowDiscoursesMessage(): void
    {
        $this->expectExceptionMessage('You cannot create tags');
        $this->service([new MockResponse('{"errors":["You cannot create tags"]}', ['http_code' => 403])])->tagTopic('42', ['nouvelle']);
    }

    public function testTheForumsTagsAreListedByName(): void
    {
        $tags = $this->service([new MockResponse(json_encode(['tags' => [['id' => 1, 'name' => 'team'], ['id' => 2, 'text' => 'atelier'], ['id' => 3]]]))])->tags();

        $this->assertSame(['team', 'atelier'], $tags);
        $this->assertSame('https://forum.example.org/tags.json', $this->sent[0]['url']);
    }

    public function testARefusedTopicThrowsDiscoursesMessage(): void
    {
        $service = $this->service([
            new MockResponse('{"errors":["Title is too short (minimum is 15 characters)"]}', ['http_code' => 422]),
        ]);

        $this->expectException(TopicRefusedException::class);
        $this->expectExceptionMessage('Title is too short');
        $service->createTopic('Court', 'Discussion', '', '');
    }

    public function testAForumThatFailsIsNotARefusal(): void
    {
        try {
            $this->service([new MockResponse('', ['http_code' => 502])])->createTopic('Un titre assez long', 'Discussion', '', '');
            $this->fail('a 502 should throw');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(TopicRefusedException::class, $e);
        }
    }

    public function testCategoriesComeFromTheSiteWithTheirParentsName(): void
    {
        $categories = $this->service([new MockResponse(json_encode(['categories' => [
            ['id' => 4, 'name' => 'Général'],
            ['id' => 5, 'name' => 'Parcours', 'parent_category_id' => 4],
            ['id' => 'x', 'name' => 'Cassée'],
        ]]))])->categories();

        $this->assertSame(['4' => 'Général', '5' => 'Général › Parcours'], $categories);
        $this->assertSame('https://forum.example.org/site.json', $this->sent[0]['url']);
        $this->assertSame('wikibot', $this->header($this->sent[0]['options']['headers'], 'Api-Username'));
    }

    public function testCategoriesThrowWhenTheForumDoesNotAnswer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service([new MockResponse('', ['http_code' => 502])])->categories();
    }

    public function testACategoryIsCreatedUnderItsParentWithTheParentsColours(): void
    {
        $category = $this->service([
            new MockResponse(json_encode(['category' => ['id' => 4, 'color' => '25AAE2', 'text_color' => 'FFFFFF']])),
            new MockResponse(json_encode(['category' => ['id' => 9, 'slug' => 'atelier-velo']])),
        ])->createCategory(str_repeat('Atelier vélo ', 5), '4');

        $this->assertSame(['categoryId' => '9', 'categoryUrl' => 'https://forum.example.org/c/atelier-velo/9'], $category);
        $this->assertSame('https://forum.example.org/c/4/show.json', $this->sent[0]['url']);
        $post = $this->sent[1];
        $this->assertSame('https://forum.example.org/categories.json', $post['url']);
        $this->assertSame('wikibot', $this->header($post['options']['headers'], 'Api-Username'));
        $body = json_decode($post['options']['body'], true);
        $this->assertSame(4, $body['parent_category_id']);
        $this->assertSame('25AAE2', $body['color']);
        $this->assertSame(DiscourseApiService::CATEGORY_NAME_MAX, mb_strlen($body['name']));
    }

    public function testARefusedCategorySaysWhy(): void
    {
        $this->expectException(TopicRefusedException::class);
        $this->expectExceptionMessage('Category Name has already been taken');
        $this->service([
            new MockResponse('{}', ['http_code' => 404]),
            new MockResponse('{"errors":["Category Name has already been taken"]}', ['http_code' => 422]),
        ])->createCategory('Atelier vélo', '4');
    }

    public function testACategoryListsItsOwnTopicsWithoutItsDescriptionTopic(): void
    {
        $category = $this->service([
            new MockResponse(json_encode(['category' => ['id' => 9, 'topic_count' => 2, 'post_count' => 5, 'topic_url' => '/t/a-propos/30']])),
            new MockResponse(json_encode(['topic_list' => ['topics' => [
                ['id' => 5, 'category_id' => 4, 'title' => 'Bienvenue', 'slug' => 'bienvenue', 'posts_count' => 1],
                ['id' => 30, 'category_id' => 9, 'title' => 'À propos', 'slug' => 'a-propos', 'posts_count' => 1],
                ['id' => 42, 'category_id' => 9, 'title' => 'Atelier vélo', 'slug' => 'atelier-velo', 'posts_count' => 4, 'last_posted_at' => '2026-11-20T10:00:00.000Z'],
            ]]])),
        ])->category('9');

        $this->assertSame('https://forum.example.org/latest.json?category=9', $this->sent[1]['url']);
        $this->assertSame(2, $category['topics']);
        $this->assertSame(5, $category['posts']);
        $this->assertSame([[
            'id' => '42',
            'title' => 'Atelier vélo',
            'url' => 'https://forum.example.org/t/atelier-velo/42',
            'replies' => 3,
            'lastPostedAt' => '2026-11-20T10:00:00.000Z',
        ]], $category['latest']);
    }
}
