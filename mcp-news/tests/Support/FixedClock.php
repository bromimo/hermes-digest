<?php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Clock;

final class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}