<?php

namespace YesWiki\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use YesWiki\Kernel\Service\Journal;

/** A topic's or a category's activity, kept a quarter of an hour in cache/discourse/ so that a page of cards asks Discourse once. */
class TopicActivity
{
    public const TTL = 900;
    public const ERROR_TTL = 300;
    public const CATEGORY_TOPICS = 30;

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
        if (!ctype_digit($topicId)) {
            return null;
        }

        return $this->cached($topicId, $fetch, fn () => $this->api->topic($topicId));
    }

    /**
     * A category's counts, latest topics, and the posts per day of the topics active in the weeks a sparkline shows.
     *
     * @return array<string, mixed>|null topics, posts, latest, postsByDaysAgo and fetchedAt
     */
    public function category(string $categoryId, bool $fetch = true): ?array
    {
        if (!ctype_digit($categoryId)) {
            return null;
        }

        return $this->cached('category-' . $categoryId, $fetch, function () use ($categoryId): array {
            $category = $this->api->category($categoryId);
            $since = time() - ActivityPresenter::WEEKS * 7 * 86400;
            $days = [];
            foreach (array_slice($category['latest'], 0, self::CATEGORY_TOPICS) as $topic) {
                if ($topic['lastPostedAt'] === '' || strtotime($topic['lastPostedAt']) < $since) {
                    continue;
                }
                foreach ($this->get($topic['id'])['postsByDaysAgo'] ?? [] as $daysAgo => $posts) {
                    $days[$daysAgo] = ($days[$daysAgo] ?? 0) + (int)$posts;
                }
            }

            return $category + ['postsByDaysAgo' => $days];
        });
    }

    /** @return array<string, mixed>|null what $fetch gives, kept TTL seconds, or null for ERROR_TTL seconds when it fails */
    private function cached(string $key, bool $fetch, callable $read): ?array
    {
        if (!$this->api->isConfigured()) {
            return null;
        }

        $cached = $this->read($key);
        if ($cached !== null) {
            return $cached['activity'];
        }
        if (!$fetch) {
            return null;
        }

        try {
            $activity = $read() + ['fetchedAt' => time()];
        } catch (\Throwable $e) {
            $this->journal->warning("discourse: no activity for $key: " . $e->getMessage());
            $this->write($key, null, self::ERROR_TTL);

            return null;
        }
        $this->write($key, $activity, self::TTL);

        return $activity;
    }

    /** Whether get() can answer without asking Discourse. */
    public function isCached(string $topicId): bool
    {
        return ctype_digit($topicId) && $this->read($topicId) !== null;
    }

    /** @return array{activity: array<string, mixed>|null}|null */
    private function read(string $key): ?array
    {
        $file = $this->file($key);
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
    private function write(string $key, ?array $activity, int $ttl): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
            return;
        }
        $file = $this->file($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['expires' => time() + $ttl, 'activity' => $activity])) !== false) {
            @rename($tmp, $file);
        }
    }

    private function file(string $key): string
    {
        $config = $this->params->has('discourse_connect') ? $this->params->get('discourse_connect') : [];
        $secret = is_array($config) ? (string)($config['api_key'] ?? '') . (string)($config['url'] ?? '') : '';

        return $this->cacheDir . '/' . hash_hmac('sha256', $key, $secret) . '.json';
    }
}
