<?php

namespace YesWiki\Discourse\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YesWiki\Core\YesWikiController;
use YesWiki\Discourse\Service\DiscourseConnectService;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Render\Service\MarkdownFormatterService;
use YesWiki\Render\Service\TemplateEngine;

class ApiController extends YesWikiController
{
    public const LOGIN_CONTEXT = 'discourse-sso';

    #[Route('/api/discourse/sso', methods: ['GET', 'POST'], options: ['acl' => ['public']])]
    public function discourseSso(Request $request): Response
    {
        $discourseConnectService = $this->getService(DiscourseConnectService::class);

        $sso = (string)$request->query->get('sso', '');
        $sig = (string)$request->query->get('sig', '');

        if ($sso === '' || $sig === '') {
            return $this->errorResponse(_t('DISCOURSE_INVALID_REQUEST'));
        }

        try {
            $sigValid = $discourseConnectService->verifySignature($sso, $sig);
        } catch (\Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_NOT_CONFIGURED'));
        }
        if (!$sigValid) {
            return $this->errorResponse(_t('DISCOURSE_INVALID_SIGNATURE'));
        }

        try {
            $payload = $discourseConnectService->decodePayload($sso);
        } catch (\Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_INVALID_REQUEST'));
        }

        $user = $this->getService(AuthenticationService::class)->getLoggedUser();

        if (empty($user)) {
            return new Response($this->getService(TemplateEngine::class)->renderPage(
                $this->getService(MarkdownFormatterService::class)->format(
                    '{{login template="login-form.twig" context="' . self::LOGIN_CONTEXT . '" signupurl="0"}}'
                )
            ));
        }

        try {
            $claims = $discourseConnectService->getClaimsForUser($user, $payload['nonce']);
            $response = $discourseConnectService->buildResponse($claims);
        } catch (\Exception $e) {
            return $this->errorResponse(_t('DISCOURSE_NOT_CONFIGURED'));
        }

        $separator = str_contains($payload['return_sso_url'], '?') ? '&' : '?';
        $target = $payload['return_sso_url'] . $separator
            . 'sso=' . rawurlencode($response['sso'])
            . '&sig=' . rawurlencode($response['sig']);

        return new RedirectResponse($target);
    }

    /** The replies and sparkline of the topic an entry's discourse field opened, or 204 when there is none to show. */
    #[Route('/api/discourse/entries/{tag}/activity', methods: ['GET'], options: ['acl' => ['public']])]
    public function entryActivity(string $tag): Response
    {
        $nothing = new Response('', Response::HTTP_NO_CONTENT);
        if (!$this->getService(AclService::class)->hasAccess('read', $tag)) {
            return $nothing;
        }
        $entry = $this->getService(EntryManager::class)->getOne($tag);
        $formId = is_array($entry) ? (string)($entry['form_id'] ?? $entry['id_typeannonce'] ?? '') : '';
        if ($formId === '') {
            return $nothing;
        }
        $form = $this->getService(FormManager::class)->getOne($formId);

        foreach ($form['prepared'] ?? [] as $field) {
            if (!$field instanceof DiscourseField || !$field->canRead($entry)) {
                continue;
            }
            $topicId = $field->topicIdOf($entry);
            $activity = $topicId === '' ? null : $this->getService(TopicActivity::class)->get($topicId);
            if ($activity !== null) {
                $response = new Response($this->getService(ActivityPresenter::class)->fragment($activity));
                $response->setPrivate();
                $response->setMaxAge(300);

                return $response;
            }
        }

        return $nothing;
    }

    private function errorResponse(string $message): Response
    {
        return new Response(
            '<div class="yw-alert yw-alert--danger" role="alert">' . htmlspecialchars($message, ENT_COMPAT, 'UTF-8') . '</div>',
            Response::HTTP_BAD_REQUEST
        );
    }
}
