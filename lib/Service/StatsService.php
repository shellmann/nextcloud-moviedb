<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
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

    public function getOverview(string $userId): array {
        $totalMovies = $this->movieMapper->countAll($userId);
        $movieRuntime = $this->watchMapper->getTotalRuntime($userId);
        $episodeRuntime = $this->episodeMapper->getWatchedRuntimeForUser($userId);
        $totalRuntime = $movieRuntime + $episodeRuntime;
        $avgRating = $this->watchMapper->getAverageRating($userId);
        $watchlistCount = $this->watchlistMapper->countAll($userId);
        $totalSeries = $this->seriesMapper->countAll($userId);
        $totalEpisodesWatched = $this->episodeMapper->countWatchedForUser($userId);

        $currentYear = (int)date('Y');
        $thisYearMovies = $this->movieMapper->countAll($userId, [
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

    public function getStatsByYear(string $userId): array {
        return $this->watchMapper->getCountByYear($userId);
    }

    public function getStatsByPlatform(string $userId): array {
        $countByPlatform = $this->watchMapper->getCountByPlatform($userId);
        $platforms = $this->platformMapper->findAllForUser($userId);

        $result = [];
        foreach ($platforms as $platform) {
            $count = $countByPlatform[$platform->getId()] ?? 0;
            if ($count > 0) {
                $result[] = [
                    'id' => $platform->getId(),
                    'name' => $platform->getName(),
                    'icon' => $platform->getIcon(),
                    'count' => $count,
                ];
            }
        }

        usort($result, fn($a, $b) => $b['count'] - $a['count']);

        return $result;
    }

    public function getStatsByGenre(string $userId): array {
        return [];
    }

    public function getRecentMovies(string $userId, int $limit = 5): array {
        return $this->movieMapper->findAll($userId, [
            'sort' => 'date_watched',
            'dir' => 'DESC'
        ], $limit, 0);
    }

    /**
     * Series with at least one watched episode, most-recently-watched first.
     * findAll left-joins the latest episode watch per series, so series that
     * have never been watched surface a null lastWatchedAt — filter those out
     * so the dashboard "recently watched" row only shows shows actually seen.
     *
     * @return Series[]
     */
    public function getRecentSeries(string $userId, int $limit = 5): array {
        $series = $this->seriesMapper->findAll($userId, [
            'sort' => 'date_watched',
            'dir' => 'DESC'
        ], $limit * 4, 0);

        $watched = array_filter($series, static fn ($s) => $s->getLastWatchedAt() !== null);

        return array_slice(array_values($watched), 0, $limit);
    }

    public function getTopRated(string $userId, int $limit = 5): array {
        return $this->movieMapper->findAll($userId, [
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
    public function getTopRatedSeries(string $userId, int $limit = 5): array {
        $series = $this->seriesMapper->findAll($userId, [
            'sort' => 'rating',
            'dir' => 'DESC'
        ], $limit * 4, 0);

        $rated = array_filter($series, static fn ($s) => $s->getLastRating() !== null);

        return array_slice(array_values($rated), 0, $limit);
    }
}

