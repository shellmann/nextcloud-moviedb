<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Tests\Unit\TestCase;
use OCA\MovieDB\Util\WatchRegion;
use PHPUnit\Framework\Attributes\DataProvider;

class WatchRegionTest extends TestCase {
    public static function cases(): array {
        return [
            'country in the TMDB language' => ['de-DE', 'en_US', 'DE'],
            'language wins over the locale' => ['en-GB', 'de_DE', 'GB'],
            'language without a country uses the locale' => ['de', 'de_AT', 'AT'],
            'language and locale without a country' => ['de', 'de', 'US'],
            'no locale' => ['fr', null, 'US'],
            'three-letter language' => ['fil-PH', null, 'PH'],
            'garbage' => ['<script>', '??', 'US'],
        ];
    }

    #[DataProvider('cases')]
    public function testResolve(string $language, ?string $locale, string $expected): void {
        $this->assertSame($expected, WatchRegion::resolve($language, $locale));
    }
}
