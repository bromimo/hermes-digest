<?php
declare(strict_types=1);

namespace App\Tests\Dedup;

use App\Dedup\DedupStore;
use PHPUnit\Framework\TestCase;

final class DedupStoreTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'dedup_store_');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function test_seenIds_empty_for_unknown_key(): void
    {
        $store = new DedupStore($this->dbPath);
        self::assertSame([], $store->seenIds('digest:1:t'));
    }

    public function test_save_and_read_roundtrip(): void
    {
        $store = new DedupStore($this->dbPath);
        $store->save('digest:1:t', [100, 200, 300]);
        self::assertSame([100, 200, 300], $store->seenIds('digest:1:t'));
    }

    public function test_save_upserts(): void
    {
        $store = new DedupStore($this->dbPath);
        $store->save('digest:1:t', [100]);
        $store->save('digest:1:t', [100, 200]);
        self::assertSame([100, 200], $store->seenIds('digest:1:t'));
    }

    public function test_keys_are_isolated(): void
    {
        $store = new DedupStore($this->dbPath);
        $store->save('digest:1:a', [1, 2]);
        $store->save('digest:1:b', [3, 4]);
        self::assertSame([1, 2], $store->seenIds('digest:1:a'));
        self::assertSame([3, 4], $store->seenIds('digest:1:b'));
    }

    public function test_persists_across_connections(): void
    {
        (new DedupStore($this->dbPath))->save('digest:1:t', [7, 8]);
        self::assertSame([7, 8], (new DedupStore($this->dbPath))->seenIds('digest:1:t'));
    }

    public function test_transaction_returns_value_and_commits(): void
    {
        $store = new DedupStore($this->dbPath);
        $out = $store->transaction(function () use ($store): string {
            $store->save('digest:1:t', [9]);
            return 'ok';
        });
        self::assertSame('ok', $out);
        self::assertSame([9], $store->seenIds('digest:1:t'));
    }
}