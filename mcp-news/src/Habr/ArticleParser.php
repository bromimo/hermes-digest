<?php
declare(strict_types=1);

namespace App\Habr;

use App\Support\DateNormalizer;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер HTML-страницы статьи Хабра с извлечением текста, автора, даты и обложки.
 */
final class ArticleParser
{
    /**
     * @param string $html Тело HTML-страницы статьи
     * @param string $url  Канонический URL статьи
     * @return array{title:?string,text:string,author:?string,published_at:?string,image:?string}
     */
    public function parse(string $html, string $url): array
    {
        $crawler = new Crawler($html, $url);

        $title = $this->firstText($crawler, 'h1.tm-title');

        $body = $crawler->filter('div.article-formatted-body');
        $text = $body->count() > 0 ? $this->normalizeWhitespace($body->first()->text('')) : '';

        $author = $this->firstText($crawler, 'a.tm-user-info__username');

        $time = $crawler->filter('time[datetime]');
        $published = $time->count() > 0
            ? DateNormalizer::toAtomUtc((string) $time->first()->attr('datetime'))
            : null;

        $image = $this->metaContent($crawler, 'og:image');
        if ($image === null && $body->count() > 0) {
            $img = $body->first()->filter('img');
            if ($img->count() > 0) {
                $image = $img->first()->attr('src');
            }
        }

        return [
            'title' => $title,
            'text' => $text,
            'author' => $author,
            'published_at' => $published,
            'image' => $image,
        ];
    }

    /**
     * @param string $selector CSS-селектор
     * @return ?string Текстовое содержимое первого узла или null
     */
    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);
        return $node->count() > 0 ? trim($node->first()->text('')) : null;
    }

    /**
     * @param string $property Значение атрибута property у тега meta
     * @return ?string Значение атрибута content или null
     */
    private function metaContent(Crawler $crawler, string $property): ?string
    {
        $node = $crawler->filter('meta[property="' . $property . '"]');
        if ($node->count() === 0) {
            return null;
        }
        $content = $node->first()->attr('content');
        return ($content !== null && $content !== '') ? $content : null;
    }

    /**
     * @param string $text Исходная строка
     * @return string Строка с нормализованными пробелами
     */
    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}