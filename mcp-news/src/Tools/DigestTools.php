<?php
declare(strict_types=1);

namespace App\Tools;

use App\Habr\HabrService;
use App\Habr\HabrServiceFactory;
use PhpMcp\Server\Attributes\McpTool;

final class DigestTools
{
    /**Инструменты MCP-сервера: три метода, делегирующих запросы к HabrService.*/
    private HabrService $habr;

    public function __construct()
    {
        $this->habr = HabrServiceFactory::fromEnv();
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
}
