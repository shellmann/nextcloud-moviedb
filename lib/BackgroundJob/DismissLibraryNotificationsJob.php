<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Removes the release notifications a user had for the shows and watchlist entries of a
 * library after leaving it. One call per entry can add up in a big shared library, so
 * removing a member only adds this job instead of making the request wait for it.
 *
 * It is a timed job that runs every hour until it succeeds (and is then removed), so a
 * failing cleanup (database timeout, ...) is retried by itself, no matter whether anybody calls
 * the removal again. It gives up after MAX_AGE_SECONDS and logs that. It does nothing when the
 * user is a member again by the time it runs: the notifications they got since then are theirs.
 */
class DismissLibraryNotificationsJob extends TimedJob {
    public const INTERVAL_SECONDS = 3600;
    public const MAX_AGE_SECONDS = 3 * 24 * 3600;

    public function __construct(
        private ITimeFactory $clock,
        private ReleaseNotificationService $service,
        private LibraryMemberMapper $members,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($clock);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    /** @param array{libraryId?: int, userId?: string}|mixed $argument */
    protected function run($argument): void {
        $argument = is_array($argument) ? $argument : [];
        $libraryId = (int)($argument['libraryId'] ?? 0);
        $userId = isset($argument['userId']) ? (string)$argument['userId'] : '';
        if ($libraryId <= 0 || $userId === '') {
            $this->jobList->remove($this, $argument);
            return;
        }

        // The cleanup is done (or was never pending): this job is not needed any more.
        $since = $this->service->cleanupPendingSince($libraryId, $userId);
        if ($since === null) {
            $this->jobList->remove($this, $argument);
            return;
        }

        try {
            $isGone = fn (): bool => $this->members->findMembership($libraryId, $userId) === null;
            if ($isGone()) {
                $this->service->dismissLibrary($libraryId, $userId, true, $isGone);
            }
            // done, or moot because the user is back: the job has served its purpose
            $this->jobList->remove($this, $argument);
        } catch (\Throwable $e) {
            if ($this->clock->getTime() - $since > self::MAX_AGE_SECONDS) {
                $this->logger->warning('Gave up removing the MovieDB notifications of a former library member', ['exception' => $e]);
                $this->jobList->remove($this, $argument);
                return;
            }
            // stays in the job list: the next run (in an hour) tries again
            $this->logger->info('Removing the MovieDB notifications of a former library member failed, retrying later', ['exception' => $e]);
        }
    }
}
