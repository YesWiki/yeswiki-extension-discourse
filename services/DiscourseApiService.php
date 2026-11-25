<?php

namespace YesWiki\Discourse\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use YesWiki\Discourse\Exception\TopicRefusedException;

/** Calls to the Discourse REST API with the admin key from discourse_connect.api_key. */
class DiscourseApiService
{
    public const CATEGORY_NAME_MAX = 50;

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
     * @throws TopicRefusedException with Discourse's own error message when the topic is refused
     * @throws \RuntimeException     when Discourse does not answer
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
            if ($status === 422 && $errors !== '') {
                throw new TopicRefusedException($errors);
            }
            throw new \RuntimeException($errors !== '' ? $errors : "Discourse answered HTTP $status");
        }

        $slug = is_string($data['topic_slug'] ?? null) && $data['topic_slug'] !== '' ? $data['topic_slug'] : 'topic';

        return [
            'topicId' => (string)$data['topic_id'],
            'topicUrl' => $this->baseUrl() . '/t/' . rawurlencode($slug) . '/' . (int)$data['topic_id'],
        ];
    }

    /**
     * Creates a category under $parent, as the configured api_username, with the parent's colours when it can read them.
     *
     * @return array{categoryId: string, categoryUrl: string}
     *
     * @throws TopicRefusedException with Discourse's own error message when the category is refused
     * @throws \RuntimeException     when Discourse does not answer
     */
    public function createCategory(string $name, string $parent): array
    {
        $body = ['name' => mb_substr($name, 0, self::CATEGORY_NAME_MAX), 'color' => '0088CC', 'text_color' => 'FFFFFF'];
        if (ctype_digit($parent)) {
            $body['parent_category_id'] = (int)$parent;
            try {
                $colours = $this->categoryData($parent);
                $body['color'] = is_string($colours['color'] ?? null) && $colours['color'] !== '' ? $colours['color'] : $body['color'];
                $body['text_color'] = is_string($colours['text_color'] ?? null) && $colours['text_color'] !== '' ? $colours['text_color'] : $body['text_color'];
            } catch (\RuntimeException $e) {
            }
        }

        $response = $this->client->request('POST', $this->baseUrl() . '/categories.json', [
            'headers' => $this->headers($this->apiUsername()),
            'json' => $body,
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        $category = is_array($data['category'] ?? null) ? $data['category'] : [];

        if ($status < 200 || $status >= 300 || empty($category['id'])) {
            $errors = is_array($data['errors'] ?? null) ? implode(' ', $data['errors']) : '';
            if ($status === 422 && $errors !== '') {
                throw new TopicRefusedException($errors);
            }
            throw new \RuntimeException($errors !== '' ? $errors : "Discourse answered HTTP $status");
        }

        $slug = is_string($category['slug'] ?? null) && $category['slug'] !== '' ? $category['slug'] : '-';

        return [
            'categoryId' => (string)$category['id'],
            'categoryUrl' => $this->baseUrl() . '/c/' . rawurlencode($slug) . '/' . (int)$category['id'],
        ];
    }

    /** Deletes a category that holds nothing but its description topic; says nothing when Discourse refuses. */
    public function deleteCategory(string $categoryId): void
    {
        try {
            $this->client->request('DELETE', $this->baseUrl() . '/categories/' . (int)$categoryId . '.json', [
                'headers' => $this->headers($this->apiUsername()),
            ])->getStatusCode();
        } catch (\Throwable $e) {
        }
    }

    /**
     * A category's counts and its latest topics, without the topic that describes the category.
     *
     * @return array{topics: int, posts: int, latest: list<array{id: string, title: string, url: string, replies: int, lastPostedAt: string}>}
     *
     * @throws \RuntimeException when Discourse does not answer with the category
     */
    public function category(string $categoryId): array
    {
        $category = $this->categoryData($categoryId);
        $about = preg_match('#/(\d+)$#', (string)($category['topic_url'] ?? ''), $match) === 1 ? $match[1] : '';

        $response = $this->client->request('GET', $this->baseUrl() . '/latest.json?category=' . (int)$categoryId, [
            'headers' => $this->headers($this->apiUsername()),
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status !== 200 || !is_array($data['topic_list']['topics'] ?? null)) {
            throw new \RuntimeException("Discourse answered HTTP $status for the topics of category $categoryId");
        }

        $latest = [];
        foreach ($data['topic_list']['topics'] as $topic) {
            if (!is_array($topic) || (int)($topic['category_id'] ?? 0) !== (int)$categoryId || empty($topic['id']) || (string)$topic['id'] === $about) {
                continue;
            }
            $slug = is_string($topic['slug'] ?? null) && $topic['slug'] !== '' ? $topic['slug'] : 'topic';
            $latest[] = [
                'id' => (string)$topic['id'],
                'title' => is_string($topic['title'] ?? null) ? $topic['title'] : '',
                'url' => $this->baseUrl() . '/t/' . rawurlencode($slug) . '/' . (int)$topic['id'],
                'replies' => max(0, (int)($topic['posts_count'] ?? 1) - 1),
                'lastPostedAt' => is_string($topic['last_posted_at'] ?? null) ? $topic['last_posted_at'] : '',
            ];
        }

        return [
            'topics' => (int)($category['topic_count'] ?? count($latest)),
            'posts' => (int)($category['post_count'] ?? 0),
            'latest' => $latest,
        ];
    }

    /**
     * Sets a topic's tags as the configured api_username, whose rights do not depend on the topic's author.
     *
     * @param list<string> $tags
     *
     * @throws \RuntimeException with Discourse's own error message when the tags are refused
     */
    public function tagTopic(string $topicId, array $tags): void
    {
        $response = $this->client->request('PUT', $this->baseUrl() . '/t/-/' . (int)$topicId . '.json', [
            'headers' => $this->headers($this->apiUsername()),
            'json' => ['tags' => $tags],
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $data = json_decode($response->getContent(false), true);
            $errors = is_array($data['errors'] ?? null) ? implode(' ', $data['errors']) : '';
            throw new \RuntimeException($errors !== '' ? $errors : "Discourse answered HTTP $status");
        }
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
            'headers' => $this->headers($this->apiUsername()),
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
     * The forum's categories, by id, a subcategory named after its parent.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException when Discourse does not answer with its categories
     */
    public function categories(): array
    {
        $response = $this->client->request('GET', $this->baseUrl() . '/site.json', [
            'headers' => $this->headers($this->apiUsername()),
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status !== 200 || !is_array($data['categories'] ?? null)) {
            throw new \RuntimeException("Discourse answered HTTP $status for its categories");
        }

        $names = [];
        $parents = [];
        foreach ($data['categories'] as $category) {
            if (is_array($category) && is_int($category['id'] ?? null) && is_string($category['name'] ?? null)) {
                $names[$category['id']] = $category['name'];
                $parents[$category['id']] = is_int($category['parent_category_id'] ?? null) ? $category['parent_category_id'] : null;
            }
        }

        $categories = [];
        foreach ($names as $id => $name) {
            $parent = $parents[$id];
            $categories[(string)$id] = $parent !== null && isset($names[$parent]) ? $names[$parent] . ' › ' . $name : $name;
        }

        return $categories;
    }

    /**
     * The names of the forum's tags.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when Discourse does not answer with its tags
     */
    public function tags(): array
    {
        $response = $this->client->request('GET', $this->baseUrl() . '/tags.json', [
            'headers' => $this->headers($this->apiUsername()),
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status !== 200 || !is_array($data['tags'] ?? null)) {
            throw new \RuntimeException("Discourse answered HTTP $status for its tags");
        }

        $tags = [];
        foreach ($data['tags'] as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? $tag['text'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $tags[] = $name;
            }
        }

        return $tags;
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
        $fallback = $this->apiUsername();
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

    /** @return array<string, mixed> the category as /c/{id}/show.json gives it */
    private function categoryData(string $categoryId): array
    {
        $response = $this->client->request('GET', $this->baseUrl() . '/c/' . (int)$categoryId . '/show.json', [
            'headers' => $this->headers($this->apiUsername()),
        ]);
        $status = $response->getStatusCode();
        $data = json_decode($response->getContent(false), true);
        if ($status !== 200 || !is_array($data['category'] ?? null)) {
            throw new \RuntimeException("Discourse answered HTTP $status for category $categoryId");
        }

        return $data['category'];
    }

    private function apiUsername(): string
    {
        return $this->config('api_username') !== '' ? $this->config('api_username') : 'system';
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
