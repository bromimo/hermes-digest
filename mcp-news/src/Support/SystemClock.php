<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Системные часы, возвращающие реальное UTC-время.
 */
final class SystemClock implements Clock
{
    /**
     * Возвращает текущий момент времени в UTC.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}