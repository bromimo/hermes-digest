<?php
declare(strict_types=1);

namespace App\Dedup;

use App\Support\UrlNormalizer;

/**
 * Чистая логика дедупа (без IO): фильтрация кандидатов и слияние скользящего окна показанных ID.
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
     * @param list<int> $seenIds ID, показанные ранее (из хранилища)
     * @return array{fresh: list<array<string, mixed>>, candidate_count: int, removed_count: int}
     *   `removed_count` — сколько кандидатов отброшено как уже показанные; внутри-запросные дубли
     *   схлопываются молча и в `removed_count` не входят. Поле `id` проставляется (перезаписывая
     *   любое существующее) на каждом возвращённом элементе.
     */
    public function filter(array $candidates, array $seenIds = []): array
    {
        $seenSet = array_fill_keys($seenIds, true);

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
     * Сливает показанные ранее ID с вошедшими в дайджест и обрезает скользящее окно.
     *
     * @param list<int> $seenIds показанные ранее ID (старейшие слева)
     * @param list<int|string|null> $shownIds ID, вошедшие в дайджест (нечисловые и null отбрасываются)
     * @param int $keep максимальный размер окна по количеству ID
     * @return list<int> обновлённое окно ID (старейшие слева, новейшие справа)
     */
    public function merge(array $seenIds, array $shownIds, int $keep = 50): array
    {
        $result = array_values($seenIds);

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

        return $result;
    }
}