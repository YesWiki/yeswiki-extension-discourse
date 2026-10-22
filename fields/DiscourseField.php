<?php

namespace YesWiki\Discourse\Field;

use Psr\Container\ContainerInterface;
use YesWiki\Content\Attribute\Field;
use YesWiki\Content\Exception\EntryValidationException;
use YesWiki\Content\Field\BazarField;
use YesWiki\Content\Field\ContributesNoSearchableText;
use YesWiki\Content\Service\ImportContext;
use YesWiki\Content\Service\PageManager;
use YesWiki\Discourse\Exception\TopicRefusedException;
use YesWiki\Discourse\Service\ActivityPresenter;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Discourse\Service\TopicActivity;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\FlashMessageService;
use YesWiki\Kernel\Service\Journal;
use YesWiki\Kernel\Service\UrlFormatter;

/** `discourse`: saving an entry without a topic opens one about it on the Discourse forum, and the entry links to it. */
#[Field(['discourse'])]
class DiscourseField extends BazarField
{
    use ContributesNoSearchableText;

    protected const FIELD_CATEGORY = 6;

    protected string $category;

    public function __construct(array $values, ContainerInterface $services)
    {
        parent::__construct($values, $services);
        $this->category = trim((string)($values[self::FIELD_CATEGORY] ?? ''));
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function requiresTagBeforeFormatting()
    {
        return true;
    }

    public function getValueStructure()
    {
        return [$this->propertyName => [
            'topicId' => ['_mode_' => 'single', '_type_' => 'string'],
            'topicUrl' => ['_mode_' => 'single', '_type_' => 'string'],
        ]];
    }

    public function formatValuesBeforeSave($entry)
    {
        $tag = is_array($entry) ? (string)($entry['tag'] ?? '') : '';
        if ($tag === '' || $this->getService(ImportContext::class)->isImporting()) {
            return [(string)$this->propertyName => $this->storedValue($entry)];
        }

        $page = $this->getService(PageManager::class)->getOne($tag);
        if (!empty($page)) {
            $stored = $this->storedValue(is_array($page['body'] ?? null) ? $page['body'] : []);
            if ($stored['topicId'] !== '') {
                return [(string)$this->propertyName => $stored];
            }
        }

        return [(string)$this->propertyName => $this->createTopic($entry, $tag)];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{topicId: string, topicUrl: string}
     *
     * @throws EntryValidationException when the forum refuses the topic, so the entry is not saved either
     */
    protected function createTopic(array $entry, string $tag): array
    {
        $none = ['topicId' => '', 'topicUrl' => ''];
        $api = $this->getService(DiscourseApiService::class);
        if (!$api->isConfigured()) {
            $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_API_NOT_CONFIGURED'));

            return $none;
        }

        $title = trim(strip_tags(html_entity_decode((string)($entry['title'] ?? $tag), ENT_QUOTES, 'UTF-8')));
        $url = $this->getService(UrlFormatter::class)->href('', $tag, null, false);
        $user = $this->getService(AuthenticationService::class)->getLoggedUser();

        try {
            return $api->createTopic(
                $title,
                _t('DISCOURSE_TOPIC_BODY', ['title' => str_replace(['[', ']'], '', $title), 'url' => $url]),
                $this->category,
                is_array($user) ? (string)($user['name'] ?? '') : ''
            );
        } catch (TopicRefusedException $e) {
            throw new EntryValidationException(_t('DISCOURSE_TOPIC_REFUSED', ['error' => $e->getMessage()]), 0, $e);
        } catch (\Throwable $e) {
            $this->getService(Journal::class)->error("discourse: no topic for entry $tag: " . $e->getMessage(), ['exception' => $e]);
            $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_TOPIC_FAILED', ['error' => $e->getMessage()]));

            return $none;
        }
    }

    protected function renderInput($entry)
    {
        $stored = $this->storedValue($entry);

        return $this->render('@discourse/discourse-input.twig', [
            'topicUrl' => $stored['topicUrl'],
            'activity' => $this->activityOf($stored),
        ]);
    }

    protected function renderStatic($entry)
    {
        $stored = $this->storedValue($entry);

        if ($stored['topicUrl'] === '') {
            return '';
        }

        return $this->render('@discourse/discourse-field.twig', [
            'topicUrl' => $stored['topicUrl'],
            'activity' => $this->activityOf($stored),
        ]);
    }

    /** The topic's activity fragment shown beside the forum button, or '' when there is none yet. */
    private function activityOf(array $stored): string
    {
        $activity = $stored['topicId'] === '' ? null : $this->getService(TopicActivity::class)->get($stored['topicId']);

        return $activity === null ? '' : $this->getService(ActivityPresenter::class)->fragment($activity);
    }

    /** @param array<string, mixed>|null $entry */
    public function topicIdOf($entry): string
    {
        $topicId = $this->storedValue($entry)['topicId'];

        return ctype_digit($topicId) ? $topicId : '';
    }

    /**
     * @param array<string, mixed>|null $entry
     *
     * @return array{topicId: string, topicUrl: string}
     */
    protected function storedValue($entry): array
    {
        $value = $this->getValue($entry);
        $value = is_array($value) ? $value : [];
        $url = is_string($value['topicUrl'] ?? null) ? $value['topicUrl'] : '';

        return [
            'topicId' => is_scalar($value['topicId'] ?? null) ? (string)$value['topicId'] : '',
            'topicUrl' => in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '',
        ];
    }
}
