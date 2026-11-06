<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes the release notifications a user had for the shows and watchlist entries of a
 * library after leaving it. One call per entry can add up in a big shared library, so
 * removing a member only queues this job instead of making the request wait for it.
 *
 * Nextcloud removes a queued job before it runs, so a failure re-queues it (three tries).
 * It does nothing when the user is a member again by the time it runs: the notifications
 * they got since then are theirs.
 */
class DismissLibraryNotificationsJob extends QueuedJob {
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        ITimeFactory $time,
        private ReleaseNotificationService $service,
        private LibraryMemberMapper $members,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /** @param array{libraryId?: int, userId?: string, attempt?: int} $argument */
    protected function run($argument): void {
        $libraryId = (int)($argument['libraryId'] ?? 0);
        $userId = isset($argument['userId']) ? (string)$argument['userId'] : null;
        $attempt = (int)($argument['attempt'] ?? 0);
        if ($libraryId <= 0 || $userId === null || $userId === '') {
            return;
        }
        try {
            $isGone = fn (): bool => $this->members->findMembership($libraryId, $userId) === null;
            if (!$isGone()) {
                return;
            }
            $this->service->dismissLibrary($libraryId, $userId, true, $isGone);
        } catch (\Throwable $e) {
            if ($attempt + 1 < self::MAX_ATTEMPTS) {
                $this->jobList->add(self::class, ['libraryId' => $libraryId, 'userId' => $userId, 'attempt' => $attempt + 1]);
                return;
            }
            $this->logger->warning('Could not remove the MovieDB notifications of a former library member', ['exception' => $e]);
        }
    }
}
