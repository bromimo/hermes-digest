<?php
declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\DateNormalizer;
use PHPUnit\Framework\TestCase;

final class DateNormalizerTest extends TestCase
{
    public function test_converts_rfc822_to_atom_utc(): void
    {
        self::assertSame(
            '2026-06-13T13:51:49+00:00',
            DateNormalizer::toAtomUtc('Sat, 13 Jun 2026 13:51:49 GMT')
        );
    }

    public function test_converts_iso_with_offset_to_utc(): void
    {
        self::assertSame(
            '2026-06-13T13:51:49+00:00',
            DateNormalizer::toAtomUtc('2026-06-13T16:51:49+03:00')
        );
    }

    public function test_returns_null_for_empty_string(): void
    {
        self::assertNull(DateNormalizer::toAtomUtc(''));
    }

    public function test_returns_null_for_garbage(): void
    {
        self::assertNull(DateNormalizer::toAtomUtc('garbage'));
    }
}