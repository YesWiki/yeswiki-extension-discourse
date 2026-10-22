<?php

return [
    'DISCOURSE_FORM_BUILDER_LABEL' => 'Forum discussion',
    'DISCOURSE_FORM_BUILDER_HINT' => 'Saving an entry that has no topic yet opens one on the Discourse forum, and the entry links to it.',
    'DISCOURSE_FORM_BUILDER_NOT_CONFIGURED' => 'discourse_connect.url and discourse_connect.api_key are not set on this wiki: no topic will be opened.',
    'DISCOURSE_FORM_BUILDER_CATEGORY' => 'Forum category',
    'DISCOURSE_FORM_BUILDER_NO_CATEGORY' => 'The forum\'s default one',
    'DISCOURSE_FORM_BUILDER_CATEGORY_HINT' => 'Topics for this form\'s entries are opened there.',
    'DISCOURSE_FORM_BUILDER_CATEGORY_ID_HINT' => 'The Discourse category number. The forum could not give its list.',
    'DISCOURSE_FORM_BUILDER_TAGS' => 'Topic tags',
    'DISCOURSE_FORM_BUILDER_TAGS_PLACEHOLDER' => 'workshop, path',
    'DISCOURSE_FORM_BUILDER_TAGS_HINT' => 'Comma separated. The api_username account sets them, and creates those the forum lacks if it may.',
    'DISCOURSE_FORM_BUILDER_TAGS_EXISTING' => 'Comma separated. Already on the forum: %{tags}',
    'DISCOURSE_FORM_BUILDER_TOPIC_BODY' => 'First post of the topic',
    'DISCOURSE_FORM_BUILDER_TOPIC_BODY_HINT' => 'In markdown. {{title}} gives the entry\'s title, {{url}} its address, and {{bf_…}} a field\'s value. Left empty, the post just links to the entry.',
];
