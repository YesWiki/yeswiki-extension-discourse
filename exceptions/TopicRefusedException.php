<?php

namespace YesWiki\Discourse\Exception;

/** Discourse refused the topic itself, a title too short or unclear, with its own message. */
class TopicRefusedException extends \RuntimeException
{
}
