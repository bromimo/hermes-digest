<?php
declare(strict_types=1);

namespace App\Tests\Habr;

use App\Habr\RssParser;
use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class RssParserTest extends TestCase
{
    public function test_parses_items(): void
    {
        $xml = file_get_contents(__DIR__ . '/../Fixtures/rss_programming.xml');
        $items = (new RssParser(new UrlNormalizer()))->parse($xml);

        self::assertCount(2, $items);

        $first = $items[0];
        self::assertSame('Нужно ли использовать Qwen? Качество и цена', $first['title']);
        self::assertSame('https://habr.com/ru/articles/1047108/', $first['url']);
        self::assertSame('2026-06-13T13:51:49+00:00', $first['published_at']);
        self::assertSame('habr', $first['source']);
        self::assertStringContainsString('Китайские модели', $first['snippet']);
        self::assertSame('https://habrastorage.org/getpro/habr/upload_files/e93/lead.jpg', $first['image']);
        self::assertSame(['Qwen', 'LLM', 'машинное обучение'], $first['tags']);
    }

    public function test_handles_broken_xml(): void
    {
        self::assertSame([], (new RssParser(new UrlNormalizer()))->parse('<not xml'));
    }
}