<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Util;

use OCA\MovieDB\Tests\Unit\TestCase;
use OCA\MovieDB\Util\JsonComplexity;

class JsonComplexityTest extends TestCase {
    public function testOrdinaryExportIsFine(): void {
        $file = json_encode(['app' => 'moviedb', 'movies' => array_fill(0, 1000, [
            'title' => 'A', 'genreIds' => [1, 2, 3],
            'castData' => array_fill(0, 20, ['name' => 'X', 'character' => 'Y', 'profilePath' => '/a.jpg']),
            'watches' => [['rating' => 8]],
        ])]);

        $this->assertFalse(JsonComplexity::isTooComplex($file));
    }

    public function testManyTinyObjectsAreRejected(): void {
        $this->assertTrue(JsonComplexity::isTooComplex('[' . str_repeat('{},', JsonComplexity::MAX_OBJECTS) . '{}]'));
    }

    public function testManyTinyElementsAreRejected(): void {
        $this->assertTrue(JsonComplexity::isTooComplex('[' . str_repeat('1,', JsonComplexity::MAX_ELEMENTS + 1) . '1]'));
    }

    public function testExactlyAtTheLimitIsAccepted(): void {
        $this->assertFalse(JsonComplexity::isTooComplex(str_repeat('{', JsonComplexity::MAX_OBJECTS)));
        $this->assertFalse(JsonComplexity::isTooComplex(str_repeat(',', JsonComplexity::MAX_ELEMENTS)));
    }
}
