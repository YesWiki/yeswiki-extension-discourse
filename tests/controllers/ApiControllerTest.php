<?php

namespace YesWiki\Test\Discourse\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use YesWiki\Discourse\Controller\ApiController;
use YesWiki\Discourse\Service\DiscourseConnectService;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\CurrentRequest;
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
        $services->get(AuthenticationService::class)->logout();
        if ($this->previousRequest !== null) {
            $services->get(CurrentRequest::class)->replace($this->previousRequest);
        }
        parent::tearDown();
    }

    private function controller(string $secret = self::SECRET): ApiController
    {
        $real = $this->getWiki()->services;
        $service = new DiscourseConnectService(new ForcedParameterBag(
            $real->get(ParameterBagInterface::class),
            ['discourse_connect' => ['sso_secret' => $secret]]
        ));
        $controller = new ApiController();
        $controller->setServices(new class($real, $service) implements ContainerInterface {
            public function __construct(private ContainerInterface $real, private DiscourseConnectService $service)
            {
            }

            public function get(string $id): mixed
            {
                return $id === DiscourseConnectService::class ? $this->service : $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === DiscourseConnectService::class || $this->real->has($id);
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
}
