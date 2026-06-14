<?php
declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\DigestTools;
use PHPUnit\Framework\TestCase;

final class DigestToolsDedupTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'dedup_tools_');
        putenv('DEDUP_DB_PATH=' . $this->dbPath);
    }

    protected function tearDown(): void
    {
        putenv('DEDUP_DB_PATH');
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function test_dedup_filter_and_commit_roundtrip_via_store(): void
    {
        $tools = new DigestTools();
        $key = 'digest:1:ai-ml';

        // commit two shown ids → persisted server-side
        $committed = $tools->dedupCommit($key, [100, 200]);
        self::assertSame(2, $committed['id_count']);

        // filter against the SAME key → previously shown excluded, new kept
        $result = $tools->dedupFilter($key, [
            ['url' => 'https://habr.com/ru/articles/100/'],
            ['url' => 'https://habr.com/ru/articles/200/'],
            ['url' => 'https://habr.com/ru/articles/300/'],
        ]);
        self::assertSame([300], array_column($result['fresh'], 'id'));
        self::assertSame(2, $result['removed_count']);
    }

    public function test_dedup_filter_empty_store_keeps_all(): void
    {
        $tools = new DigestTools();
        $result = $tools->dedupFilter('digest:1:fresh', [
            ['url' => 'https://habr.com/ru/articles/100/'],
            ['url' => 'https://habr.com/ru/articles/200/'],
        ]);
        self::assertSame([100, 200], array_column($result['fresh'], 'id'));
        self::assertSame(0, $result['removed_count']);
    }
}