<?php

namespace YesWiki\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Calls to the Discourse REST API with the admin key from discourse_connect.api_key. */
class DiscourseApiService
{
    private HttpClientInterface $client;

    public function __construct(private readonly ParameterBagInterface $params, ?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create(['timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0]);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->config('api_key') !== '';
    }

    /**
     * Posts a new topic, as the Discourse account linked to $wikiUserName when there is one.
     *
     * @return array{topicId: string, topicUrl: string}
     *
     * @throws \RuntimeException with Discourse's own error message when the topic is refused
     */
    public function createTopic(string $title, string $raw, string $category, string $wikiUserName): array
    {
        $body = ['title' => $title, 'raw' => $raw];
        if ($category !== '') {
            $body['category'] = (int)$category;
        }

        $response = $this->client->request('POST', $this->baseUrl() . '/posts.json', [
            'headers' => $this->headers($this->authorFor($wikiUserName)),
            'json' => $body,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        $data = is_array($data) ? $data : [];

        if ($status < 200 || $status >= 300 || empty($data['topic_id'])) {
            $errors = isset($data['errors']) && is_array($data['errors']) ? implode(' ', $data['errors']) : '';
            throw new \RuntimeException($errors !== '' ? $errors : "Discourse answered HTTP $status");
        }

        $slug = is_string($data['topic_slug'] ?? null) && $data['topic_slug'] !== '' ? $data['topic_slug'] : 'topic';

        return [
            'topicId' => (string)$data['topic_id'],
            'topicUrl' => $this->baseUrl() . '/t/' . rawurlencode($slug) . '/' . (int)$data['topic_id'],
        ];
    }

    /**
     * A topic's counts, and its posts per day counted back from now, read off timeline_lookup.
     *
     * @return array{posts: int, replies: int, views: int, likes: int, participants: int, lastPostedAt: string, postsByDaysAgo: array<int, int>}
     *
     * @throws \RuntimeException when Discourse does not answer with the topic
     */
    public function topic(string $topicId): array
    {
        $response = $this->client->request('GET', $this->baseUrl() . '/t/' . (int)$topicId . '.json', [
            'headers' => $this->headers($this->config('api_username') !== '' ? $this->config('api_username') : 'system'),
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status !== 200 || !is_array($data) || !isset($data['posts_count'])) {
            throw new \RuntimeException("Discourse answered HTTP $status for topic $topicId");
        }

        $posts = (int)$data['posts_count'];
        $stream = $data['post_stream']['stream'] ?? null;
        $lookup = is_array($data['timeline_lookup'] ?? null) ? $data['timeline_lookup'] : [];

        return [
            'posts' => $posts,
            'replies' => max(0, $posts - 1),
            'views' => (int)($data['views'] ?? 0),
            'likes' => (int)($data['like_count'] ?? 0),
            'participants' => (int)($data['participant_count'] ?? 0),
            'lastPostedAt' => is_string($data['last_posted_at'] ?? null) ? $data['last_posted_at'] : '',
            'postsByDaysAgo' => self::postsByDaysAgo($lookup, is_array($stream) ? count($stream) : $posts),
        ];
    }

    /**
     * Discourse emits [index, days_ago] each time the age changes, so a day holds the posts up to the next index.
     *
     * @param array<mixed> $lookup
     *
     * @return array<int, int>
     */
    public static function postsByDaysAgo(array $lookup, int $streamLength): array
    {
        $points = array_values(array_filter(
            $lookup,
            static fn ($point) => is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null)
        ));
        $days = [];
        foreach ($points as $i => $point) {
            $next = isset($points[$i + 1]) ? (int)$points[$i + 1][0] : $streamLength + 1;
            $count = max(0, $next - (int)$point[0]);
            $daysAgo = max(0, (int)$point[1]);
            $days[$daysAgo] = ($days[$daysAgo] ?? 0) + $count;
        }

        return $days;
    }

    /** The Discourse username whose external_id is $wikiUserName, or the configured api_username. */
    public function authorFor(string $wikiUserName): string
    {
        $fallback = $this->config('api_username') !== '' ? $this->config('api_username') : 'system';
        if ($wikiUserName === '') {
            return $fallback;
        }

        try {
            $response = $this->client->request(
                'GET',
                $this->baseUrl() . '/u/by-external/' . rawurlencode($wikiUserName) . '.json',
                ['headers' => $this->headers($fallback)]
            );
            if ($response->getStatusCode() !== 200) {
                return $fallback;
            }
            $data = json_decode($response->getContent(false), true);
            $username = $data['user']['username'] ?? null;

            return is_string($username) && $username !== '' ? $username : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** @return array<string, string> */
    private function headers(string $username): array
    {
        return [
            'Api-Key' => $this->config('api_key'),
            'Api-Username' => $username,
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        return rtrim($this->config('url'), '/');
    }

    private function config(string $key): string
    {
        $config = $this->params->has('discourse_connect') ? $this->params->get('discourse_connect') : [];

        return is_array($config) && is_scalar($config[$key] ?? null) ? trim((string)$config[$key]) : '';
    }
}
