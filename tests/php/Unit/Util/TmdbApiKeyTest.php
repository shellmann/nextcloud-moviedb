<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Tests\Unit\TestCase;
use OCA\MovieDB\Util\TmdbApiKey;

class TmdbApiKeyTest extends TestCase {
    public function testReadAccessTokenIsValid(): void {
        // Shape of a TMDB Read Access Token (a JWT): base64url parts joined by dots
        $this->assertTrue(TmdbApiKey::isValid('eyJhbGciOiJIUzI1NiJ9.eyJhdWQiOiJ4In0.abc_DEF-123'));
    }

    public function testKeyAtTheLengthLimitIsValid(): void {
        $this->assertTrue(TmdbApiKey::isValid(str_repeat('a', 500)));
    }

    public function testTooLongKeyIsInvalid(): void {
        $this->assertFalse(TmdbApiKey::isValid(str_repeat('a', 501)));
    }

    /**
     * @dataProvider invalidKeyProvider
     */
    public function testInvalidKeys(string $key): void {
        $this->assertFalse(TmdbApiKey::isValid($key));
    }

    public static function invalidKeyProvider(): array {
        return [
            'empty' => [''],
            'space' => ['abc def'],
            'trailing newline' => ["abc\n"],
            'markup' => ['<script>'],
            'url' => ['https://example.com'],
        ];
    }
}
