<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use OCA\MovieDB\Service\ReleaseNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes the release notifications a user had for the shows and watchlist entries of a
 * library after leaving it. One call per entry can add up in a big shared library, so
 * removing a member only queues this job instead of making the request wait for it.
 */
class DismissLibraryNotificationsJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ReleaseNotificationService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /** @param array{libraryId?: int, userId?: string} $argument */
    protected function run($argument): void {
        $libraryId = (int)($argument['libraryId'] ?? 0);
        $userId = isset($argument['userId']) ? (string)$argument['userId'] : null;
        if ($libraryId <= 0 || $userId === null || $userId === '') {
            return;
        }
        try {
            $this->service->dismissLibrary($libraryId, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not remove the MovieDB notifications of a former library member', ['exception' => $e]);
        }
    }
}
