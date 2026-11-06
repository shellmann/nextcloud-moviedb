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
 * crash can at worst send twice, never lose a notification silently.
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
            foreach ($this->recipientsOf((int)$first['library_id']) as $userId) {
                $sent += $this->send($userId, Notifier::SUBJECT_EPISODES, Notifier::OBJECT_SERIES, (string)$first['series_id'], $params);
            }
        }

        $this->episodeMapper->markNotified(array_map(static fn (array $r): int => (int)$r['id'], $rows), $this->now());

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
            foreach ($users as $userId) {
                $sent += $this->send($userId, Notifier::SUBJECT_WATCHLIST, Notifier::OBJECT_WATCHLIST, (string)$item->getId(), $params);
            }
        }

        $this->watchlistMapper->markReleaseNotified(array_map(static fn ($i): int => (int)$i->getId(), $items), $this->now());

        return $sent;
    }

    /** Removes the pending notifications about one show (it was deleted). */
    public function dismissSeries(int $seriesId, ?string $userId = null): void {
        $this->dismiss(Notifier::OBJECT_SERIES, (string)$seriesId, $userId);
    }

    /** Removes the pending notifications about one watchlist item. */
    public function dismissWatchlistItem(int $itemId, ?string $userId = null): void {
        $this->dismiss(Notifier::OBJECT_WATCHLIST, (string)$itemId, $userId);
    }

    /** Removes the pending notifications about everything in a library (for one user, or all). */
    public function dismissLibrary(int $libraryId, ?string $userId = null): void {
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $series) {
            $this->dismissSeries((int)$series->getId(), $userId);
        }
        foreach ($this->watchlistMapper->findAllByLibrary($libraryId) as $item) {
            $this->dismissWatchlistItem((int)$item->getId(), $userId);
        }
    }

    private function dismiss(string $type, string $id, ?string $userId): void {
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
        }
    }

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
            return 0;
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
