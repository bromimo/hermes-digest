<?php
declare(strict_types=1);

namespace App\Tests\Habr;

use App\Habr\ArticleParser;
use App\Habr\HabrClientInterface;
use App\Habr\HabrService;
use App\Habr\HabrUnavailable;
use App\Habr\RssParser;
use App\Habr\SearchApiParser;
use App\Support\PeriodParser;
use App\Support\UrlNormalizer;
use App\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class HabrServiceTest extends TestCase
{
    private function service(HabrClientInterface $client): HabrService
    {
        $urls  = new UrlNormalizer();
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-14T00:00:00+00:00'));
        return new HabrService(
            $client,
            new RssParser($urls),
            new SearchApiParser(),
            new ArticleParser(),
            new PeriodParser($clock),
            $urls,
        );
    }

    public function test_get_news_filters_by_period_and_limits(): void
    {
        $rss    = file_get_contents(__DIR__ . '/../Fixtures/rss_programming.xml');
        $client = new class($rss) implements HabrClientInterface {
            public function __construct(private string $rss) {}
            public function getRss(string $path): string { return $this->rss; }
            public function getSearch(string $query, int $page = 1): string { return '{}'; }
            public function getArticle(string $url): string { return ''; }
        };

        // период 7d от 2026-06-13 — исключает элемент от 2026-06-01
        $res = $this->service($client)->getNews('programming', '7d', 7);

        self::assertArrayNotHasKey('error', $res);
        self::assertSame(1, $res['count']);
        self::assertSame('1047108', (new UrlNormalizer())->id($res['items'][0]['url']));
    }

    public function test_search_habr_filters_by_period(): void
    {
        $json   = file_get_contents(__DIR__ . '/../Fixtures/kek_search.json');
        $client = new class($json) implements HabrClientInterface {
            public function __construct(private string $json) {}
            public function getRss(string $path): string { return ''; }
            public function getSearch(string $query, int $page = 1): string { return $page === 1 ? $this->json : '{}'; }
            public function getArticle(string $url): string { return ''; }
        };

        // окно 7d оставляет только элемент от 2026-06-13, отбрасывает 2026-05-20
        $res = $this->service($client)->searchHabr('ML', '7d', 10);
        self::assertSame(1, $res['count']);
        self::assertSame('https://habr.com/ru/articles/1047116/', $res['items'][0]['url']);
    }

    public function test_fetch_article_returns_object(): void
    {
        $html   = file_get_contents(__DIR__ . '/../Fixtures/article.html');
        $client = new class($html) implements HabrClientInterface {
            public function __construct(private string $html) {}
            public function getRss(string $path): string { return ''; }
            public function getSearch(string $query, int $page = 1): string { return '{}'; }
            public function getArticle(string $url): string { return $this->html; }
        };

        $res = $this->service($client)->fetchArticle('https://habr.com/ru/articles/1047108/');
        self::assertArrayNotHasKey('error', $res);
        self::assertSame('opium', $res['author']);
        self::assertSame('https://habr.com/share/publication/1047108/abc123/', $res['image']);
    }

    public function test_get_news_returns_error_envelope_on_source_down(): void
    {
        $client = new class implements HabrClientInterface {
            public function getRss(string $path): string { throw new HabrUnavailable('down'); }
            public function getSearch(string $query, int $page = 1): string { return '{}'; }
            public function getArticle(string $url): string { return ''; }
        };

        $res = $this->service($client)->getNews('programming', '7d', 7);
        self::assertSame([], $res['items']);
        self::assertSame(0, $res['count']);
        self::assertArrayHasKey('error', $res);
    }

    public function test_search_habr_returns_error_when_first_page_down(): void
    {
        $client = new class implements HabrClientInterface {
            public function getRss(string $path): string { return ''; }
            public function getSearch(string $query, int $page = 1): string { throw new HabrUnavailable('down'); }
            public function getArticle(string $url): string { return ''; }
        };

        $res = $this->service($client)->searchHabr('ML', '7d', 10);
        self::assertSame([], $res['items']);
        self::assertSame(0, $res['count']);
        self::assertArrayHasKey('error', $res);
    }

    public function test_search_habr_keeps_partial_results_when_later_page_fails(): void
    {
        $json   = file_get_contents(__DIR__ . '/../Fixtures/kek_search.json');
        $client = new class($json) implements HabrClientInterface {
            public function __construct(private string $json) {}
            public function getRss(string $path): string { return ''; }
            public function getSearch(string $query, int $page = 1): string
            {
                if ($page > 1) {
                    throw new HabrUnavailable('later page down');
                }
                return $this->json;
            }
            public function getArticle(string $url): string { return ''; }
        };

        // clock is 2026-06-14, window 7d → only the June-13 item survives
        $res = $this->service($client)->searchHabr('ML', '7d', 10);
        self::assertSame(1, $res['count']);
        self::assertArrayNotHasKey('error', $res);
    }

    public function test_fetch_article_error_envelope_includes_url(): void
    {
        $client = new class implements HabrClientInterface {
            public function getRss(string $path): string { return ''; }
            public function getSearch(string $query, int $page = 1): string { return '{}'; }
            public function getArticle(string $url): string { throw new HabrUnavailable('gone'); }
        };

        $res = $this->service($client)->fetchArticle('https://habr.com/ru/articles/1047108/');
        self::assertSame('https://habr.com/ru/articles/1047108/', $res['url']);
        self::assertArrayHasKey('error', $res);
    }
}