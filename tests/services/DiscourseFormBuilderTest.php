<?php

namespace YesWiki\Test\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use YesWiki\Content\Service\FormBuilderExtensions;
use YesWiki\Discourse\Service\DiscourseApiService;
use YesWiki\Discourse\Service\DiscourseFormBuilder;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** What the form builder receives to offer the discourse field. */
class DiscourseFormBuilderTest extends YesWikiTestCase
{
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
    }

    /** @param list<MockResponse> $responses */
    private function builder(array $responses, bool $configured = true): DiscourseFormBuilder
    {
        $this->requests = 0;
        $client = new MockHttpClient(function () use (&$responses): MockResponse {
            $this->requests++;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new DiscourseFormBuilder(new DiscourseApiService(new ParameterBag(['discourse_connect' => $configured
            ? ['url' => 'https://forum.example.org', 'api_key' => 'k3y']
            : []]), $client));
    }

    public function testTheBuilderPageLoadsTheFieldsScript(): void
    {
        $designer = $this->getWiki()->services->get(FormBuilderExtensions::class)->forDesigner();

        $this->assertContains('extensions/discourse/javascripts/form-builder-discourse.js', $designer['scripts']);
        $this->assertArrayHasKey('discourse', $designer['data']);
    }

    public function testTheForumsCategoriesAreOffered(): void
    {
        $data = $this->builder([new MockResponse(json_encode(['categories' => [['id' => 5, 'name' => 'CARe']]]))])->formBuilderData();

        $this->assertSame(['discourse' => ['configured' => true, 'categories' => ['5' => 'CARe']]], $data);
    }

    public function testAForumThatDoesNotAnswerLeavesTheCategoryToType(): void
    {
        $data = $this->builder([new MockResponse('', ['http_code' => 502])])->formBuilderData();

        $this->assertSame(['discourse' => ['configured' => true, 'categories' => []]], $data);
    }

    public function testAWikiWithoutApiSettingsAsksNothingOfTheForum(): void
    {
        $data = $this->builder([], false)->formBuilderData();

        $this->assertSame(['discourse' => ['configured' => false, 'categories' => []]], $data);
        $this->assertSame(0, $this->requests);
    }
}
