<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\WatchlistMapper;

class StatsService {
    private MovieMapper $movieMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;

    public function __construct(
        MovieMapper $movieMapper,
        WatchlistMapper $watchlistMapper,
        PlatformMapper $platformMapper
    ) {
        $this->movieMapper = $movieMapper;
        $this->watchlistMapper = $watchlistMapper;
        $this->platformMapper = $platformMapper;
    }

    public function getOverview(string $userId): array {
        $totalMovies = $this->movieMapper->countAll($userId);
        $totalRuntime = $this->movieMapper->getTotalRuntime($userId);
        $avgRating = $this->movieMapper->getAverageRating($userId);
        $watchlistCount = $this->watchlistMapper->countAll($userId);

        // Get this year's count
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
        return $this->movieMapper->getCountByYear($userId);
    }

    public function getStatsByPlatform(string $userId): array {
        $countByPlatform = $this->movieMapper->getCountByPlatform($userId);
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

        // Sort by count descending
        usort($result, fn($a, $b) => $b['count'] - $a['count']);

        return $result;
    }

    public function getStatsByGenre(string $userId): array {
        // This would require a more complex query to aggregate JSON genre_ids
        // For now, return an empty array - can be implemented later
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
