<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use OCA\MovieDB\Service\ReleaseNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly: notifies about episodes that aired and watchlist titles that were
 * released today or in the last two days (see ReleaseNotificationService).
 */
class ReleaseNotificationJob extends TimedJob {
    public const INTERVAL_SECONDS = 3600;

    public function __construct(
        ITimeFactory $time,
        private ReleaseNotificationService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        try {
            $episodes = $this->service->notifyAiredEpisodes();
            $titles = $this->service->notifyReleasedWatchlist();
            if ($episodes + $titles > 0) {
                $this->logger->info("MovieDB release notifications sent: $episodes for episodes, $titles for watchlist titles");
            }
        } catch (\Throwable $e) {
            $this->logger->error('MovieDB release notification job failed', ['exception' => $e]);
        }
    }
}
