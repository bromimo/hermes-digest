<?php
declare(strict_types=1);

namespace App\Dedup;

use App\Support\UrlNormalizer;

/**
 * Детерминированный дедуп материалов дайджеста: фильтрация показанных и обновление окна показанных ID.
 */
final class DedupService
{
    /**
     * Создаёт сервис с нормализатором URL.
     *
     * @param UrlNormalizer $urls нормализатор URL статей Хабра
     */
    public function __construct(private UrlNormalizer $urls) {}

    /**
     * Фильтрует кандидатов, убирая показанные ранее статьи и внутри-запросные дубли.
     *
     * @param list<array<string, mixed>> $candidates объединённый список кандидатов из поиска/ленты
     * @param string $seenBlob сырая строка памяти с показанными ранее ID (или пустая)
     * @return array{fresh: list<array<string, mixed>>, candidate_count: int, removed_count: int} свежие кандидаты с проставленным id и счётчики
     */
    public function filter(array $candidates, string $seenBlob = ''): array
    {
        $seenSet = array_fill_keys($this->parseIds($seenBlob), true);

        $fresh = [];
        $batchIds = [];
        $batchUrls = [];
        $removed = 0;

        foreach ($candidates as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $idStr = $url !== '' ? $this->urls->id($url) : null;
            $id = $idStr !== null ? (int) $idStr : null;

            if ($id !== null) {
                if (isset($batchIds[$id])) {
                    continue;
                }
                if (isset($seenSet[$id])) {
                    $removed++;
                    continue;
                }
                $batchIds[$id] = true;
            } else {
                $canon = $url !== '' ? $this->urls->canonical($url) : '';
                if ($canon !== '') {
                    if (isset($batchUrls[$canon])) {
                        continue;
                    }
                    $batchUrls[$canon] = true;
                }
            }

            $item['id'] = $id;
            $fresh[] = $item;
        }

        return [
            'fresh' => $fresh,
            'candidate_count' => count($candidates),
            'removed_count' => $removed,
        ];
    }

    /**
     * Извлекает числовые ID из строки памяти: числа после маркера `ids:` или, при его отсутствии, все числа строки.
     *
     * @param string $blob строка памяти
     * @return list<int> ID в порядке появления, без дублей
     */
    private function parseIds(string $blob): array
    {
        if (trim($blob) === '') {
            return [];
        }
        $pos = stripos($blob, 'ids:');
        $haystack = $pos !== false ? substr($blob, $pos + 4) : $blob;
        preg_match_all('/\d+/', $haystack, $m);

        $ids = [];
        $seen = [];
        foreach ($m[0] as $num) {
            $id = (int) $num;
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $ids[] = $id;
            }
        }
        return $ids;
    }
}