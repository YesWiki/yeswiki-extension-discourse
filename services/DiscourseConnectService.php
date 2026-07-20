<?php

namespace YesWiki\Discourse\Service;

use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Implements the DiscourseConnect (formerly "Discourse SSO") protocol: a single
 * shared HMAC-SHA256 secret authenticates a base64-encoded query-string payload
 * passed back and forth with Discourse. Entirely stateless - Discourse generates
 * and tracks its own nonce, we just echo it back.
 */
class DiscourseConnectService
{
    protected $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function getSecret(): string
    {
        $secret = $this->params->get('discourse_connect')['sso_secret'] ?? '';
        if (empty($secret)) {
            throw new Exception('discourse_connect.sso_secret is not configured');
        }

        return $secret;
    }

    public function verifySignature(string $sso, string $sig): bool
    {
        $expected = hash_hmac('sha256', $sso, $this->getSecret());

        return hash_equals($expected, strtolower($sig));
    }

    /**
     * @return array{nonce:string,return_sso_url:string}
     */
    public function decodePayload(string $sso): array
    {
        $decoded = base64_decode($sso, true);
        if ($decoded === false) {
            throw new Exception('Malformed sso payload');
        }

        parse_str($decoded, $payload);

        if (empty($payload['nonce']) || empty($payload['return_sso_url'])) {
            throw new Exception('Missing nonce or return_sso_url in sso payload');
        }
        if (!filter_var($payload['return_sso_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid return_sso_url in sso payload');
        }

        return [
            'nonce' => $payload['nonce'],
            'return_sso_url' => $payload['return_sso_url'],
        ];
    }

    /**
     * @param array<string,string> $claims
     * @return array{sso:string,sig:string}
     */
    public function buildResponse(array $claims): array
    {
        $sso = base64_encode(http_build_query($claims));
        $sig = hash_hmac('sha256', $sso, $this->getSecret());

        return ['sso' => $sso, 'sig' => $sig];
    }

    /**
     * $user is whatever AuthController::getLoggedUser()/UserManager::getLoggedUser()
     * returns - a plain array on some YesWiki versions, an ArrayAccess User entity on
     * others. Both support the [] access used below, so it's left untyped here.
     *
     * @return array<string,string>
     */
    public function getClaimsForUser($user, string $nonce): array
    {
        return [
            'nonce' => $nonce,
            'external_id' => $user['name'],
            'email' => $user['email'],
            'username' => $user['name'],
            'name' => $user['name'],
            'email_verified' => 'true',
        ];
    }
}
