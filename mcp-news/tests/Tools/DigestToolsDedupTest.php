<?php
declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\DigestTools;
use PHPUnit\Framework\TestCase;

final class DigestToolsDedupTest extends TestCase
{
    public function test_dedup_filter_delegates_to_service(): void
    {
        $tools = new DigestTools();
        $result = $tools->dedupFilter(
            [
                ['url' => 'https://habr.com/ru/articles/100/'],
                ['url' => 'https://habr.com/ru/articles/200/'],
            ],
            'digest:1:t ids: 200'
        );
        self::assertSame(1, $result['removed_count']);
        self::assertCount(1, $result['fresh']);
        self::assertSame(100, $result['fresh'][0]['id']);
    }

    public function test_dedup_commit_delegates_to_service(): void
    {
        $tools = new DigestTools();
        $result = $tools->dedupCommit('digest:1:t', [300], 'digest:1:t ids: 100, 200');
        self::assertSame('digest:1:t ids: 100, 200, 300', $result['line']);
        self::assertSame(3, $result['id_count']);
    }
}