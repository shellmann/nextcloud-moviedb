<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use DateTime;
use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesRefreshService;
use OCA\MovieDB\Service\TmdbService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * Once a day, brings the episodes of the TV shows up to date with TMDB: new
 * seasons, new episodes, changed air dates (see SeriesRefreshService).
 *
 * Ongoing shows are checked daily, ended or canceled ones once a
 * month (that catches a revival and keeps the data well inside TMDB's
 * six-month cache limit). TMDB gets a short pause between shows, far below its
 * rate limit, and the run stops when TMDB asks to slow down. The library
 * owner's TMDB key is used, or the instance-wide one.
 */
class RefreshSeriesJob extends TimedJob {
    public const INTERVAL_SECONDS = 24 * 3600;
    public const BATCH_SIZE = 500;
    /** A bit under the 24 h interval, so a run that started late does not skip every show. */
    public const ONGOING_AFTER = '-23 hours';
    public const ENDED_AFTER = '-30 days';
    private const PAUSE_MICROSECONDS = 250000;

    /** @var array<int, string> library id => owner */
    private array $owners = [];

    public function __construct(
        ITimeFactory $time,
        private SeriesMapper $seriesMapper,
        private LibraryMapper $libraryMapper,
        private SeriesRefreshService $refreshService,
        private TmdbService $tmdbService,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $now = new DateTime();
        $due = $this->seriesMapper->findDueForRefresh(
            self::BATCH_SIZE,
            (clone $now)->modify(self::ONGOING_AFTER)->format('Y-m-d H:i:s'),
            (clone $now)->modify(self::ENDED_AFTER)->format('Y-m-d H:i:s'),
        );

        $refreshed = 0;
        $skipped = 0;
        foreach ($due as $series) {
            $owner = $this->ownerOf($series->getLibraryId(), $series->getUserId());
            if (!$this->tmdbService->hasApiKey($owner)) {
                // Nobody to ask TMDB for. Stamp it anyway, otherwise key-less shows (never
                // refreshed) sort first in every run and could fill the whole batch.
                $this->markQuietly($series);
                $skipped++;
                continue;
            }

            try {
                $language = $this->userConfig->getValueString($owner, Application::APP_ID, 'default_language', 'de-DE');
                $this->refreshService->refresh($series, $owner, $language);
                $refreshed++;
            } catch (\Throwable $e) {
                if (SeriesRefreshService::isRateLimited($e)) {
                    $this->logger->warning('TMDB asked to slow down; the show refresh continues in the next run.', ['refreshed' => $refreshed]);
                    return;
                }
                // Mark it as looked at, so a removed or broken show is retried at the normal interval.
                $this->logger->warning('Could not refresh a TV show from TMDB.', ['exception' => $e, 'seriesId' => $series->getId()]);
                $this->markQuietly($series);
            }
            $this->pause();
        }

        $this->logger->debug('TV show refresh finished.', ['refreshed' => $refreshed, 'skippedWithoutKey' => $skipped]);
    }

    private function markQuietly(Series $series): void {
        try {
            $this->refreshService->markRefreshed($series);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record the refresh attempt.', ['exception' => $e, 'seriesId' => $series->getId()]);
        }
    }

    /** Overridden in tests. */
    protected function pause(): void {
        usleep(self::PAUSE_MICROSECONDS);
    }

    private function ownerOf(?int $libraryId, string $fallback): string {
        if ($libraryId === null) {
            return $fallback;
        }
        if (!isset($this->owners[$libraryId])) {
            try {
                $this->owners[$libraryId] = $this->libraryMapper->find($libraryId)->getOwner();
            } catch (DoesNotExistException $e) {
                $this->owners[$libraryId] = $fallback;
            }
        }
        return $this->owners[$libraryId];
    }
}
