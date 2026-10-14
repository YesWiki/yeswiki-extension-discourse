<?php

namespace YesWiki\Discourse\Service;

use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/** DiscourseConnect: a shared HMAC-SHA256 secret signs a base64 query string; Discourse owns the nonce. */
class DiscourseConnectService
{
    public function __construct(private readonly ParameterBagInterface $params)
    {
    }

    /** The shared secret from discourse_connect.sso_secret, or an exception when it is empty. */
    public function getSecret(): string
    {
        $config = $this->params->has('discourse_connect') ? $this->params->get('discourse_connect') : [];
        $secret = is_array($config) ? (string)($config['sso_secret'] ?? '') : '';
        if ($secret === '') {
            throw new \Exception('discourse_connect.sso_secret is not configured');
        }

        return $secret;
    }

    public function verifySignature(string $sso, string $sig): bool
    {
        $expected = hash_hmac('sha256', $sso, $this->getSecret());

        return hash_equals($expected, strtolower($sig));
    }

    /** @return array{nonce:string,return_sso_url:string} */
    public function decodePayload(string $sso): array
    {
        $decoded = base64_decode($sso, true);
        if ($decoded === false) {
            throw new \Exception('Malformed sso payload');
        }

        parse_str($decoded, $payload);

        if (empty($payload['nonce']) || empty($payload['return_sso_url'])
            || !is_string($payload['nonce']) || !is_string($payload['return_sso_url'])) {
            throw new \Exception('Missing nonce or return_sso_url in sso payload');
        }
        if (!filter_var($payload['return_sso_url'], FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid return_sso_url in sso payload');
        }

        return [
            'nonce' => $payload['nonce'],
            'return_sso_url' => $payload['return_sso_url'],
        ];
    }

    /**
     * @param array<string,string> $claims
     *
     * @return array{sso:string,sig:string}
     */
    public function buildResponse(array $claims): array
    {
        $sso = base64_encode(http_build_query($claims));
        $sig = hash_hmac('sha256', $sso, $this->getSecret());

        return ['sso' => $sso, 'sig' => $sig];
    }

    /**
     * @param array<string,mixed> $user
     *
     * @return array<string,string>
     */
    public function getClaimsForUser(array $user, string $nonce): array
    {
        return [
            'nonce' => $nonce,
            'external_id' => (string)$user['name'],
            'email' => (string)$user['email'],
            'username' => (string)$user['name'],
            'name' => (string)$user['name'],
            'email_verified' => 'true',
        ];
    }
}
