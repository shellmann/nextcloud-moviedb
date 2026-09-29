<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\ExportService;
use OCA\MovieDB\Tests\Unit\TestCase;

class ExportServiceFilenameTest extends TestCase {
    private \DateTimeImmutable $at;

    protected function setUp(): void {
        parent::setUp();
        $this->at = new \DateTimeImmutable('2026-09-29 19:36:12', new \DateTimeZone('Europe/Berlin'));
    }

    public function testIncludesLibraryNameAndTimestampWithoutSeconds(): void {
        $this->assertSame('moviedb-Personal-2026-09-29_19-36.json', ExportService::filename('Personal', $this->at));
    }

    /**
     * @dataProvider namesProvider
     */
    public function testNameIsReducedToSafeAscii(string $name, string $expected): void {
        $this->assertSame("moviedb-$expected-2026-09-29_19-36.json", ExportService::filename($name, $this->at));
    }

    public static function namesProvider(): array {
        return [
            'spaces' => ['Family Movies', 'Family-Movies'],
            'german umlauts' => ['Familie Müller', 'Familie-Mueller'],
            'sharp s' => ['Straße', 'Strasse'],
            'path separators' => ['../etc/passwd', 'etc-passwd'],
            'quotes and header chars' => ["a\"b;c\r\nd", 'a-b-c-d'],
            'punctuation collapses' => ['  Movies!!!  ', 'Movies'],
        ];
    }

    public function testFallsBackToTimestampOnlyWhenNothingUsableRemains(): void {
        $this->assertSame('moviedb-2026-09-29_19-36.json', ExportService::filename('???', $this->at));
        $this->assertSame('moviedb-2026-09-29_19-36.json', ExportService::filename('', $this->at));
    }

    public function testLongNamesAreCapped(): void {
        $file = ExportService::filename(str_repeat('a', 200), $this->at);
        $this->assertSame('moviedb-' . str_repeat('a', 60) . '-2026-09-29_19-36.json', $file);
    }

    public function testNoColonsOrSlashesEver(): void {
        $file = ExportService::filename('a:b/c\\d', $this->at);
        $this->assertDoesNotMatchRegularExpression('/[:\/\\\\"]/', $file);
    }
}
