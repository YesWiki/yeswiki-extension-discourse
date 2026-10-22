<?php

namespace YesWiki\Discourse\Service;

use YesWiki\Content\Entity\ExtendsFormBuilder;

/** Puts the `discourse` field in the form builder, with the forum's categories and tags to choose from. */
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

        return ['discourse' => [
            'configured' => $configured,
            'categories' => $configured ? $this->orNothing(fn () => $this->api->categories()) : [],
            'tags' => $configured ? $this->orNothing(fn () => $this->api->tags()) : [],
        ]];
    }

    /** What $read gives, or nothing when the forum does not answer. */
    private function orNothing(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
