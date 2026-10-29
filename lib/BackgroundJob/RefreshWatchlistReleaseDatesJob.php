<?php

declare(strict_types=1);

namespace OCA\MovieDB\BackgroundJob;

use DateTime;
use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\SeriesRefreshService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Service\WatchlistReleaseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Config\IUserConfig;
use Psr\Log\LoggerInterface;

/**
 * Once a day, looks up the release date of watchlist items that have none yet
 * or a date that is still ahead, and takes over what TMDB says (see
 * WatchlistReleaseService). Released titles are not asked again. TMDB gets a
 * short pause between items, far below its rate limit, and the run stops when
 * TMDB asks to slow down. The library owner's TMDB key is used, or the
 * instance-wide one.
 */
class RefreshWatchlistReleaseDatesJob extends TimedJob {
    public const INTERVAL_SECONDS = 24 * 3600;
    public const BATCH_SIZE = 500;
    private const PAUSE_MICROSECONDS = 250000;

    /** @var array<int, string> library id => owner */
    private array $owners = [];

    public function __construct(
        ITimeFactory $time,
        private WatchlistMapper $watchlistMapper,
        private LibraryMapper $libraryMapper,
        private WatchlistReleaseService $releaseService,
        private TmdbService $tmdbService,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $due = $this->watchlistMapper->findDueForReleaseCheck(self::BATCH_SIZE, (new DateTime())->format('Y-m-d'));

        $checked = 0;
        $changed = 0;
        $skipped = 0;
        foreach ($due as $item) {
            $owner = $this->ownerOf($item->getLibraryId(), $item->getUserId());
            if (!$this->tmdbService->hasApiKey($owner)) {
                $skipped++;
                continue;
            }

            try {
                $language = $this->userConfig->getValueString($owner, Application::APP_ID, 'default_language', 'de-DE');
                if ($this->releaseService->refresh($item, $owner, $language)) {
                    $changed++;
                }
                $checked++;
            } catch (\Throwable $e) {
                if (SeriesRefreshService::isRateLimited($e)) {
                    $this->logger->warning('TMDB asked to slow down; the release date check continues in the next run.', ['checked' => $checked]);
                    return;
                }
                // A removed or broken title is simply tried again tomorrow.
                $this->logger->warning('Could not check the release date of a watchlist item at TMDB.', ['exception' => $e, 'itemId' => $item->getId()]);
            }
            $this->pause();
        }

        $this->logger->debug('Watchlist release date check finished.', ['checked' => $checked, 'changed' => $changed, 'skippedWithoutKey' => $skipped]);
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
