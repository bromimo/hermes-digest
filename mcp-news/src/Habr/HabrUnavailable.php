<?php
declare(strict_types=1);

namespace App\Habr;

/**
 * Исключение, сигнализирующее о недоступности Хабра (HTTP-ошибка или сетевой сбой).
 */
final class HabrUnavailable extends \RuntimeException
{
}