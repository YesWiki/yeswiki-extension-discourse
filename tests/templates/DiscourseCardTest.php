<?php

namespace YesWiki\Test\Discourse\Templates;

use YesWiki\Content\Service\FormManager;
use YesWiki\Identity\Service\AuthenticationService;
use YesWiki\Render\Service\ActionRunner;
use YesWiki\Render\Service\PresentationCatalog;
use YesWiki\Test\Core\YesWikiTestCase;

require_once 'tests/YesWikiTestCase.php';

/** The card presentation with forum activity, as a page's {{entrylist}} draws it. */
class DiscourseCardTest extends YesWikiTestCase
{
    private string $formId = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!isset($this->getWiki()->services->get(\YesWiki\Kernel\Service\ExtensionRegistry::class)->all()['discourse'])) {
            $this->markTestSkipped('discourse is not switched on in this wiki');
        }
    }

    protected function tearDown(): void
    {
        $services = $this->getWiki()->services;
        if ($this->formId !== '') {
            $services->get(FormManager::class)->delete($this->formId);
        }
        $services->get(AuthenticationService::class)->logout();
        parent::tearDown();
    }

    public function testTheCardWithActivityIsOfferedAmongTheCards(): void
    {
        $presentation = $this->getWiki()->services->get(PresentationCatalog::class)->get('discourse-card');

        $this->assertNotNull($presentation);
        $this->assertSame('card', $presentation->category);
        $this->assertSame(_t('DISCOURSE_PRESENTATION_CARD'), $presentation->label);
    }

    public function testTheListAsksEachCardForItsEntrysActivity(): void
    {
        $services = $this->getWiki()->services;
        $services->get(AuthenticationService::class)->connectFirstAdmin();
        $forms = $services->get(FormManager::class);
        $this->formId = (string)$forms->findNewId();
        $forms->create([
            'id' => $this->formId,
            'label' => 'Discourse card test',
            'template' => [
                ['type' => 'texte', 'name' => 'bf_titre', 'label' => 'Titre'],
                ['type' => 'discourse', 'name' => 'bf_discourse', 'label' => 'Forum'],
            ],
            'entry_title_template' => '{{bf_titre}}',
        ]);

        $html = $services->get(ActionRunner::class)->action('entrylist', [
            'id' => $this->formId,
            'template' => 'discourse-card',
            'dynamic' => 'true',
        ]);

        $this->assertStringContainsString('<discourse-activity', $html);
        $this->assertStringContainsString(':entry="entry.tag"', $html);
        $this->assertStringContainsString('bazar-cards-container', $html);
    }
}
