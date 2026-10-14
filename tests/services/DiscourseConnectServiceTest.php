<?php

namespace YesWiki\Test\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use YesWiki\Discourse\Service\DiscourseConnectService;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** The DiscourseConnect signing and payload rules, with a secret of the test's own. */
class DiscourseConnectServiceTest extends YesWikiTestCase
{
    private const SECRET = 'd836444a9e4084d5b224a60c208dce14';

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
    }

    private function service(?string $secret = self::SECRET): DiscourseConnectService
    {
        $params = $secret === null ? [] : ['discourse_connect' => ['sso_secret' => $secret]];

        return new DiscourseConnectService(new ParameterBag($params));
    }

    public function testDiscoursesOwnExampleSignatureVerifies(): void
    {
        $sso = 'bm9uY2U9Y2I2ODI1MWVlZmI1MjExZTU4YzAwZmYxMzk1ZjBjMGI=';

        $this->assertTrue($this->service()->verifySignature($sso, hash_hmac('sha256', $sso, self::SECRET)));
        $this->assertTrue($this->service()->verifySignature($sso, strtoupper(hash_hmac('sha256', $sso, self::SECRET))));
        $this->assertFalse($this->service()->verifySignature($sso, hash_hmac('sha256', $sso, 'another secret')));
    }

    public function testAnEmptyOrMissingSecretRefusesToSign(): void
    {
        foreach (['', null] as $secret) {
            try {
                $this->service($secret)->verifySignature('x', 'y');
                $this->fail('a missing secret should throw');
            } catch (\Exception $e) {
                $this->assertStringContainsString('sso_secret', $e->getMessage());
            }
        }
    }

    public function testThePayloadGivesBackTheNonceAndTheReturnUrl(): void
    {
        $sso = base64_encode(http_build_query(['nonce' => 'abc', 'return_sso_url' => 'https://forum.example.org/session/sso_login']));

        $this->assertSame(
            ['nonce' => 'abc', 'return_sso_url' => 'https://forum.example.org/session/sso_login'],
            $this->service()->decodePayload($sso)
        );
    }

    /** @return array<string, array{string}> */
    public static function badPayloads(): array
    {
        return [
            'not base64' => ['%%%'],
            'no nonce' => [base64_encode('return_sso_url=https://forum.example.org/')],
            'no return url' => [base64_encode('nonce=abc')],
            'return url is not a url' => [base64_encode('nonce=abc&return_sso_url=javascript:alert(1)')],
            'nonce is an array' => [base64_encode('nonce[]=abc&return_sso_url=https://forum.example.org/')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPayloads')]
    public function testABadPayloadIsRefused(string $sso): void
    {
        $this->expectException(\Exception::class);
        $this->service()->decodePayload($sso);
    }

    public function testTheResponseIsSignedWithTheSharedSecret(): void
    {
        $service = $this->service();
        $claims = $service->getClaimsForUser(['name' => 'JaneDoe', 'email' => 'jane@example.org'], 'abc');
        $response = $service->buildResponse($claims);

        $this->assertSame(hash_hmac('sha256', $response['sso'], self::SECRET), $response['sig']);
        parse_str(base64_decode($response['sso']), $decoded);
        $this->assertSame([
            'nonce' => 'abc',
            'external_id' => 'JaneDoe',
            'email' => 'jane@example.org',
            'username' => 'JaneDoe',
            'name' => 'JaneDoe',
            'email_verified' => 'true',
        ], $decoded);
    }
}
