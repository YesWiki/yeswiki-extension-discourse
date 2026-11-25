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

/** `discourse`: saving an entry without a topic opens one about it on the Discourse forum, in a category of its own if the form asks, and the entry links to it. */
#[Field(['discourse'])]
class DiscourseField extends BazarField
{
    use ContributesNoSearchableText;

    protected const FIELD_CATEGORY = 6;
    protected const FIELD_TAGS = 7;
    protected const FIELD_TOPIC_BODY = 13;
    protected const FIELD_CATEGORY_PER_ENTRY = 14;

    private const NONE = ['topicId' => '', 'topicUrl' => '', 'categoryId' => '', 'categoryUrl' => ''];

    protected string $category;
    /** @var list<string> */
    protected array $tags;
    protected string $topicBody;
    protected bool $categoryPerEntry;

    public function __construct(array $values, ContainerInterface $services)
    {
        parent::__construct($values, $services);
        $this->category = trim((string)($values[self::FIELD_CATEGORY] ?? ''));
        $this->tags = array_values(array_filter(array_map('trim', explode(',', (string)($values[self::FIELD_TAGS] ?? ''))), 'strlen'));
        $this->topicBody = trim((string)($values[self::FIELD_TOPIC_BODY] ?? ''));
        $this->categoryPerEntry = in_array($values[self::FIELD_CATEGORY_PER_ENTRY] ?? '', [true, 1, '1', 'true', 'yes'], true);
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getTopicBody(): string
    {
        return $this->topicBody;
    }

    /** Whether each entry gets its own category, under the form's, with its topic inside. */
    public function opensACategoryPerEntry(): bool
    {
        return $this->categoryPerEntry;
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
            'categoryId' => ['_mode_' => 'single', '_type_' => 'string'],
            'categoryUrl' => ['_mode_' => 'single', '_type_' => 'string'],
        ]];
    }

    public function formatValuesBeforeSave($entry)
    {
        $tag = is_array($entry) ? (string)($entry['tag'] ?? '') : '';
        if ($tag === '' || $this->getService(ImportContext::class)->isImporting()) {
            return [(string)$this->propertyName => $this->storedValue($entry)];
        }

        $page = $this->getService(PageManager::class)->getOne($tag);
        $stored = $this->storedValue(empty($page) ? [] : (is_array($page['body'] ?? null) ? $page['body'] : []));
        if ($stored['topicId'] !== '') {
            return [(string)$this->propertyName => $stored];
        }

        return [(string)$this->propertyName => $this->createTopic($entry, $tag, $stored)];
    }

    /**
     * Opens the entry's topic, first creating its category when the form asks for one and the entry has none yet.
     *
     * @param array<string, mixed>                                                              $entry
     * @param array{topicId: string, topicUrl: string, categoryId: string, categoryUrl: string} $stored
     *
     * @return array{topicId: string, topicUrl: string, categoryId: string, categoryUrl: string}
     *
     * @throws EntryValidationException when the forum refuses the category or the topic, so the entry is not saved either
     */
    protected function createTopic(array $entry, string $tag, array $stored = self::NONE): array
    {
        $none = array_merge(self::NONE, ['categoryId' => $stored['categoryId'], 'categoryUrl' => $stored['categoryUrl']]);
        $api = $this->getService(DiscourseApiService::class);
        if (!$api->isConfigured()) {
            $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_API_NOT_CONFIGURED'));

            return $none;
        }

        $title = trim(strip_tags(html_entity_decode((string)($entry['title'] ?? $tag), ENT_QUOTES, 'UTF-8')));
        $url = $this->getService(UrlFormatter::class)->href('', $tag, null, false);
        $user = $this->getService(AuthenticationService::class)->getLoggedUser();
        $category = ['categoryId' => $none['categoryId'], 'categoryUrl' => $none['categoryUrl']];

        if ($this->categoryPerEntry && $category['categoryId'] === '') {
            try {
                $category = $api->createCategory($title, $this->category);
            } catch (TopicRefusedException $e) {
                throw new EntryValidationException(_t('DISCOURSE_CATEGORY_REFUSED', ['error' => $e->getMessage()]), 0, $e);
            } catch (\Throwable $e) {
                $this->getService(Journal::class)->error("discourse: no category for entry $tag: " . $e->getMessage(), ['exception' => $e]);
                $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_CATEGORY_FAILED', ['error' => $e->getMessage()]));

                return $none;
            }
            $none = array_merge(self::NONE, $category);
        }

        try {
            $topic = $api->createTopic(
                $title,
                $this->topicBody === ''
                    ? _t('DISCOURSE_TOPIC_BODY', ['title' => str_replace(['[', ']'], '', $title), 'url' => $url])
                    : $this->fillTopicBody($entry, $title, $url),
                $category['categoryId'] !== '' ? $category['categoryId'] : $this->category,
                is_array($user) ? (string)($user['name'] ?? '') : ''
            );
        } catch (TopicRefusedException $e) {
            if ($category['categoryId'] !== '' && $category['categoryId'] !== $stored['categoryId']) {
                $api->deleteCategory($category['categoryId']);
            }
            throw new EntryValidationException(_t('DISCOURSE_TOPIC_REFUSED', ['error' => $e->getMessage()]), 0, $e);
        } catch (\Throwable $e) {
            $this->getService(Journal::class)->error("discourse: no topic for entry $tag: " . $e->getMessage(), ['exception' => $e]);
            $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_TOPIC_FAILED', ['error' => $e->getMessage()]));

            return $none;
        }

        if ($this->tags !== []) {
            try {
                $api->tagTopic($topic['topicId'], $this->tags);
            } catch (\Throwable $e) {
                $this->getService(Journal::class)->error("discourse: no tags on the topic of entry $tag: " . $e->getMessage(), ['exception' => $e]);
                $this->getService(FlashMessageService::class)->setMessage(_t('DISCOURSE_TAGS_FAILED', ['error' => $e->getMessage()]));
            }
        }

        return $topic + $category;
    }

    /**
     * The form's topic body with {{title}}, {{url}} and each {{field}} replaced by the entry's value, '' when it has none.
     *
     * @param array<string, mixed> $entry
     */
    protected function fillTopicBody(array $entry, string $title, string $url): string
    {
        $values = ['title' => $title, 'url' => $url];

        return trim((string)preg_replace_callback('#{{\s*([^{}]+?)\s*}}#', static function (array $match) use ($entry, $values): string {
            $value = $values[$match[1]] ?? $entry[$match[1]] ?? '';
            if (is_array($value)) {
                return implode(', ', array_filter($value, 'is_scalar'));
            }

            return is_scalar($value) ? (string)$value : '';
        }, $this->topicBody));
    }

    protected function renderInput($entry)
    {
        $stored = $this->storedValue($entry);

        return $this->render('@discourse/discourse-input.twig', [
            'topicUrl' => $stored['categoryUrl'] !== '' ? $stored['categoryUrl'] : $stored['topicUrl'],
            'activity' => $this->activityOf($stored),
        ]);
    }

    protected function renderStatic($entry)
    {
        $stored = $this->storedValue($entry);

        $url = $stored['categoryUrl'] !== '' ? $stored['categoryUrl'] : $stored['topicUrl'];
        if ($url === '') {
            return '';
        }

        return $this->render('@discourse/discourse-field.twig', [
            'topicUrl' => $url,
            'activity' => $this->activityOf($stored),
        ]);
    }

    /** The category's or else the topic's activity fragment shown beside the forum button, or '' when there is none yet. */
    private function activityOf(array $stored): string
    {
        $presenter = $this->getService(ActivityPresenter::class);
        if ($stored['categoryId'] !== '') {
            $activity = $this->getService(TopicActivity::class)->category($stored['categoryId']);

            return $activity === null ? '' : $presenter->categoryFragment($activity);
        }
        $activity = $stored['topicId'] === '' ? null : $this->getService(TopicActivity::class)->get($stored['topicId']);

        return $activity === null ? '' : $presenter->fragment($activity);
    }

    /** @param array<string, mixed>|null $entry */
    public function topicIdOf($entry): string
    {
        $topicId = $this->storedValue($entry)['topicId'];

        return ctype_digit($topicId) ? $topicId : '';
    }

    /** @param array<string, mixed>|null $entry */
    public function categoryOf($entry): array
    {
        $stored = $this->storedValue($entry);

        return ctype_digit($stored['categoryId']) ? ['categoryId' => $stored['categoryId'], 'categoryUrl' => $stored['categoryUrl']] : ['categoryId' => '', 'categoryUrl' => ''];
    }

    /**
     * @param array<string, mixed>|null $entry
     *
     * @return array{topicId: string, topicUrl: string, categoryId: string, categoryUrl: string}
     */
    protected function storedValue($entry): array
    {
        $value = $this->getValue($entry);
        $value = is_array($value) ? $value : [];

        return [
            'topicId' => is_scalar($value['topicId'] ?? null) ? (string)$value['topicId'] : '',
            'topicUrl' => self::webUrl($value['topicUrl'] ?? null),
            'categoryId' => is_scalar($value['categoryId'] ?? null) ? (string)$value['categoryId'] : '',
            'categoryUrl' => self::webUrl($value['categoryUrl'] ?? null),
        ];
    }

    /** $url when it is an http or https address, '' otherwise. */
    private static function webUrl(mixed $url): string
    {
        $url = is_string($url) ? $url : '';

        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }
}
