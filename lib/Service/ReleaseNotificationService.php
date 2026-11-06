<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use DateTime;
use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Notification\Notifier;
use OCP\Config\IUserConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Sends the Nextcloud notifications "a new episode aired" and "a watchlist
 * title is out" to every member of the library (owner included) who has not
 * switched them off (user setting notify_releases, on by default).
 *
 * Nothing is sent for a backlog: a title counts only if it was added before it
 * was released, and only releases of the last two days are looked at, so a
 * show added late or a job that did not run for a while cannot flood anyone.
 * The notified_at / release_notified_at stamp is written after notify(), so a
 * crash can at worst send twice, never lose a notification silently. A row is only
 * stamped when every enabled recipient was served; a failed delivery is retried by
 * the next hourly run (inside the look-back window). TMDB moving a release date
 * clears the stamp, so the new date is announced.
 */
class ReleaseNotificationService {
    public const LOOKBACK_DAYS = 2;

    /** @var array<int, string[]> library id => user ids */
    private array $recipients = [];

    public function __construct(
        private EpisodeMapper $episodeMapper,
        private WatchlistMapper $watchlistMapper,
        private SeriesMapper $seriesMapper,
        private LibraryMapper $libraryMapper,
        private LibraryMemberMapper $memberMapper,
        private IUserConfig $userConfig,
        private IManager $notifications,
        private LoggerInterface $logger,
    ) {
    }

