<?php

return [
    'DISCOURSE_INVALID_REQUEST' => 'Invalid or malformed SSO request.',
    'DISCOURSE_INVALID_SIGNATURE' => 'Invalid SSO signature.',
    'DISCOURSE_NOT_CONFIGURED' => 'Discourse Connect is not configured on this wiki.',
    'DISCOURSE_API_NOT_CONFIGURED' => 'The entry is saved, but no forum topic was created: discourse_connect.url and discourse_connect.api_key are not set on this wiki.',
    'DISCOURSE_TOPIC_FAILED' => 'The entry is saved, but the forum topic could not be created: %{error}',
    'DISCOURSE_TOPIC_REFUSED' => 'The entry is not saved: the forum will not make a topic of it. %{error}',
    'DISCOURSE_TAGS_FAILED' => 'The forum topic is open, but without its tags: %{error}',
    'DISCOURSE_TOPIC_BODY' => 'Discussion about the wiki entry [%{title}](%{url}).',
    'DISCOURSE_FIELD_DISCUSS' => 'Discuss on the forum',
    'DISCOURSE_FIELD_WILL_CREATE' => 'Saving this entry opens a topic about it on the forum. The forum refuses titles that are too short or unclear.',
    'DISCOURSE_ACTIVITY_REPLIES' => 'Replies: %{n}',
    'DISCOURSE_ACTIVITY_WEEK' => 'Posts from %{from} to %{to}: %{posts}',
    'DISCOURSE_ACTIVITY_SPARKLINE_LABEL' => 'Posts over the last %{weeks} weeks: %{posts}, this week: %{last}',
    'DISCOURSE_ACTIVITY_DATE_FORMAT' => 'm/d',
    'DISCOURSE_PRESENTATION_CARD' => 'Cards with forum activity',
];
