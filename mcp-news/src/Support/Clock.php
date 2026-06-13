<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Интерфейс источника текущего времени.
 */
interface Clock
{
    /**
     * Возвращает текущий момент времени.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable;
}