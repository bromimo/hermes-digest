<?php
declare(strict_types=1);

namespace App\Tests\Habr;

use App\Habr\ArticleParser;
use PHPUnit\Framework\TestCase;

final class ArticleParserTest extends TestCase
{
    public function test_parses_article(): void
    {
        $html = file_get_contents(__DIR__ . '/../Fixtures/article.html');
        $a = (new ArticleParser())->parse($html, 'https://habr.com/ru/articles/1047108/');

        self::assertSame('Нужно ли использовать Qwen? Качество и цена', $a['title']);
        self::assertStringContainsString('Китайские модели', $a['text']);
        self::assertStringContainsString('Второй абзац', $a['text']);
        self::assertSame('opium', $a['author']);
        self::assertSame('2026-06-13T13:51:49+00:00', $a['published_at']);
        self::assertSame('https://habr.com/share/publication/1047108/abc123/', $a['image']);
    }
}