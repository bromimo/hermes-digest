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

        self::assertSame('https://habr.com/ru/articles/1000001/', $items[1]['url']);
        self::assertNull($items[1]['image']);
        self::assertSame(['PHP'], $items[1]['tags']);
        self::assertSame('2026-06-01T09:00:00+00:00', $items[1]['published_at']);
    }

    public function test_handles_broken_xml(): void
    {
        self::assertSame([], (new RssParser(new UrlNormalizer()))->parse('<not xml'));
    }

    public function test_skips_item_without_guid_or_link(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Test</title>
    <item>
      <title>No URL item</title>
      <pubDate>Sat, 13 Jun 2026 13:51:49 GMT</pubDate>
    </item>
  </channel>
</rss>
XML;
        self::assertSame([], (new RssParser(new UrlNormalizer()))->parse($xml));
    }
}