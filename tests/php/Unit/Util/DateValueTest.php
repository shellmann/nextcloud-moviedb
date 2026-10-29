<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Tests\Unit\TestCase;
use OCA\MovieDB\Util\DateValue;

class DateValueTest extends TestCase {
    public function testKeepsAValidDate(): void {
        $this->assertSame('2026-10-29', DateValue::normalize('2026-10-29'));
    }

    /**
     * @dataProvider invalidProvider
     */
    public function testReturnsNullForAnythingElse(mixed $value): void {
        $this->assertNull(DateValue::normalize($value));
    }

    public static function invalidProvider(): array {
        return [
            'empty string (TMDB, not released yet)' => [''],
            'null' => [null],
            'number' => [20261029],
            'garbage' => ['abc'],
            'impossible month' => ['2026-13-01'],
            'impossible day' => ['2026-02-30'],
            'with time' => ['2026-10-29 10:00:00'],
            'year only' => ['2026'],
        ];
    }
}
