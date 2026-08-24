<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\WatchlistMapper;

class StatsService {
    private MovieMapper $movieMapper;
    private MovieWatchMapper $watchMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;

    public function __construct(
        MovieMapper $movieMapper,
        MovieWatchMapper $watchMapper,
        WatchlistMapper $watchlistMapper,
        PlatformMapper $platformMapper
    ) {
        $this->movieMapper = $movieMapper;
        $this->watchMapper = $watchMapper;
        $this->watchlistMapper = $watchlistMapper;
        $this->platformMapper = $platformMapper;
    }

    public function getOverview(string $userId): array {
        $totalMovies = $this->movieMapper->countAll($userId);
        $totalRuntime = $this->watchMapper->getTotalRuntime($userId);
        $avgRating = $this->watchMapper->getAverageRating($userId);
        $watchlistCount = $this->watchlistMapper->countAll($userId);

        $currentYear = (int)date('Y');
        $thisYearMovies = $this->movieMapper->countAll($userId, [
            'year' => $currentYear
        ]);

        return [
            'totalMovies' => $totalMovies,
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

    public function getTopRated(string $userId, int $limit = 5): array {
        return $this->movieMapper->findAll($userId, [
            'sort' => 'rating',
            'dir' => 'DESC'
        ], $limit, 0);
    }
}

