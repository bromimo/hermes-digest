<?php
declare(strict_types=1);

namespace App\Tools;

use App\Habr\HabrService;
use App\Dedup\DedupService;
use App\Support\UrlNormalizer;
use App\Habr\HabrServiceFactory;
use PhpMcp\Server\Attributes\McpTool;

final class DigestTools
{
    /**Инструменты MCP-сервера: поиск/лента/статья через HabrService и дедуп через DedupService.*/
    private HabrService $habr;

    private DedupService $dedup;

    public function __construct()
    {
        $this->habr = HabrServiceFactory::fromEnv();
        $this->dedup = new DedupService(new UrlNormalizer());
    }

    /**
     * Get recent Habr articles for a topic from RSS feeds, filtered by period. Use for a broad news stream by hub/topic when the user does not give a precise search query.
     *
     * @param string $topic Topic or Habr hub slug. Examples: "machine_learning", "artificial_intelligence", "programming", "php", or a free phrase like "AI/ML" / "машинное обучение".
     * @param string $period Time window. Examples: "24h" (last day), "7d" (last week), "30d" (last month), or a date range "YYYY-MM-DD..YYYY-MM-DD". Default "7d".
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
     * @param string $period Time window. Examples: "24h", "7d", "30d", or "YYYY-MM-DD..YYYY-MM-DD". Default "7d".
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
     * Remove already-shown Habr candidates against the per-topic "seen" memory blob. Call once after merging search_habr/get_news results and before selecting articles to annotate. Attaches an explicit numeric `id` to each returned item and drops within-batch duplicates.
     *
     * @param array $candidates Merged candidate items from search_habr/get_news, each with at least a `url`. Passed through as-is.
     * @param string $seen_blob Raw memory line for this chat+topic (snapshot of shown IDs), passed verbatim; "" if none. Opaque — do not parse it.
     * @return array{fresh: array<int, array<string, mixed>>, candidate_count: int, removed_count: int}
     */
    #[McpTool(name: 'dedup_filter')]
    public function dedupFilter(array $candidates, string $seen_blob = ''): array
    {
        return $this->dedup->filter($candidates, $seen_blob);
    }

    /**
     * Merge the IDs actually shown in this digest into the per-topic "seen" memory blob and return the updated memory line to store. Call once after the final article set is assembled. Keeps a sliding window of the most recent IDs. Write the returned `line` to memory: add it if there was no prior line, otherwise replace the prior line (use seen_blob verbatim as the old text).
     *
     * @param string $key Memory key for this chat+topic, e.g. "digest:<chat_id>:<topic-slug>".
     * @param array $shown_ids Numeric IDs of articles that made it into the digest (take `id` from dedup_filter's `fresh`). Null/non-numeric ignored.
     * @param string $seen_blob Prior raw memory line (same string passed to dedup_filter), or "". Opaque.
     * @param int $keep Max IDs to retain in the sliding window. Default 50.
     * @return array{line: string, id_count: int}
     */
    #[McpTool(name: 'dedup_commit')]
    public function dedupCommit(string $key, array $shown_ids, string $seen_blob = '', int $keep = 50): array
    {
        return $this->dedup->commit($key, $shown_ids, $seen_blob, $keep);
    }
}
