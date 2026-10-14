<?php

namespace YesWiki\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Kernel\Service\Journal;

/** A topic's activity, kept a quarter of an hour in cache/discourse/ so that a page of cards asks Discourse once. */
class TopicActivity
{
    public const TTL = 900;
    public const ERROR_TTL = 300;

    private string $cacheDir;

    public function __construct(
        private readonly DiscourseApiService $api,
        private readonly ParameterBagInterface $params,
        private readonly Journal $journal,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? (\defined('YESWIKI_INSTANCE_DIR') ? YESWIKI_INSTANCE_DIR : getcwd()) . '/cache/discourse';
    }

    /**
     * The cached activity, fetched from Discourse when $fetch allows it and the cache is cold.
     *
     * @return array<string, mixed>|null posts, replies, views, likes, participants, lastPostedAt, postsByDaysAgo and fetchedAt
     */
    public function get(string $topicId, bool $fetch = true): ?array
    {
        if (!ctype_digit($topicId) || !$this->api->isConfigured()) {
            return null;
        }

        $cached = $this->read($topicId);
        if ($cached !== null) {
            return $cached['activity'];
        }
        if (!$fetch) {
            return null;
        }

        try {
            $activity = $this->api->topic($topicId) + ['fetchedAt' => time()];
        } catch (\Throwable $e) {
            $this->journal->warning("discourse: no activity for topic $topicId: " . $e->getMessage());
            $this->write($topicId, null, self::ERROR_TTL);

            return null;
        }
        $this->write($topicId, $activity, self::TTL);

        return $activity;
    }

    /** Whether get() can answer without asking Discourse. */
    public function isCached(string $topicId): bool
    {
        return ctype_digit($topicId) && $this->read($topicId) !== null;
    }

    /** @return array{activity: array<string, mixed>|null}|null */
    private function read(string $topicId): ?array
    {
        $file = $this->file($topicId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || (int)($data['expires'] ?? 0) < time()) {
            return null;
        }
        if (is_array($data['activity'] ?? null)) {
            $data['activity']['postsByDaysAgo'] = array_map('intval', $data['activity']['postsByDaysAgo'] ?? []);
        }

        return ['activity' => is_array($data['activity'] ?? null) ? $data['activity'] : null];
    }

    /** @param array<string, mixed>|null $activity */
    private function write(string $topicId, ?array $activity, int $ttl): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        $file = $this->file($topicId);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['expires' => time() + $ttl, 'activity' => $activity])) !== false) {
            @rename($tmp, $file);
        }
    }

    private function file(string $topicId): string
    {
        $config = $this->params->has('discourse_connect') ? $this->params->get('discourse_connect') : [];
        $key = is_array($config) ? (string)($config['api_key'] ?? '') . (string)($config['url'] ?? '') : '';

        return $this->cacheDir . '/' . hash_hmac('sha256', $topicId, $key) . '.json';
    }
}
