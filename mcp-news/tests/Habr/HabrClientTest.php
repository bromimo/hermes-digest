<?php
declare(strict_types=1);

namespace App\Tests\Habr;

use App\Habr\HabrClient;
use App\Habr\HabrUnavailable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class HabrClientTest extends TestCase
{
    private function client(MockHandler $mock): HabrClient
    {
        $guzzle = new Client(['handler' => HandlerStack::create($mock)]);
        return new HabrClient($guzzle, 'https://habr.com');
    }

    public function test_get_rss_returns_body(): void
    {
        $client = $this->client(new MockHandler([new Response(200, [], '<rss/>')]));
        self::assertSame('<rss/>', $client->getRss('/ru/rss/all/all/'));
    }

    public function test_search_builds_url_and_returns_json(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"ok":1}')]);
        $client = $this->client($mock);
        self::assertSame('{"ok":1}', $client->getSearch('AI/ML', 2));
        $req = $mock->getLastRequest();
        self::assertSame('/kek/v2/articles/', $req->getUri()->getPath());
        self::assertStringContainsString('query=AI%2FML', $req->getUri()->getQuery());
        self::assertStringContainsString('order=date', $req->getUri()->getQuery());
        self::assertStringContainsString('page=2', $req->getUri()->getQuery());
    }

    public function test_throws_on_http_error(): void
    {
        $client = $this->client(new MockHandler([new Response(404, [], 'nope')]));
        $this->expectException(HabrUnavailable::class);
        $client->getArticle('https://habr.com/ru/articles/1/');
    }
}