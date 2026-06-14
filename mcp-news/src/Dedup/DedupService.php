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
     * @return array{fresh: list<array<string, mixed>>, candidate_count: int, removed_count: int}
     *   `removed_count` — количество кандидатов, отброшенных потому что их id уже есть в seen-наборе;
     *   внутри-запросные дубли молча схлопываются и в `removed_count` НЕ входят.
     *   Поле `id` проставляется (и перезаписывает любое существующее значение `id`) на каждом возвращённом элементе.
     */
    public function filter(array $candidates, string $seenBlob = ''): array
    {
        $seenSet = array_fill_keys($this->parseIds($seenBlob), true);

        $fresh = [];
        $batchIds = [];
        $batchUrls = [];
        $removedBySeen = 0;

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
                    $removedBySeen++;
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
            'removed_count' => $removedBySeen,
        ];
    }

    /**
     * Объединяет показанные ранее ID с вошедшими в дайджест и обрезает окно, возвращая новую строку памяти.
     *
     * @param string $key ключ памяти `digest:<chat_id>:<тема-slug>`
     * @param list<int|string|null> $shownIds ID статей, реально вошедших в дайджест (нечисловые и null отбрасываются)
     * @param string $seenBlob старая строка памяти (или пустая)
     * @param int $keep максимальный размер окна по количеству ID
     * @return array{line: string, id_count: int} новая строка памяти и число ID в ней
     */
    public function commit(string $key, array $shownIds, string $seenBlob = '', int $keep = 50): array
    {
        $result = $this->parseIds($seenBlob);

        foreach ($shownIds as $raw) {
            if (!is_numeric($raw)) {
                continue;
            }
            $id = (int) $raw;
            $pos = array_search($id, $result, true);
            if ($pos !== false) {
                array_splice($result, $pos, 1);
            }
            $result[] = $id;
        }

        if ($keep > 0 && count($result) > $keep) {
            $result = array_slice($result, -$keep);
        }

        return [
            'line' => $key . ' ids: ' . implode(', ', $result),
            'id_count' => count($result),
        ];
    }

    /**
     * Извлекает числовые ID из строки памяти: числа после маркера `ids:` (регистронезависимо) или, при его отсутствии, все числа строки.
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
