<?php
declare(strict_types=1);

namespace App\Tests\Habr;

use App\Habr\SearchApiParser;
use PHPUnit\Framework\TestCase;

final class SearchApiParserTest extends TestCase
{
    public function test_parses_in_id_order(): void
    {
        $json = file_get_contents(__DIR__ . '/../Fixtures/kek_search.json');
        $items = (new SearchApiParser())->parse($json);

        self::assertCount(2, $items);

        self::assertSame('Как мы внедряли ML в продакшн', $items[0]['title']);
        self::assertSame('https://habr.com/ru/articles/1047116/', $items[0]['url']);
        self::assertSame('2026-06-13T13:55:30+00:00', $items[0]['published_at']);
        self::assertSame('habr', $items[0]['source']);
        self::assertStringContainsString('грабл', $items[0]['snippet']);
        self::assertSame('https://habrastorage.org/getpro/habr/lead116.jpg', $items[0]['image']);
        self::assertSame(['ML', 'MLOps'], $items[0]['tags']);

        self::assertNull($items[1]['image']);
    }

    public function test_handles_garbage(): void
    {
        self::assertSame([], (new SearchApiParser())->parse('null'));
        self::assertSame([], (new SearchApiParser())->parse('{"x":1}'));
    }
}