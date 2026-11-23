<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

/**
 * Another request still holds the import lock of the same TV show (#147).
 */
class ImportLockedException extends \RuntimeException {
}
