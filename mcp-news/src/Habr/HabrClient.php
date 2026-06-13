<?php
declare(strict_types=1);

namespace App\Habr;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * HTTP-клиент для обращения к Хабру: RSS-ленты, поиск kek и HTML статей.
 */
final class HabrClient implements HabrClientInterface
{
    /**
     * @param Client $http    HTTP-клиент Guzzle.
     * @param string $baseUrl Базовый URL, по умолчанию https://habr.com.
     */
    public function __construct(
        private Client $http,
        private string $baseUrl = 'https://habr.com',
    ) {}

    /**
     * @param string $path Путь, начинающийся с "/".
     * @return string Тело ответа.
     * @throws HabrUnavailable При сетевой ошибке или HTTP >= 400.
     */
    public function getRss(string $path): string
    {
        return $this->get($this->baseUrl . $path);
    }

    /**
     * @param string $query Поисковый запрос.
     * @param int    $page  Номер страницы (с 1).
     * @return string JSON-ответ kek API.
     * @throws HabrUnavailable При сетевой ошибке или HTTP >= 400.
     */
    public function getSearch(string $query, int $page = 1): string
    {
        $qs = http_build_query([
            'query' => $query,
            'order' => 'date',
            'fl'    => 'ru',
            'hl'    => 'ru',
            'page'  => $page,
        ]);
        return $this->get($this->baseUrl . '/kek/v2/articles/?' . $qs);
    }

    /**
     * @param string $url Абсолютный URL статьи.
     * @return string HTML-тело страницы.
     * @throws HabrUnavailable При сетевой ошибке или HTTP >= 400.
     */
    public function getArticle(string $url): string
    {
        return $this->get($url);
    }

    /**
     * @param string $url Полный URL запроса.
     * @return string Тело ответа.
     * @throws HabrUnavailable При сетевой ошибке или HTTP >= 400.
     */
    private function get(string $url): string
    {
        try {
            $resp = $this->http->request('GET', $url, [
                RequestOptions::HEADERS => [
                    'User-Agent'      => 'hermes-digest/1.0 (+https://github.com/)',
                    'Accept-Language' => 'ru,en;q=0.8',
                ],
                RequestOptions::TIMEOUT         => 15,
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::ALLOW_REDIRECTS => true,
                RequestOptions::HTTP_ERRORS     => false,
            ]);
        } catch (GuzzleException $e) {
            throw new HabrUnavailable("Request failed for {$url}: {$e->getMessage()}", 0, $e);
        }

        $code = $resp->getStatusCode();
        if ($code >= 400) {
            throw new HabrUnavailable("HTTP {$code} for {$url}");
        }

        return (string) $resp->getBody();
    }
}