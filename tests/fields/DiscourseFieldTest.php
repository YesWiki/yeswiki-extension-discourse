<?php

namespace YesWiki\Test\Discourse\Field;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use YesWiki\Content\Entity\PageType;
use YesWiki\Content\Exception\EntryValidationException;
use YesWiki\Content\Service\EntryManager;
use YesWiki\Content\Service\FieldFactory;
use YesWiki\Content\Service\FormManager;
use YesWiki\Content\Service\ImportContext;
use YesWiki\Content\Service\PageManager;
use YesWiki\Discourse\Field\DiscourseField;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Kernel\Service\FlashMessageService;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** The discourse field opens a topic when its entry is saved without one, and only then. */
class DiscourseFieldTest extends YesWikiTestCase
{
    private int $posts = 0;
    private string $formId = '';
    private string $entryTag = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
        $this->getWiki()->services->get(FlashMessageService::class)->getMessage();
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
        $services->get(AuthenticationService::class)->logout();
        parent::tearDown();
    }

    /** @param list<MockResponse> $responses */
    private function field(array $responses, bool $configured = true): DiscourseField
    {
        $this->posts = 0;
        $client = new MockHttpClient(function (string $method) use (&$responses): MockResponse {
            if ($method === 'POST') {
                $this->posts++;
            }

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });
        $api = new DiscourseApiService(new ParameterBag(['discourse_connect' => $configured
            ? ['url' => 'https://forum.example.org', 'api_key' => 'k3y']
            : []]), $client);
        $real = $this->getWiki()->services;
        $services = new class($real, $api) implements ContainerInterface {
            public function __construct(private ContainerInterface $real, private DiscourseApiService $api)
            {
            }

            public function get(string $id): mixed
            {
                return $id === DiscourseApiService::class ? $this->api : $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === DiscourseApiService::class || $this->real->has($id);
            }
        };

        $values = array_fill(0, 16, '');
        $values[0] = 'discourse';
        $values[1] = 'bf_discourse';
        $values[2] = 'Forum';
        $values[6] = '5';

        return new DiscourseField($values, $services);
    }

    private function topicCreated(): MockResponse
    {
        return new MockResponse(json_encode(['topic_id' => 42, 'topic_slug' => 'atelier-velo']));
    }

    public function testTheFormDesignerKeyCategoryReachesTheField(): void
    {
        $map = $this->getWiki()->services->get(FieldFactory::class)->getAttributeKeyToIndexMap('discourse');

        $this->assertSame(6, $map['category'] ?? null);
        $this->assertSame('5', $this->field([])->getCategory());
    }

    public function testCreatingAnEntryOpensATopic(): void
    {
        $saved = $this->field([$this->topicCreated()])->formatValuesBeforeSave([
            'tag' => 'DiscourseFieldTestUnsavedEntry',
            'title' => 'Atelier vélo du samedi',
        ]);

        $this->assertSame(['bf_discourse' => ['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/atelier-velo/42']], $saved);
        $this->assertSame(1, $this->posts);
    }

    /** @param array{topicId: string, topicUrl: string} $topic */
    private function savedEntry(array $topic): string
    {
        $this->getWiki()->services->get(AuthenticationService::class)->connectFirstAdmin();
        $this->entryTag = 'DiscourseFieldTestSavedEntry';
        $this->getWiki()->services->get(PageManager::class)->save($this->entryTag, [
            'title' => 'Atelier vélo du samedi',
            'bf_discourse' => $topic,
        ], '', true, null, PageType::ENTRY);

        return $this->entryTag;
    }

    public function testUpdatingAnEntryKeepsItsTopicAndPostsNothing(): void
    {
        $topic = ['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/atelier-velo/42'];
        $saved = $this->field([$this->topicCreated()])->formatValuesBeforeSave([
            'tag' => $this->savedEntry($topic),
            'title' => 'Atelier vélo du samedi',
            'bf_discourse' => ['topicId' => '', 'topicUrl' => ''],
        ]);

        $this->assertSame(['bf_discourse' => $topic], $saved);
        $this->assertSame(0, $this->posts);
    }

    public function testUpdatingAnEntryWithoutTopicOpensOne(): void
    {
        $tag = $this->savedEntry(['topicId' => '', 'topicUrl' => '']);
        $saved = $this->field([new MockResponse('', ['http_code' => 404]), $this->topicCreated()])->formatValuesBeforeSave([
            'tag' => $tag,
            'title' => 'Atelier vélo du samedi',
        ]);

        $this->assertSame(['bf_discourse' => ['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/atelier-velo/42']], $saved);
        $this->assertSame(1, $this->posts);
    }

    public function testTheEditFormOfAnEntryWithoutTopicSaysSavingWillOpenOne(): void
    {
        $html = $this->field([])->renderInputIfPermitted(['tag' => 'DiscourseFieldTestSavedEntry', 'bf_discourse' => ['topicId' => '', 'topicUrl' => '']]);

        $this->assertStringContainsString(_t('DISCOURSE_FIELD_WILL_CREATE'), $html);
    }

    public function testAnImportedEntryOpensNoTopic(): void
    {
        $field = $this->field([$this->topicCreated()]);
        $saved = $this->getWiki()->services->get(ImportContext::class)->during(fn () => $field->formatValuesBeforeSave([
            'tag' => 'DiscourseFieldTestUnsavedEntry',
            'title' => 'Atelier vélo du samedi',
        ]));

        $this->assertSame(['bf_discourse' => ['topicId' => '', 'topicUrl' => '']], $saved);
        $this->assertSame(0, $this->posts);
    }

    public function testATitleTheForumRefusesStopsTheEntryAndSaysWhy(): void
    {
        $field = $this->field([new MockResponse('{"errors":["Title is too short"]}', ['http_code' => 422])]);

        $this->expectException(EntryValidationException::class);
        $this->expectExceptionMessage('Title is too short');
        $field->formatValuesBeforeSave(['tag' => 'DiscourseFieldTestUnsavedEntry', 'title' => 'Court']);
    }

    public function testAForumThatFailsStillLetsTheEntryBeSavedAndSaysWhy(): void
    {
        $saved = $this->field([new MockResponse('', ['http_code' => 502])])
            ->formatValuesBeforeSave(['tag' => 'DiscourseFieldTestUnsavedEntry', 'title' => 'Atelier vélo du samedi']);

        $this->assertSame(['bf_discourse' => ['topicId' => '', 'topicUrl' => '']], $saved);
        $this->assertStringContainsString('HTTP 502', $this->getWiki()->services->get(FlashMessageService::class)->getMessage());
    }

    public function testAWikiWithoutApiSettingsSaysSo(): void
    {
        $saved = $this->field([], false)->formatValuesBeforeSave(['tag' => 'DiscourseFieldTestUnsavedEntry', 'title' => 'Atelier']);

        $this->assertSame(['bf_discourse' => ['topicId' => '', 'topicUrl' => '']], $saved);
        $this->assertSame(_t('DISCOURSE_API_NOT_CONFIGURED'), $this->getWiki()->services->get(FlashMessageService::class)->getMessage());
    }

    public function testTheEntryShowsALinkToItsTopicOnlyOnceThereIsOne(): void
    {
        $field = $this->field([]);
        $html = $field->renderStaticIfPermitted(['bf_discourse' => ['topicId' => '42', 'topicUrl' => 'https://forum.example.org/t/x/42']]);

        $this->assertStringContainsString('href="https://forum.example.org/t/x/42"', $html);
        $this->assertSame('', trim($field->renderStaticIfPermitted(['bf_discourse' => ['topicId' => '', 'topicUrl' => 'javascript:alert(1)']])));
    }

    public function testTheEditFormShowsTheSameForumButtonAsTheEntry(): void
    {
        $html = $this->field([])->renderInputIfPermitted(['bf_discourse' => ['topicId' => '', 'topicUrl' => 'https://forum.example.org/t/x/42']]);

        $this->assertStringContainsString('class="yw-btn yw-btn--primary"', $html);
        $this->assertStringContainsString('href="https://forum.example.org/t/x/42"', $html);
    }

    public function testAFormWithTheFieldSavesItsEntryEvenWithoutDiscourse(): void
    {
        $services = $this->getWiki()->services;
        $services->get(AuthenticationService::class)->connectFirstAdmin();
        $forms = $services->get(FormManager::class);
        $this->formId = (string)$forms->findNewId();
        $forms->create([
            'id' => $this->formId,
            'label' => 'Discourse field test',
            'template' => [
                ['type' => 'texte', 'name' => 'bf_titre', 'label' => 'Titre'],
                ['type' => 'discourse', 'name' => 'bf_discourse', 'label' => 'Forum', 'category' => '5'],
            ],
            'entry_title_template' => '{{bf_titre}}',
        ]);

        $prepared = $forms->getOne($this->formId)['prepared'];
        $this->assertInstanceOf(DiscourseField::class, $prepared[1]);
        $this->assertSame('5', $prepared[1]->getCategory());
        $this->assertStringContainsString(_t('DISCOURSE_FIELD_WILL_CREATE'), $prepared[1]->renderInputIfPermitted([]));

        $entry = $services->get(EntryManager::class)->create($this->formId, ['bf_titre' => 'Discourse field test entry']);
        $this->entryTag = (string)$entry['tag'];

        $this->assertNotSame('', $this->entryTag);
        $this->assertSame(['topicId' => '', 'topicUrl' => ''], $entry['bf_discourse']);
        $this->assertSame(_t('DISCOURSE_API_NOT_CONFIGURED'), $services->get(FlashMessageService::class)->getMessage());
    }
}
