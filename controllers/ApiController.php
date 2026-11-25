<?php

namespace YesWiki\Discourse\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\FormManager;
use YesWiki\Core\YesWikiController;
use YesWiki\Discourse\Field\DiscourseField;
use YesWiki\Discourse\Service\ActivityPresenter;
use YesWiki\Discourse\Service\DiscourseConnectService;
use YesWiki\Discourse\Service\TopicActivity;
use YesWiki\Identity\Service\AclService;
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

    /** The topics and sparkline of an entry's forum category, or else the replies and sparkline of its topic, or 204 when there is none to show. */
    #[Route('/api/discourse/entries/{tag}/activity', methods: ['GET'], options: ['acl' => ['public']])]
    public function entryActivity(string $tag): Response
    {
        $presenter = $this->getService(ActivityPresenter::class);
        $activities = $this->getService(TopicActivity::class);
        foreach ($this->discourseFieldsOf($tag) as [$field, $entry]) {
            $category = $field->categoryOf($entry);
            if ($category['categoryId'] !== '') {
                $activity = $activities->category($category['categoryId']);
                if ($activity !== null) {
                    return $this->fragment($presenter->categoryFragment($activity));
                }
                continue;
            }
            $topicId = $field->topicIdOf($entry);
            $activity = $topicId === '' ? null : $activities->get($topicId);
            if ($activity !== null) {
                return $this->fragment($presenter->fragment($activity));
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** The latest topics of an entry's forum category as a list of links, or 204 when the entry has no category. */
    #[Route('/api/discourse/entries/{tag}/topics', methods: ['GET'], options: ['acl' => ['public']])]
    public function entryTopics(string $tag): Response
    {
        foreach ($this->discourseFieldsOf($tag) as [$field, $entry]) {
            $category = $field->categoryOf($entry);
            $activity = $category['categoryId'] === '' ? null : $this->getService(TopicActivity::class)->category($category['categoryId']);
            if ($activity !== null) {
                return $this->fragment($this->getService(ActivityPresenter::class)->topics($activity, $category['categoryUrl']));
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** @return list<array{0: DiscourseField, 1: array<string, mixed>}> the discourse fields of the entry the visitor may read, with the entry */
    private function discourseFieldsOf(string $tag): array
    {
        if (!$this->getService(AclService::class)->hasAccess('read', $tag)) {
            return [];
        }
        $entry = $this->getService(EntryManager::class)->getOne($tag);
        $formId = is_array($entry) ? (string)($entry['form_id'] ?? $entry['id_typeannonce'] ?? '') : '';
        if ($formId === '') {
            return [];
        }
        $fields = [];
        foreach ($this->getService(FormManager::class)->getOne($formId)['prepared'] ?? [] as $field) {
            if ($field instanceof DiscourseField && $field->canRead($entry)) {
                $fields[] = [$field, $entry];
            }
        }

        return $fields;
    }

    private function fragment(string $html): Response
    {
        $response = new Response($html);
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }

    private function errorResponse(string $message): Response
    {
        return new Response(
            '<div class="yw-alert yw-alert--danger" role="alert">' . htmlspecialchars($message, ENT_COMPAT, 'UTF-8') . '</div>',
            Response::HTTP_BAD_REQUEST
        );
    }
}
