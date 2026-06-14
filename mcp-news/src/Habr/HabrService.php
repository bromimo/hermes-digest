<?php
declare(strict_types=1);

namespace App\Habr;

use App\Support\UrlNormalizer;
use App\Support\PeriodParser;

/**
 * Оркестрирует получение новостей, поиск и загрузку статей с Хабра.
 */
final class HabrService
{
    /** Соответствие псевдонима темы → slug хаба Хабра для RSS-пути. */
    private const HUBS = [
        'ai'                      => 'artificial_intelligence',
        'ml'                      => 'machine_learning',
        'php'                     => 'php',
        'python'                  => 'python',
        'devops'                  => 'devops',
        'ai/ml'                   => 'machine_learning',
        'машинное обучение'       => 'machine_learning',
        'программирование'        => 'programming',
        'machine learning'        => 'machine_learning',
        'информационная безопасность' => 'infosecurity',
    ];

    /**
     * @param HabrClientInterface $client  HTTP-клиент Хабра.
     * @param RssParser           $rss     Парсер RSS.
     * @param SearchApiParser     $search  Парсер kek-поиска.
     * @param ArticleParser       $article Парсер HTML статьи.
     * @param PeriodParser        $period  Разбор временного периода.
     * @param UrlNormalizer       $urls    Нормализатор URL.
     */
    public function __construct(
        private HabrClientInterface $client,
        private RssParser $rss,
        private SearchApiParser $search,
        private ArticleParser $article,
        private PeriodParser $period,
        private UrlNormalizer $urls,
    ) {}

    /**
     * Возвращает новости по теме за период с ограничением количества.
     *
     * @param string $topic  Тема или псевдоним хаба.
     * @param string $period Период в формате "7d", "24h", "2w" или "2026-06-01..2026-06-10".
     * @param int    $limit  Максимальное количество элементов (1–30).
     * @return array{items: list<array>, count: int, since: string, until: string, error?: string}
     */
    public function getNews(string $topic, string $period, int $limit): array
    {
        $limit = max(1, min(30, $limit));
        ['since' => $since, 'until' => $until] = $this->period->parse($period);
        try {
            $slug       = $this->resolveHubSlug($topic);
            $path       = $slug !== null ? "/ru/rss/hub/{$slug}/all/?fl=ru" : '/ru/rss/all/all/';
            $useKeyword = $slug === null;
            $items      = $this->rss->parse($this->client->getRss($path));
        } catch (HabrUnavailable $e) {
            return ['items' => [], 'count' => 0, 'since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d'), 'error' => 'Источник недоступен: ' . $e->getMessage()];
        }

        if ($useKeyword) {
            $items = $this->keywordFilter($items, $topic);
        }
        $items = $this->periodFilter($items, $since, $until);
        $items = $this->sortNewestFirst($items);
        $items = array_slice($items, 0, $limit);

        return ['items' => array_values($items), 'count' => count($items), 'since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d')];
    }

    /**
     * Выполняет поиск по Хабру и фильтрует результаты по периоду.
     *
     * @param string $query  Поисковый запрос.
     * @param string $period Период фильтрации.
     * @param int    $limit  Максимальное количество элементов (1–30).
     * @return array{items: list<array>, count: int, since: string, until: string, error?: string}
     */
    public function searchHabr(string $query, string $period, int $limit): array
    {
        $limit = max(1, min(30, $limit));
        ['since' => $since, 'until' => $until] = $this->period->parse($period);
        $collected = [];
        $error = null;
        for ($page = 1; $page <= 3; $page++) {
            try {
                $items = $this->search->parse($this->client->getSearch($query, $page));
            } catch (HabrUnavailable $e) {
                $error = 'Поиск недоступен: ' . $e->getMessage();
                break;
            }
            if ($items === []) {
                break;
            }
            foreach ($items as $it) {
                $collected[] = $it;
            }
            if (count($this->periodFilter($collected, $since, $until)) >= $limit) {
                break;
            }
        }
        if ($collected === [] && $error !== null) {
            return ['items' => [], 'count' => 0, 'since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d'), 'error' => $error];
        }
        $items = $this->periodFilter($collected, $since, $until);
        $items = $this->sortNewestFirst($items);
        $items = array_slice($items, 0, $limit);
        return ['items' => array_values($items), 'count' => count($items), 'since' => $since->format('Y-m-d'), 'until' => $until->format('Y-m-d')];
    }

    /**
     * Загружает и парсит статью по URL.
     *
     * @param string $url URL статьи (абсолютный).
     * @return array{title: ?string, text: string, author: ?string, published_at: ?string, image: ?string, url: string, error?: string}
     */
    public function fetchArticle(string $url): array
    {
        $url = $this->urls->canonical($url);
        try {
            $html = $this->client->getArticle($url);
        } catch (HabrUnavailable $e) {
            return [
                'title' => null, 'text' => '', 'author' => null, 'published_at' => null,
                'image' => null, 'url' => $url,
                'error' => 'Статья недоступна: ' . $e->getMessage(),
            ];
        }
        $parsed        = $this->article->parse($html, $url);
        $parsed['url'] = $url;
        return $parsed;
    }

    /**
     * Определяет slug хаба Habr по теме: из карты синонимов или из самого slug-подобного значения.
     *
     * @param string $topic тема запроса
     * @return string|null slug хаба или null, если тему нужно искать по ключевым словам в общей ленте
     */
    private function resolveHubSlug(string $topic): ?string
    {
        $key = mb_strtolower(trim($topic));
        if (isset(self::HUBS[$key])) {
            return self::HUBS[$key];
        }
        if (preg_match('/^[a-z0-9_]+$/', $key) === 1) {
            return $key;
        }
        return null;
    }

    /**
     * @param array  $items Список статей.
     * @param string $topic Тема для фильтрации по ключевым словам.
     * @return array Отфильтрованный список.
     */
    private function keywordFilter(array $items, string $topic): array
    {
        $terms = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2
        ));
        if ($terms === []) {
            return $items;
        }
        return array_filter($items, function (array $it) use ($terms): bool {
            $hay = mb_strtolower($it['title'] . ' ' . $it['snippet'] . ' ' . implode(' ', $it['tags']));
            foreach ($terms as $t) {
                if (str_contains($hay, $t)) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * @param array               $items Список статей.
     * @param \DateTimeImmutable  $since Начало периода.
     * @param \DateTimeImmutable  $until Конец периода.
     * @return array Статьи, попадающие в период.
     */
    private function periodFilter(array $items, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return array_filter($items, function (array $it) use ($since, $until): bool {
            if (empty($it['published_at'])) {
                return false;
            }
            try {
                $dt = new \DateTimeImmutable($it['published_at']);
            } catch (\Exception) {
                return false;
            }
            return $this->period->isWithin($dt, $since, $until);
        });
    }

    /**
     * @param array $items Список статей.
     * @return array Список, отсортированный по убыванию даты публикации.
     */
    private function sortNewestFirst(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $b['published_at'], (string) $a['published_at']);
        });
        return $items;
    }
}