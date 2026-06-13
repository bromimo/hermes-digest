<?php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\PeriodParser;
use PHPUnit\Framework\TestCase;

final class PeriodParserTest extends TestCase
{
    private function parser(): PeriodParser
    {
        $now = new \DateTimeImmutable('2026-06-13T12:00:00+00:00');
        return new PeriodParser(new FixedClock($now));
    }

    public function test_days(): void
    {
        $r = $this->parser()->parse('7d');
        self::assertSame('2026-06-06T12:00:00+00:00', $r['since']->format(DATE_ATOM));
        self::assertSame('2026-06-13T12:00:00+00:00', $r['until']->format(DATE_ATOM));
    }

    public function test_hours(): void
    {
        $r = $this->parser()->parse('24h');
        self::assertSame('2026-06-12T12:00:00+00:00', $r['since']->format(DATE_ATOM));
    }

    public function test_weeks(): void
    {
        $r = $this->parser()->parse('2w');
        self::assertSame('2026-05-30T12:00:00+00:00', $r['since']->format(DATE_ATOM));
    }

    public function test_date_range(): void
    {
        $r = $this->parser()->parse('2026-06-01..2026-06-10');
        self::assertSame('2026-06-01T00:00:00+00:00', $r['since']->format(DATE_ATOM));
        self::assertSame('2026-06-10T23:59:59+00:00', $r['until']->format(DATE_ATOM));
    }

    public function test_months(): void
    {
        $r = $this->parser()->parse('1m');
        self::assertSame('2026-05-13T12:00:00+00:00', $r['since']->format(DATE_ATOM));
    }

    public function test_default_on_garbage(): void
    {
        $r = $this->parser()->parse('whenever');
        self::assertSame('2026-06-06T12:00:00+00:00', $r['since']->format(DATE_ATOM));
    }
}