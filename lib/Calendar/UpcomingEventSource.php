<?php

declare(strict_types=1);

namespace OCA\MovieDB\Calendar;

use DateTime;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Plain event records for the "MovieDB – Upcoming" calendar of one user:
 * episodes of followed shows and watchlist releases, over all libraries the
 * user can access, deduplicated across libraries.
 *
 * "Today" is the server date (UTC), like SeriesProgressService::today(): TMDB
 * air dates carry no time zone and the events are all-day events.
 *
 * @psalm-type EventRecord = array{uid: string, summary: string, date: string, description: string, url: string, lastModified: string}
 */
class UpcomingEventSource {
    public const DAYS_BACK = 30;
    public const DAYS_AHEAD = 180;

    public function __construct(
        private LibraryMapper $libraryMapper,
        private EpisodeMapper $episodeMapper,
        private WatchlistMapper $watchlistMapper,
        private IURLGenerator $urlGenerator,
    ) {
    }

    /**
     * @return list<array{uid: string, summary: string, date: string, description: string, url: string, lastModified: string}>
     */
    public function eventsFor(string $userId, IL10N $l, ?string $today = null): array {
        $today ??= (new DateTime())->format('Y-m-d');
        $from = (new DateTime($today))->modify('-' . self::DAYS_BACK . ' days')->format('Y-m-d');
        $until = (new DateTime($today))->modify('+' . self::DAYS_AHEAD . ' days')->format('Y-m-d');
        $base = $this->urlGenerator->linkToRouteAbsolute('moviedb.page.index');

        $events = [];
        foreach ($this->libraryMapper->findAccessible($userId) as $library) {
            $libraryId = (int)$library->getId();
            $query = '?library=' . $libraryId;

            foreach ($this->episodeMapper->airingBetween($libraryId, $from, $until) as $row) {
                $tmdbId = $row['series_tmdb_id'] ?? null;
                $season = (int)$row['season_number'];
                $episode = (int)$row['episode_number'];
                $uid = $tmdbId
                    ? sprintf('moviedb-ep-%d-s%de%d', (int)$tmdbId, $season, $episode)
                    : 'moviedb-ep-local-' . (int)$row['id'];
                $name = trim((string)($row['name'] ?? ''));
                $code = sprintf('S%02dE%02d', $season, $episode);
                $events[$uid] ??= [
                    'uid' => $uid,
                    'summary' => $name !== '' ? $row['series_title'] . ' ' . $code . ' · ' . $name : $row['series_title'] . ' ' . $code,
                    'date' => (string)$row['air_date'],
                    'description' => $library->getName(),
                    'url' => $base . '#/tv/' . (int)$row['series_id'] . $query,
                    // Stable, never "now": calendar clients compare it to decide whether to refetch.
                    'lastModified' => (string)($row['series_refreshed_at'] ?? $row['series_created_at'] ?? $row['created_at']),
                ];
            }

            foreach ($this->watchlistMapper->releasingBetween($libraryId, $from, $until) as $item) {
                $tmdbId = $item->getTmdbId();
                $uid = $tmdbId
                    ? sprintf('moviedb-wl-%s-%d', $item->getMediaType(), $tmdbId)
                    : 'moviedb-wl-local-' . (int)$item->getId();
                $events[$uid] ??= [
                    'uid' => $uid,
                    'summary' => $l->t('%s (release)', [$item->getTitle()]),
                    'date' => (string)$item->getReleaseDate(),
                    'description' => $library->getName(),
                    'url' => $base . '#/watchlist' . $query,
                    'lastModified' => (string)($item->getReleaseDateChangedAt() ?? $item->getAddedAt()),
                ];
            }
        }

        $events = array_values($events);
        usort($events, static fn (array $a, array $b): int => [$a['date'], $a['uid']] <=> [$b['date'], $b['uid']]);

        return $events;
    }
}
