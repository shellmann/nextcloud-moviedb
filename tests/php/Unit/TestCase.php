<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for MovieDB unit tests
 *
 * Provides common utilities and helpers for all test cases.
 */
abstract class TestCase extends PHPUnitTestCase {
    /**
     * Assert that an array contains expected keys
     *
     * @param array $expectedKeys Expected keys
     * @param array $array Array to check
     * @param string $message Optional message
     */
    protected function assertArrayHasKeys(array $expectedKeys, array $array, string $message = ''): void {
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, $message ?: "Array is missing key: $key");
        }
    }
}
