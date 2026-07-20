<?php

namespace YesWiki\Discourse\Controller;

use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use YesWiki\Core\YesWikiController;
use YesWiki\Core\Service\UserManager;
use YesWiki\Discourse\Service\DiscourseConnectService;

class ApiController extends YesWikiController
{
    /**
     * @Route("/api/discourse/sso", methods={"GET","POST"}, options={"acl":{"public"}})
     */
    public function discourseSso(Request $request)
    {
        $discourseConnectService = $this->getService(DiscourseConnectService::class);

        $sso = $request->query->get('sso', '');
        $sig = $request->query->get('sig', '');

        if (empty($sso) || empty($sig)) {
            return $this->errorResponse(_t('DISCOURSE_INVALID_REQUEST'));
        }

        try {
            $sigValid = $discourseConnectService->verifySignature($sso, $sig);
        } catch (Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_NOT_CONFIGURED'));
        }
        if (!$sigValid) {
            return $this->errorResponse(_t('DISCOURSE_INVALID_SIGNATURE'));
        }

        try {
            $payload = $discourseConnectService->decodePayload($sso);
        } catch (Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_INVALID_REQUEST'));
        }

        $user = $this->getService(UserManager::class)->getLoggedUser();

        if (empty($user)) {
            return new Response(
                $this->wiki->Header()
                . $this->wiki->Format('{{login context="login-page" signupurl="0"}}')
                . $this->wiki->Footer()
            );
        }

        try {
            $claims = $discourseConnectService->getClaimsForUser($user, $payload['nonce']);
            $response = $discourseConnectService->buildResponse($claims);
        } catch (Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_NOT_CONFIGURED'));
        }

        $separator = str_contains($payload['return_sso_url'], '?') ? '&' : '?';
        $target = $payload['return_sso_url'] . $separator
            . 'sso=' . rawurlencode($response['sso'])
            . '&sig=' . rawurlencode($response['sig']);

        return new RedirectResponse($target);
    }

    private function errorResponse(string $message): Response
    {
        return new Response(
            '<div class="alert alert-danger">' . htmlspecialchars($message, ENT_COMPAT, 'UTF-8') . '</div>',
            Response::HTTP_BAD_REQUEST
        );
    }
}
