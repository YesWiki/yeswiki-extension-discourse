<?php

namespace YesWiki\Test\Discourse\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\FormManager;
use YesWiki\Content\Service\PageManager;
use YesWiki\Discourse\Controller\ApiController;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Discourse\Service\DiscourseConnectService;
use YesWiki\Discourse\Service\TopicActivity;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\CurrentRequest;
use YesWiki\Kernel\Service\Journal;
use YesWiki\Test\Core\ForcedParameterBag;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';
require_once 'tests/ForcedParameterBag.php';

/** The /api/discourse/sso round trip, from Discourse's signed request to the signed redirect back. */
class ApiControllerTest extends YesWikiTestCase
{
    private const SECRET = 'a-secret-only-this-test-knows';
    private const RETURN_URL = 'https://forum.example.org/session/sso_login';

    private ?Request $previousRequest = null;
    private string $formId = '';
    private string $entryTag = '';
    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
        $current = $this->getWiki()->services->get(CurrentRequest::class);
        $this->previousRequest = $current->has() ? $current->get() : null;
        $this->getWiki()->services->get(AuthenticationService::class)->logout();
    }

    protected function tearDown(): void
    {
        $services = $this->getWiki()->services;
        if ($this->entryTag !== '') {
            $services->get(PageManager::class)->deleteOrphaned($this->entryTag);
        }
        if ($this->formId !== '') {
            $services->get(FormManager::class)->delete($this->formId);
        }
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/*.json') ?: []);
            rmdir($this->cacheDir);
        }
        $services->get(AuthenticationService::class)->logout();
        if ($this->previousRequest !== null) {
            $services->get(CurrentRequest::class)->replace($this->previousRequest);
        }
        parent::tearDown();
    }

    /** @param array<class-string, object> $replaced */
    private function controller(string $secret = self::SECRET, array $replaced = []): ApiController
    {
        $real = $this->getWiki()->services;
        $replaced[DiscourseConnectService::class] ??= new DiscourseConnectService(new ForcedParameterBag(
            $real->get(ParameterBagInterface::class),
            ['discourse_connect' => ['sso_secret' => $secret]]
        ));
        $controller = new ApiController();
        $controller->setServices(new class($real, $replaced) implements ContainerInterface {
            /** @param array<class-string, object> $replaced */
            public function __construct(private ContainerInterface $real, private array $replaced)
            {
            }

            public function get(string $id): mixed
            {
                return $this->replaced[$id] ?? $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return isset($this->replaced[$id]) || $this->real->has($id);
            }
        });

        return $controller;
    }

    /** @param array<string,string> $query */
    private function request(array $query): Request
    {
        $request = Request::create('https://wiki.example.org/?api/discourse/sso', 'GET', $query);
        $this->getWiki()->services->get(CurrentRequest::class)->replace($request);

        return $request;
    }

    /** @return array<string,string> */
    private function signedQuery(string $secret = self::SECRET): array
    {
        $sso = base64_encode(http_build_query(['nonce' => 'n0nc3', 'return_sso_url' => self::RETURN_URL]));

        return ['sso' => $sso, 'sig' => hash_hmac('sha256', $sso, $secret)];
    }

    public function testARequestWithoutPayloadIsRefused(): void
    {
        $response = $this->controller()->discourseSso($this->request([]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(_t('DISCOURSE_INVALID_REQUEST'), (string)$response->getContent());
    }

    public function testAWikiWithoutSecretSaysSo(): void
    {
        $response = $this->controller('')->discourseSso($this->request($this->signedQuery()));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(_t('DISCOURSE_NOT_CONFIGURED'), (string)$response->getContent());
    }

    public function testAPayloadSignedWithAnotherSecretIsRefused(): void
    {
        $response = $this->controller()->discourseSso($this->request($this->signedQuery('not the shared one')));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(_t('DISCOURSE_INVALID_SIGNATURE'), (string)$response->getContent());
    }

    public function testAVisitorWhoIsNotSignedInGetsTheLoginFormOnTheSameUrl(): void
    {
        $response = $this->controller()->discourseSso($this->request($this->signedQuery()));

        $this->assertSame(200, $response->getStatusCode());
        $html = (string)$response->getContent();
        $this->assertStringContainsString('login-form', $html);
        $this->assertStringContainsString('value="' . ApiController::LOGIN_CONTEXT . '"', $html);
        $this->assertStringContainsString('api/discourse/sso', $html);
    }

    public function testASignedInUserIsSentBackToDiscourseWithSignedClaims(): void
    {
        $admin = $this->getWiki()->services->get(AuthenticationService::class)->connectFirstAdmin();
        if ($admin === null) {
            $this->markTestSkipped('this wiki has no admin to sign in as');
        }

        $response = $this->controller()->discourseSso($this->request($this->signedQuery()));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $target = $response->getTargetUrl();
        $this->assertStringStartsWith(self::RETURN_URL . '?sso=', $target);
        parse_str((string)parse_url($target, PHP_URL_QUERY), $back);
        $this->assertSame(hash_hmac('sha256', $back['sso'], self::SECRET), $back['sig']);
        parse_str(base64_decode($back['sso']), $claims);
        $this->assertSame('n0nc3', $claims['nonce']);
        $this->assertSame($admin['name'], $claims['external_id']);
        $this->assertSame($admin['email'], $claims['email']);
    }

    private function topicActivity(): TopicActivity
    {
        $this->cacheDir = sys_get_temp_dir() . '/discourse-controller-test-' . bin2hex(random_bytes(4));
        $settings = new ParameterBag(['discourse_connect' => ['url' => 'https://forum.example.org', 'api_key' => 'k3y']]);
        $topic = json_encode([
            'posts_count' => 4,
            'post_stream' => ['stream' => [1, 2, 3, 4]],
            'timeline_lookup' => [[1, 9], [3, 0]],
        ]);
        $api = new DiscourseApiService($settings, new MockHttpClient(fn () => new MockResponse((string)$topic)));

        return new TopicActivity($api, $settings, $this->getWiki()->services->get(Journal::class), $this->cacheDir);
    }

    public function testAnEntrysActivityComesBackAsAFragment(): void
    {
        $services = $this->getWiki()->services;
        $services->get(AuthenticationService::class)->connectFirstAdmin();
        $forms = $services->get(FormManager::class);
        $this->formId = (string)$forms->findNewId();
        $forms->create([
            'id' => $this->formId,
            'label' => 'Discourse activity test',
            'template' => [
                ['type' => 'texte', 'name' => 'bf_titre', 'label' => 'Titre'],
                ['type' => 'discourse', 'name' => 'bf_discourse', 'label' => 'Forum'],
            ],
            'entry_title_template' => '{{bf_titre}}',
        ]);
        $entries = $services->get(EntryManager::class);
        $this->entryTag = (string)$entries->create($this->formId, ['bf_titre' => 'Discourse activity test entry'])['tag'];
        $pages = $services->get(PageManager::class);
        $body = $pages->getOne($this->entryTag, null, false)['body'];
        $pages->save($this->entryTag, ['bf_discourse' => ['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/x/42']] + $body, '', true, null, PageType::ENTRY);

        $controller = $this->controller(self::SECRET, [TopicActivity::class => $this->topicActivity()]);
        $response = $controller->entryActivity($this->entryTag);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(_t('DISCOURSE_ACTIVITY_REPLIES', ['n' => 3]), (string)$response->getContent());
        $this->assertStringContainsString('<svg class="discourse-sparkline"', (string)$response->getContent());

        $this->assertSame(204, $controller->entryActivity('PagePrincipale')->getStatusCode());
        $this->assertSame(204, $controller->entryActivity('NoSuchEntryAnywhere')->getStatusCode());
    }
}
