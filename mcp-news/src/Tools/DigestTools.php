<?php
declare(strict_types=1);

namespace App\Tools;

use App\Dedup\DedupStore;
use App\Habr\HabrService;
use App\Dedup\DedupService;
use App\Support\UrlNormalizer;
use App\Habr\HabrServiceFactory;
use PhpMcp\Server\Attributes\McpTool;

final class DigestTools
{
    /**Инструменты MCP-сервера: поиск/лента/статья через HabrService и серверный дедуп (DedupService + DedupStore).*/
    private HabrService $habr;

    private DedupService $dedup;

    private DedupStore $store;

    public function __construct()
    {
        $this->habr = HabrServiceFactory::fromEnv();
        $this->dedup = new DedupService(new UrlNormalizer());
        $dbPath = getenv('DEDUP_DB_PATH') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hermes-dedup.sqlite');
        $this->store = new DedupStore($dbPath);
    }

    /**
     * Get recent Habr articles for a topic from RSS feeds, filtered by period. Use for a broad news stream by hub/topic when the user does not give a precise search query.
     *
     * @param string $topic Topic or Habr hub slug. Examples: "machine_learning", "artificial_intelligence", "programming", "php", or a free phrase like "AI/ML" / "машинное обучение".
     * @param string $period Time window, general form "N<unit>" with unit h|d|w|m (hours, days, weeks, months): e.g. "24h", "7d", "3d", "2w", "30d", "6m". Or an absolute date range "YYYY-MM-DD..YYYY-MM-DD". Default "7d". Date filtering is performed on the MCP side.
     * @param int $limit Max number of articles to return, 1..30. Default 7.
     * @return array{items: array<int, array<string, mixed>>, count: int, since: string, until: string, error?: string}
     */
    #[McpTool(name: 'get_news')]
    public function getNews(string $topic, string $period = '7d', int $limit = 7): array
    {
        return $this->habr->getNews($topic, $period, $limit);
    }

    /**
     * Search Habr articles by a query string, sorted newest-first, filtered by period. Prefer this when the user gives a concrete topic/keywords (e.g. "vector databases", "LLM agents"). Returns lightweight items; call fetch_article to get full text.
     *
     * @param string $query Free-text search query in Russian or English.
     * @param string $period Time window, general form "N<unit>" with unit h|d|w|m (hours, days, weeks, months): e.g. "24h", "7d", "3d", "2w", "30d", "6m". Or an absolute date range "YYYY-MM-DD..YYYY-MM-DD". Default "7d". Date filtering is performed on the MCP side.
     * @param int $limit Max number of articles to return, 1..30. Default 20.
     * @return array{items: array<int, array<string, mixed>>, count: int, since: string, until: string, error?: string}
     */
    #[McpTool(name: 'search_habr')]
    public function searchHabr(string $query, string $period = '7d', int $limit = 20): array
    {
        return $this->habr->searchHabr($query, $period, $limit);
    }

    /**
     * Fetch the full text and metadata of a single Habr article by URL. Use to annotate (TL;DR) a candidate article and to get its lead image (for the digest cover).
     *
     * @param string $url Full Habr article URL, e.g. "https://habr.com/ru/articles/1047108/".
     * @return array{title: ?string, text: string, author: ?string, published_at: ?string, image: ?string, url?: string, error?: string}
     */
    #[McpTool(name: 'fetch_article')]
    public function fetchArticle(string $url): array
    {
        return $this->habr->fetchArticle($url);
    }

    /**
     * Remove already-shown Habr candidates using the server-side per-topic dedup store. Call once after merging search_habr/get_news results and before selecting articles to annotate. Pass the topic `key`; the MCP server reads the stored shown IDs itself (you do NOT pass or manage any memory). Attaches a numeric `id` to each returned item and drops within-batch duplicates.
     *
     * @param string $key Per-topic dedup key, e.g. "digest:<chat_id>:<topic-slug>" (topic lowercased, spaces→dashes; if chat_id is unknown use "digest:<topic-slug>"). Use the SAME key in dedup_commit.
     * @param array $candidates Merged candidate items from search_habr/get_news, each with at least a `url`.
     * @return array{fresh: array<int, array<string, mixed>>, candidate_count: int, removed_count: int}
     */
    #[McpTool(name: 'dedup_filter')]
    public function dedupFilter(string $key, array $candidates): array
    {
        return $this->dedup->filter($candidates, $this->store->seenIds($key));
    }

    /**
     * Record the IDs actually shown in this digest into the server-side per-topic dedup store (atomic; persists across requests). Call once after the final article set is assembled, with the SAME `key` as dedup_filter and the shown `id`s. The server merges them with previously stored IDs and trims a sliding window — there is NO memory to write yourself.
     *
     * @param string $key Per-topic dedup key (same one passed to dedup_filter).
     * @param array $shown_ids Numeric IDs of articles included in the digest (take `id` from dedup_filter's `fresh`). Null/non-numeric ignored.
     * @param int $keep Max IDs to retain in the sliding window. Default 50.
     * @return array{id_count: int}
     */
    #[McpTool(name: 'dedup_commit')]
    public function dedupCommit(string $key, array $shown_ids, int $keep = 50): array
    {
        return $this->store->transaction(function () use ($key, $shown_ids, $keep): array {
            $merged = $this->dedup->merge($this->store->seenIds($key), $shown_ids, $keep);
            $this->store->save($key, $merged);
            return ['id_count' => count($merged)];
        });
    }
}