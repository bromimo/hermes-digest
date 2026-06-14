<?php
declare(strict_types=1);

namespace App\Tests\Dedup;

use App\Dedup\DedupService;
use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class DedupServiceTest extends TestCase
{
    private function service(): DedupService
    {
        return new DedupService(new UrlNormalizer());
    }

    public function test_filter_removes_seen_ids(): void
    {
        $r = $this->service()->filter(
            [
                ['url' => 'https://habr.com/ru/articles/100/'],
                ['url' => 'https://habr.com/ru/articles/200/'],
                ['url' => 'https://habr.com/ru/articles/300/'],
            ],
            'digest:1:t ids: 200'
        );
        self::assertSame([100, 300], array_column($r['fresh'], 'id'));
        self::assertSame(3, $r['candidate_count']);
        self::assertSame(1, $r['removed_count']);
    }

    public function test_filter_collapses_within_batch_duplicate_ids(): void
    {
        $r = $this->service()->filter(
            [
                ['url' => 'https://habr.com/ru/articles/100/'],
                ['url' => 'https://habr.com/ru/companies/otus/articles/100/?utm_source=rss'],
            ],
            ''
        );
        self::assertSame([100], array_column($r['fresh'], 'id'));
        self::assertSame(2, $r['candidate_count']);
        self::assertSame(0, $r['removed_count']);
    }

    public function test_filter_collapses_within_batch_duplicate_canonical_urls(): void
    {
        $r = $this->service()->filter(
            [
                ['url' => 'https://habr.com/ru/news/555/'],
                ['url' => 'https://habr.com/ru/news/555/?utm_source=rss'],
            ],
            ''
        );
        self::assertCount(1, $r['fresh']);
        self::assertNull($r['fresh'][0]['id']);
        self::assertSame(0, $r['removed_count']);
    }

    public function test_filter_empty_seen_returns_all(): void
    {
        $r = $this->service()->filter(
            [
                ['url' => 'https://habr.com/ru/articles/100/'],
                ['url' => 'https://habr.com/ru/articles/200/'],
            ],
            ''
        );
        self::assertSame([100, 200], array_column($r['fresh'], 'id'));
        self::assertSame(0, $r['removed_count']);
    }

    public function test_filter_non_article_url_passes_through_with_null_id(): void
    {
        $r = $this->service()->filter(
            [['url' => 'https://habr.com/ru/news/555/']],
            ''
        );
        self::assertCount(1, $r['fresh']);
        self::assertNull($r['fresh'][0]['id']);
    }

    public function test_filter_preserves_order(): void
    {
        $r = $this->service()->filter(
            [
                ['url' => 'https://habr.com/ru/articles/300/'],
                ['url' => 'https://habr.com/ru/articles/100/'],
                ['url' => 'https://habr.com/ru/articles/200/'],
            ],
            ''
        );
        self::assertSame([300, 100, 200], array_column($r['fresh'], 'id'));
    }

    public function test_filter_empty_candidates(): void
    {
        $r = $this->service()->filter([], '');
        self::assertSame([], $r['fresh']);
        self::assertSame(0, $r['candidate_count']);
        self::assertSame(0, $r['removed_count']);
    }

    public function test_filter_candidate_without_url(): void
    {
        $r = $this->service()->filter([['title' => 'no url']], '');
        self::assertCount(1, $r['fresh']);
        self::assertNull($r['fresh'][0]['id']);
    }

    public function test_filter_garbage_seen_blob_is_lenient(): void
    {
        $r = $this->service()->filter(
            [['url' => 'https://habr.com/ru/articles/100/']],
            'totally not a memory line'
        );
        self::assertSame([100], array_column($r['fresh'], 'id'));
    }

    public function test_commit_appends_new_ids(): void
    {
        $r = $this->service()->commit('digest:1:t', [400], 'digest:1:t ids: 100, 200');
        self::assertSame('digest:1:t ids: 100, 200, 400', $r['line']);
        self::assertSame(3, $r['id_count']);
    }

    public function test_commit_empty_seen_creates_line(): void
    {
        $r = $this->service()->commit('digest:1:t', [100, 200], '');
        self::assertSame('digest:1:t ids: 100, 200', $r['line']);
        self::assertSame(2, $r['id_count']);
    }

    public function test_commit_moves_existing_shown_to_end(): void
    {
        $r = $this->service()->commit('digest:1:t', [100], 'digest:1:t ids: 100, 200, 300');
        self::assertSame('digest:1:t ids: 200, 300, 100', $r['line']);
    }

    public function test_commit_trims_to_keep(): void
    {
        $r = $this->service()->commit('digest:1:t', [4], 'digest:1:t ids: 1, 2, 3', 3);
        self::assertSame('digest:1:t ids: 2, 3, 4', $r['line']);
        self::assertSame(3, $r['id_count']);
    }

    public function test_commit_keep_boundary_no_trim(): void
    {
        $r = $this->service()->commit('digest:1:t', [], 'digest:1:t ids: 1, 2, 3', 3);
        self::assertSame('digest:1:t ids: 1, 2, 3', $r['line']);
    }

    public function test_commit_empty_shown_and_seen(): void
    {
        $r = $this->service()->commit('digest:1:t', [], '');
        self::assertSame('digest:1:t ids: ', $r['line']);
        self::assertSame(0, $r['id_count']);
    }

    public function test_commit_ignores_non_numeric_shown(): void
    {
        $r = $this->service()->commit('digest:1:t', ['abc', null, 5], '');
        self::assertSame('digest:1:t ids: 5', $r['line']);
    }
}
