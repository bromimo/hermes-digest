<?php
declare(strict_types=1);

namespace App\Habr;

use App\Support\PeriodParser;
use App\Support\SystemClock;
use App\Support\UrlNormalizer;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class HabrServiceFactory
{
    /**Фабрика HabrService: собирает зависимости из переменных окружения с Guzzle retry-middleware.
     *
     * @return HabrService
     */
    public static function fromEnv(): HabrService
    {
        $base = getenv('HABR_BASE_URL') ?: 'https://habr.com';

        $stack = HandlerStack::create();
        // retry on 429/503/network up to 2 times with linear backoff
        $stack->push(Middleware::retry(
            function (int $retries, Request $request, ?ResponseInterface $response, ?\Throwable $e): bool {
                if ($retries >= 2) {
                    return false;
                }
                if ($e !== null) {
                    return true;
                }
                return $response !== null && in_array($response->getStatusCode(), [429, 503], true);
            },
            fn (int $retries): int => 1000 * $retries // ms
        ));

        $guzzle = new Client(['handler' => $stack]);
        $urls = new UrlNormalizer();

        return new HabrService(
            new HabrClient($guzzle, $base),
            new RssParser($urls),
            new SearchApiParser(),
            new ArticleParser(),
            new PeriodParser(new SystemClock()),
            $urls,
        );
    }
}