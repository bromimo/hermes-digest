<?php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    public function test_extracts_id_from_plain_url(): void
    {
        $n = new UrlNormalizer();
        self::assertSame('1047108', $n->id('https://habr.com/ru/articles/1047108/'));
    }

    public function test_extracts_id_with_utm(): void
    {
        $n = new UrlNormalizer();
        self::assertSame('1047108', $n->id('https://habr.com/ru/articles/1047108/?utm_source=rss'));
    }

    public function test_extracts_id_from_company_post(): void
    {
        $n = new UrlNormalizer();
        self::assertSame('1044658', $n->id('https://habr.com/ru/companies/otus/articles/1044658/'));
    }

    public function test_canonical(): void
    {
        $n = new UrlNormalizer();
        self::assertSame(
            'https://habr.com/ru/articles/1047108/',
            $n->canonical('https://habr.com/ru/companies/otus/articles/1047108/?utm_source=rss')
        );
    }

    public function test_canonical_strips_query_when_no_article_id(): void
    {
        $n = new UrlNormalizer();
        self::assertSame(
            'https://habr.com/ru/search/',
            $n->canonical('https://habr.com/ru/search/?query=php')
        );
    }

    public function test_id_null_when_absent(): void
    {
        $n = new UrlNormalizer();
        self::assertNull($n->id('https://habr.com/ru/users/opium/'));
    }
}