    /** @return int notifications sent */
    public function notifyAiredEpisodes(?string $today = null): int {
        $today ??= (new DateTime())->format('Y-m-d');
        $from = (new DateTime($today))->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');

        $rows = $this->episodeMapper->findToNotify($from, $today);
        if ($rows === []) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['series_id'] . '|' . $row['air_date']][] = $row;
        }

        $sent = 0;
        $done = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $last = end($group);
            $params = [
                'seriesId' => (int)$first['series_id'],
                'libraryId' => (int)$first['library_id'],
                'title' => (string)$first['series_title'],
                'season' => (int)$last['season_number'],
                'episode' => (int)$last['episode_number'],
                'count' => count($group),
            ];
            $failed = false;
            foreach ($this->recipientsOf((int)$first['library_id']) as $userId) {
                $result = $this->send($userId, Notifier::SUBJECT_EPISODES, Notifier::OBJECT_SERIES, (string)$first['series_id'], $params);
                $sent += max(0, $result);
                $failed = $failed || $result < 0;
            }
            // A failed delivery leaves the rows unstamped so the next hourly run retries them
            // (inside the look-back window). Recipients that already got it may see it twice.
            if (!$failed) {
                foreach ($group as $r) {
                    $done[(int)$r['id']] = (string)$r['air_date'];
                }
            }
        }

        $this->episodeMapper->markNotified($done, $this->now());

        return $sent;
    }

    /** @return int notifications sent */
    public function notifyReleasedWatchlist(?string $today = null): int {
        $today ??= (new DateTime())->format('Y-m-d');
        $from = (new DateTime($today))->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');

        $items = $this->watchlistMapper->findToNotify($from, $today);
        if ($items === []) {
            return 0;
        }

        $sent = 0;
        $done = [];
        foreach ($items as $item) {
            $libraryId = $item->getLibraryId();
            $users = $libraryId !== null ? $this->recipientsOf($libraryId) : [$item->getUserId()];
            $params = [
                'itemId' => (int)$item->getId(),
                'title' => $item->getTitle(),
                'mediaType' => $item->getMediaType(),
            ];
            if ($libraryId !== null) {
                $params['libraryId'] = $libraryId;
            }
            $failed = false;
            foreach ($users as $userId) {
                $result = $this->send($userId, Notifier::SUBJECT_WATCHLIST, Notifier::OBJECT_WATCHLIST, (string)$item->getId(), $params);
                $sent += max(0, $result);
                $failed = $failed || $result < 0;
            }
            if (!$failed) {
                $done[(int)$item->getId()] = (string)$item->getReleaseDate();
            }
        }

        $this->watchlistMapper->markReleaseNotified($done, $this->now());

        return $sent;
    }

    /** Removes the pending notifications about one show (it was deleted). */
    public function dismissSeries(int $seriesId, ?string $userId = null, bool $strict = false): void {
        $this->dismiss(Notifier::OBJECT_SERIES, (string)$seriesId, $userId, $strict);
    }

    /** Removes the pending notifications about one watchlist item. */
    public function dismissWatchlistItem(int $itemId, ?string $userId = null, bool $strict = false): void {
        $this->dismiss(Notifier::OBJECT_WATCHLIST, (string)$itemId, $userId, $strict);
    }

    /** Removes the pending notifications about everything in a library (for one user, or all). */
    /**
     * A user left a library and the cleanup of their notifications is still to do. Stored per user
     * (it survives a failed job and a failed request), so a repeated removal can tell "retry the
     * cleanup" from "nothing to do" without scanning the library for every caller.
     */
    public function markCleanupPending(int $libraryId, string $userId): void {
        // The value is the time the cleanup became pending; an existing marker keeps its (older) time,
        // so repeated removals do not postpone the give-up deadline of the retrying job.
        if (!$this->isCleanupPending($libraryId, $userId)) {
            $this->userConfig->setValueString($userId, Application::APP_ID, 'cleanup_pending_' . $libraryId, (string)time());
        }
    }

    /** @return ?int when the cleanup became pending, null when none is */
    public function cleanupPendingSince(int $libraryId, string $userId): ?int {
        $value = $this->userConfig->getValueString($userId, Application::APP_ID, 'cleanup_pending_' . $libraryId, '');
        return $value === '' ? null : (int)$value;
    }

    public function isCleanupPending(int $libraryId, string $userId): bool {
        return $this->cleanupPendingSince($libraryId, $userId) !== null;
    }

    public function clearCleanupPending(int $libraryId, ?string $userId): void {
        if ($userId !== null) {
            $this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'cleanup_pending_' . $libraryId);
        }
    }

    public function dismissLibrary(int $libraryId, ?string $userId = null, bool $strict = false, ?callable $stillWanted = null): void {
        // $stillWanted is asked before every entry: a long cleanup stops when the situation it was
        // started for is over (the user joined the library again).
        $go = static fn (): bool => $stillWanted === null || $stillWanted();
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $series) {
            if (!$go()) {
                $this->clearCleanupPending($libraryId, $userId); // the user is back: nothing left to do
                return;
            }
            $this->dismissSeries((int)$series->getId(), $userId, $strict);
        }
        foreach ($this->watchlistMapper->findAllByLibrary($libraryId) as $item) {
            if (!$go()) {
                $this->clearCleanupPending($libraryId, $userId);
                return;
            }
            $this->dismissWatchlistItem((int)$item->getId(), $userId, $strict);
        }
        // Only a cleanup that ran to the end (or was moot) clears the marker: a failure keeps it.
        $this->clearCleanupPending($libraryId, $userId);
    }

    /**
     * @param bool $strict throw on failure (the queued cleanup job retries) instead of only logging
     */
    private function dismiss(string $type, string $id, ?string $userId, bool $strict = false): void {
        try {
            $n = $this->notifications->createNotification();
            $n->setApp(Application::APP_ID)->setObject($type, $id);
            if ($userId !== null) {
                $n->setUser($userId);
            }
            $this->notifications->markProcessed($n);
        } catch (\Throwable $e) {
            // A stale notification must never break a delete.
            $this->logger->warning('Could not dismiss MovieDB notifications', ['exception' => $e]);
            if ($strict) {
                throw $e;
            }
        }
    }

    /** @return int 1 sent, 0 skipped (the user opted out), -1 failed */
    private function send(string $userId, string $subject, string $objectType, string $objectId, array $params): int {
        if ($this->userConfig->getValueString($userId, Application::APP_ID, 'notify_releases', '1') === '0') {
            return 0;
        }
        try {
            $n = $this->notifications->createNotification();
            $n->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new DateTime())
                ->setObject($objectType, $objectId)
                ->setSubject($subject, $params);
            $this->notifications->notify($n);
            return 1;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not send a MovieDB notification to ' . $userId, ['exception' => $e]);
            return -1;
        }
    }

    /** @return string[] owner first, then the members */
    private function recipientsOf(int $libraryId): array {
        if (!isset($this->recipients[$libraryId])) {
            $users = [];
            try {
                $users[] = $this->libraryMapper->find($libraryId)->getOwner();
            } catch (\Throwable) {
                // library gone: nobody to tell
            }
            foreach ($this->memberMapper->findByLibrary($libraryId) as $member) {
                $users[] = $member->getUserId();
            }
            $this->recipients[$libraryId] = array_values(array_unique($users));
        }
        return $this->recipients[$libraryId];
    }

    private function now(): string {
        return (new DateTime())->format('Y-m-d H:i:s');
    }
}
