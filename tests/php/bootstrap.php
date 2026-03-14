<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap file for MovieDB tests
 */

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

// Load Nextcloud stub/mock classes first (before autoloader)
require_once __DIR__ . '/NextcloudStubs.php';

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Mock Nextcloud constants if not defined
if (!defined('OC_UNITTEST_RUN')) {
    define('OC_UNITTEST_RUN', true);
}
