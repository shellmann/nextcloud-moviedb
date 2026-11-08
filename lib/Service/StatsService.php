<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;

class StatsService {
    private MovieMapper $movieMapper;
    private MovieWatchMapper $watchMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;
    private SeriesMapper $seriesMapper;
    private EpisodeMapper $episodeMapper;

    public function __construct(
        MovieMapper $movieMapper,
        MovieWatchMapper $watchMapper,
        WatchlistMapper $watchlistMapper,
        PlatformMapper $platformMapper,
        SeriesMapper $seriesMapper,
        EpisodeMapper $episodeMapper
    ) {
        $this->movieMapper = $movieMapper;
        $this->watchMapper = $watchMapper;
        $this->watchlistMapper = $watchlistMapper;
        $this->platformMapper = $platformMapper;
        $this->seriesMapper = $seriesMapper;
        $this->episodeMapper = $episodeMapper;
    }

    public function getOverview(string $userId, int $libraryId): array {
        $totalMovies = $this->movieMapper->countAll($libraryId);
        $movieRuntime = $this->watchMapper->getTotalRuntime($libraryId);
        $episodeRuntime = $this->episodeMapper->getWatchedRuntimeForUser($libraryId);
        $totalRuntime = $movieRuntime + $episodeRuntime;
        $avgRating = $this->watchMapper->getAverageRating($libraryId);
        $watchlistCount = $this->watchlistMapper->countAll($libraryId);
        $totalSeries = $this->seriesMapper->countAll($libraryId);
        $totalEpisodesWatched = $this->episodeMapper->countWatchedForUser($libraryId);

        $currentYear = (int)date('Y');
        $thisYearMovies = $this->movieMapper->countAll($libraryId, [
            'year' => $currentYear
        ]);

        return [
            'totalMovies' => $totalMovies,
            'totalSeries' => $totalSeries,
            'totalEpisodesWatched' => $totalEpisodesWatched,
            'totalRuntime' => $totalRuntime,
            'totalRuntimeHours' => round($totalRuntime / 60, 1),
            'averageRating' => $avgRating,
            'watchlistCount' => $watchlistCount,
            'thisYear' => $thisYearMovies,
        ];
    }

    public function getStatsByYear(string $userId, int $libraryId, ?string $mediaType = null): array {
        return $this->watchMapper->getCountByYear($libraryId, $mediaType);
    }

    public function getStatsByPlatform(string $userId, int $libraryId, ?string $mediaType = null): array {
        $countByPlatform = $this->watchMapper->getCountByPlatform($libraryId, $mediaType);

        $result = [];
        foreach ($countByPlatform as $platformId => $count) {
            try {
                $platform = $this->platformMapper->find((int)$platformId);
                $result[] = [
                    'id' => $platform->getId(),
                    'name' => $platform->getName(),
                    'icon' => $platform->getIcon(),
                    'count' => $count,
                ];
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // platform was deleted; skip
            }
        }

        usort($result, fn($a, $b) => $b['count'] - $a['count']);

        return $result;
    }

    public function getStatsByGenre(string $userId, int $libraryId): array {
        return [];
    }

    public function getRecentMovies(string $userId, int $libraryId, int $limit = 5): array {
        return $this->movieMapper->findAll($libraryId, [
            'sort' => 'date_watched',
            'dir' => 'DESC'
        ], $limit, 0);
    }

    /**
     * Shows to list under "Recently Watched", most recently watched first.
     *
     * A show's last activity is the later of its own watch row (the date set
     * when adding or editing it) and the newest change to one of its watched
     * episodes, specials included. Episodes are a plain watched flag with no
     * watch row of their own, so ticking one off only moves `episodes.updated_at`.
     * Watched episodes without a timestamp (an imported show) count from the day
     * the show was added. Shows with neither a watch date nor a watched episode
     * are left out. The returned `lastWatchedAt` is the date of that activity
     * (Y-m-d), the same format as a movie's, so the dashboard can merge both lists.
     *
     * @return Series[]
     */
    public function getRecentSeries(string $userId, int $libraryId, int $limit = 5): array {
        $rowDates = $this->seriesMapper->lastWatchDatesByLibrary($libraryId);
        $episodeActivity = $this->episodeMapper->lastWatchActivityByLibrary($libraryId);

        // Rank all shows of the library, so a show can never be cut off by a scan limit.
        $ranked = [];
        foreach ($this->seriesMapper->findAllByLibrary($libraryId) as $s) {
            $id = $s->getId();
            $fromEpisodes = array_key_exists($id, $episodeActivity) ? ($episodeActivity[$id] ?? $s->getCreatedAt()) : null;
            // Y-m-d and Y-m-d H:i:s compare correctly as strings; on the same day the episode wins.
            $last = max((string)($rowDates[$id] ?? ''), (string)$fromEpisodes);
            if ($last !== '') {
                $ranked[$id] = $last;
            }
        }
        arsort($ranked, SORT_STRING);
        $topIds = array_slice(array_keys($ranked), 0, $limit);
        if ($topIds === []) {
            return [];
        }

        // Load only the winners, with the rating and language the cards show.
        $byId = [];
        foreach ($this->seriesMapper->findAll($libraryId, ['ids' => $topIds], count($topIds), 0) as $s) {
            $s->setLastWatchedAt(substr($ranked[$s->getId()], 0, 10));
            $byId[$s->getId()] = $s;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?Series => $byId[$id] ?? null, $topIds)));
    }

    public function getTopRated(string $userId, int $libraryId, int $limit = 5): array {
        return $this->movieMapper->findAll($libraryId, [
            'sort' => 'rating',
            'dir' => 'DESC'
        ], $limit, 0);
    }

    /**
     * Top-rated series (by the series-level watch row's rating), highest first.
     * findAll's rating sort keeps NULL-rated (never-rated) series in the result,
     * so over-fetch and filter them out — mirrors getRecentSeries's approach for
     * the date_watched sort.
     *
     * @return Series[]
     */
    public function getTopRatedSeries(string $userId, int $libraryId, int $limit = 5): array {
        $series = $this->seriesMapper->findAll($libraryId, [
            'sort' => 'rating',
            'dir' => 'DESC'
        ], $limit * 4, 0);

        $rated = array_filter($series, static fn ($s) => $s->getLastRating() !== null);

        return array_slice(array_values($rated), 0, $limit);
    }
}

