<?php

namespace YesWiki\Discourse\Service;

use YesWiki\Content\Entity\ExtendsFormBuilder;

/** Puts the `discourse` field in the form builder, with the forum's categories to choose from. */
class DiscourseFormBuilder implements ExtendsFormBuilder
{
    public function __construct(private DiscourseApiService $api)
    {
    }

    public function formBuilderScripts(): array
    {
        return ['extensions/discourse/javascripts/form-builder-discourse.js'];
    }

    public function formBuilderData(): array
    {
        $configured = $this->api->isConfigured();
        try {
            $categories = $configured ? $this->api->categories() : [];
        } catch (\Throwable $e) {
            $categories = [];
        }

        return ['discourse' => ['configured' => $configured, 'categories' => $categories]];
    }
}
