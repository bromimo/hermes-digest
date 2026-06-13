<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Разбирает строковое представление периода в диапазон дат.
 */
final class PeriodParser
{
    /**
     * Создаёт экземпляр с заданным источником времени.
     *
     * @param Clock $clock
     */
    public function __construct(private Clock $clock) {}

    /**
     * Разбирает строку периода и возвращает границы диапазона.
     *
     * @param string $period
     * @return array{since: \DateTimeImmutable, until: \DateTimeImmutable}
     */
    public function parse(string $period): array
    {
        $period = trim($period);
        $utc = new \DateTimeZone('UTC');

        if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $period, $m) === 1) {
            return [
                'since' => new \DateTimeImmutable($m[1] . ' 00:00:00', $utc),
                'until' => new \DateTimeImmutable($m[2] . ' 23:59:59', $utc),
            ];
        }

        $now = $this->clock->now();

        if (preg_match('/^(\d+)\s*([hdwm])$/i', $period, $m) === 1) {
            $n = (int) $m[1];
            $spec = match (strtolower($m[2])) {
                'h' => "PT{$n}H",
                'd' => "P{$n}D",
                'w' => 'P' . ($n * 7) . 'D',
                'm' => "P{$n}M",
            };
            return ['since' => $now->sub(new \DateInterval($spec)), 'until' => $now];
        }

        // default: last 7 days
        return ['since' => $now->sub(new \DateInterval('P7D')), 'until' => $now];
    }

    /**
     * Проверяет, входит ли момент времени в заданный диапазон.
     *
     * @param \DateTimeImmutable $dt
     * @param \DateTimeImmutable $since
     * @param \DateTimeImmutable $until
     * @return bool
     */
    public function isWithin(\DateTimeImmutable $dt, \DateTimeImmutable $since, \DateTimeImmutable $until): bool
    {
        return $dt >= $since && $dt <= $until;
    }
}