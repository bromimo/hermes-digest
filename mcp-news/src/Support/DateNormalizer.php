<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Приводит строковую дату в любом распознаваемом формате к ISO 8601 (DATE_ATOM) в UTC.
 */
final class DateNormalizer
{
    /**
     * Преобразует дату в ATOM-строку в UTC; возвращает null для пустого или нераспознаваемого значения.
     *
     * @param string|null $value исходная дата (RFC 822, ISO 8601 и т.п.)
     * @return string|null дата в формате DATE_ATOM (UTC) или null
     */
    public static function toAtomUtc(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}