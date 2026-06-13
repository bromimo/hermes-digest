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
     * @return array{items: list<array>, count: int, error?: string}
     */
    public function getNews(string $topic, string $period, int $limit): array
    {
        $limit = max(1, min(30, $limit));
        try {
            $path       = $this->feedPath($topic);
            $useKeyword = !$this->isHubFeed($topic);
            $items      = $this->rss->parse($this->client->getRss($path));
        } catch (HabrUnavailable $e) {
            return ['items' => [], 'count' => 0, 'error' => 'Источник недоступен: ' . $e->getMessage()];
        }

        if ($useKeyword) {
            $items = $this->keywordFilter($items, $topic);
        }
        $items = $this->periodFilter($items, $period);
        $items = $this->sortNewestFirst($items);
        $items = array_slice($items, 0, $limit);

        return ['items' => array_values($items), 'count' => count($items)];
    }

    /**
     * Выполняет поиск по Хабру и фильтрует результаты по периоду.
     *
     * @param string $query  Поисковый запрос.
     * @param string $period Период фильтрации.
     * @param int    $limit  Максимальное количество элементов (1–30).
     * @return array{items: list<array>, count: int, error?: string}
     */
    public function searchHabr(string $query, string $period, int $limit): array
    {
        $limit     = max(1, min(30, $limit));
        $collected = [];
        try {
            // загружаем до 3 страниц (сначала новые), прерываемся досрочно при достижении лимита
            for ($page = 1; $page <= 3; $page++) {
                $items = $this->search->parse($this->client->getSearch($query, $page));
                if ($items === []) {
                    break;
                }
                foreach ($items as $it) {
                    $collected[] = $it;
                }
                if (count($this->periodFilter($collected, $period)) >= $limit) {
                    break;
                }
            }
        } catch (HabrUnavailable $e) {
            return ['items' => [], 'count' => 0, 'error' => 'Поиск недоступен: ' . $e->getMessage()];
        }

        $items = $this->periodFilter($collected, $period);
        $items = $this->sortNewestFirst($items);
        $items = array_slice($items, 0, $limit);

        return ['items' => array_values($items), 'count' => count($items)];
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
                'title'        => null,
                'text'         => '',
                'author'       => null,
                'published_at' => null,
                'image'        => null,
                'error'        => 'Статья недоступна: ' . $e->getMessage(),
            ];
        }
        $parsed        = $this->article->parse($html, $url);
        $parsed['url'] = $url;
        return $parsed;
    }

    /**
     * @param string $topic Тема или псевдоним.
     * @return string RSS-путь для клиента.
     */
    private function feedPath(string $topic): string
    {
        $key = mb_strtolower(trim($topic));
        if (isset(self::HUBS[$key])) {
            return '/ru/rss/hub/' . self::HUBS[$key] . '/all/?fl=ru';
        }
        if (preg_match('/^[a-z0-9_]+$/', $key) === 1) {
            return '/ru/rss/hub/' . $key . '/all/?fl=ru';
        }
        return '/ru/rss/all/all/';
    }

    /**
     * @param string $topic Тема.
     * @return bool True, если тема соответствует известному хабу.
     */
    private function isHubFeed(string $topic): bool
    {
        $key = mb_strtolower(trim($topic));
        return isset(self::HUBS[$key]) || preg_match('/^[a-z0-9_]+$/', $key) === 1;
    }

    /**
     * @param array  $items Список статей.
     * @param string $topic Тема для фильтрации по ключевым словам.
     * @return array Отфильтрованный список.
     */
    private function keywordFilter(array $items, string $topic): array
    {
        $terms = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: []));
        if ($terms === []) {
            return $items;
        }
        return array_filter($items, function (array $it) use ($terms): bool {
            $hay = mb_strtolower($it['title'] . ' ' . $it['snippet'] . ' ' . implode(' ', $it['tags']));
            foreach ($terms as $t) {
                if (mb_strlen($t) >= 2 && str_contains($hay, $t)) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * @param array  $items  Список статей.
     * @param string $period Период фильтрации.
     * @return array Статьи, попадающие в период.
     */
    private function periodFilter(array $items, string $period): array
    {
        ['since' => $since, 'until' => $until] = $this->period->parse($period);
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