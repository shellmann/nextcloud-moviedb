<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\StatsService;
use OCA\MovieDB\Tests\Unit\TestCase;

/**
 * Unit tests for StatsService — the dashboard aggregates.
 *
 * Covers the v1.3.0 change that folds TV shows into the dashboard's Top Rated
 * list (getTopRatedSeries) alongside the movies-only getTopRated. The combined
 * average rating is enforced at the mapper layer (getAverageRating drops its
 * movie_id filter) and passes straight through getOverview.
 */
class StatsServiceTest extends TestCase {
    private MovieMapper $movieMapper;
    private MovieWatchMapper $watchMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;
    private SeriesMapper $seriesMapper;
    private EpisodeMapper $episodeMapper;
    private StatsService $service;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->movieMapper = $this->createMock(MovieMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->watchlistMapper = $this->createMock(WatchlistMapper::class);
        $this->platformMapper = $this->createMock(PlatformMapper::class);
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);

        $this->service = new StatsService(
            $this->movieMapper,
            $this->watchMapper,
            $this->watchlistMapper,
            $this->platformMapper,
            $this->seriesMapper,
            $this->episodeMapper
        );
    }

    public function testGetTopRatedSeriesSortsByRatingDescending(): void {
        $this->seriesMapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, ['sort' => 'rating', 'dir' => 'DESC'], 20, 0)
            ->willReturn([
                $this->makeSeries(1, 9),
                $this->makeSeries(2, 7),
            ]);

        $result = $this->service->getTopRatedSeries('testuser', self::LIBRARY_ID, 5);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->getId());
        $this->assertSame(2, $result[1]->getId());
    }

    public function testGetTopRatedSeriesFiltersOutUnratedSeries(): void {
        // findAll's rating sort keeps NULL-rated series in the result; the
        // service must drop them so the dashboard only shows rated shows.
        $this->seriesMapper->method('findAll')->willReturn([
            $this->makeSeries(1, 8),
            $this->makeSeries(2, null),
            $this->makeSeries(3, 6),
        ]);

        $result = $this->service->getTopRatedSeries('testuser', self::LIBRARY_ID, 5);

        $this->assertCount(2, $result);
        $ids = array_map(static fn (Series $s) => $s->getId(), $result);
        $this->assertSame([1, 3], $ids);
    }

    public function testGetTopRatedSeriesSlicesToLimit(): void {
        $this->seriesMapper->method('findAll')->willReturn([
            $this->makeSeries(1, 10),
            $this->makeSeries(2, 9),
            $this->makeSeries(3, 8),
        ]);

        $result = $this->service->getTopRatedSeries('testuser', self::LIBRARY_ID, 2);

        $this->assertCount(2, $result);
        $this->assertSame([1, 2], array_map(static fn (Series $s) => $s->getId(), $result));
    }

    public function testGetTopRatedSeriesOverFetchesFourTimesTheLimit(): void {
        $this->seriesMapper->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, ['sort' => 'rating', 'dir' => 'DESC'], 12, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->getTopRatedSeries('testuser', self::LIBRARY_ID, 3));
    }

    public function testGetOverviewPassesThroughCombinedAverageRating(): void {
        // getAverageRating now averages movie + series rows; getOverview just
        // forwards whatever the mapper returns.
        $this->movieMapper->method('countAll')->willReturn(0);
        $this->watchMapper->method('getTotalRuntime')->willReturn(0);
        $this->episodeMapper->method('getWatchedRuntimeForUser')->willReturn(0);
        $this->episodeMapper->method('countWatchedForUser')->willReturn(0);
        $this->watchlistMapper->method('countAll')->willReturn(0);
        $this->seriesMapper->method('countAll')->willReturn(0);
        $this->watchMapper->method('getAverageRating')->willReturn(7.5);

        $overview = $this->service->getOverview('testuser', self::LIBRARY_ID);

        $this->assertSame(7.5, $overview['averageRating']);
    }

    public function testGetStatsByYearForwardsNullMediaTypeByDefault(): void {
        $this->watchMapper->expects($this->once())
            ->method('getCountByYear')
            ->with(self::LIBRARY_ID, null)
            ->willReturn(['2024' => 3]);

        $result = $this->service->getStatsByYear('testuser', self::LIBRARY_ID);

        $this->assertSame(['2024' => 3], $result);
    }

    public function testGetStatsByYearForwardsMediaTypeFilter(): void {
        $this->watchMapper->expects($this->once())
            ->method('getCountByYear')
            ->with(self::LIBRARY_ID, 'series')
            ->willReturn(['2022' => 1]);

        $result = $this->service->getStatsByYear('testuser', self::LIBRARY_ID, 'series');

        $this->assertSame(['2022' => 1], $result);
    }

    public function testGetStatsByPlatformForwardsNullMediaTypeByDefault(): void {
        $this->watchMapper->expects($this->once())
            ->method('getCountByPlatform')
            ->with(self::LIBRARY_ID, null)
            ->willReturn([1 => 5]);
        $this->platformMapper->method('find')->with(1)->willReturn($this->makePlatform(1, 'Netflix'));

        $result = $this->service->getStatsByPlatform('testuser', self::LIBRARY_ID);

        $this->assertSame([['id' => 1, 'name' => 'Netflix', 'icon' => null, 'count' => 5]], $result);
    }

    public function testGetStatsByPlatformForwardsMediaTypeFilter(): void {
        $this->watchMapper->expects($this->once())
            ->method('getCountByPlatform')
            ->with(self::LIBRARY_ID, 'movie')
            ->willReturn([2 => 3]);
        $this->platformMapper->method('find')->with(2)->willReturn($this->makePlatform(2, 'HBO Max'));

        $result = $this->service->getStatsByPlatform('testuser', self::LIBRARY_ID, 'movie');

        $this->assertSame([['id' => 2, 'name' => 'HBO Max', 'icon' => null, 'count' => 3]], $result);
    }

    public function testGetStatsByPlatformSortsByCountDescending(): void {
        $names = [1 => 'Netflix', 2 => 'HBO Max', 3 => 'Cinema'];
        $this->watchMapper->method('getCountByPlatform')->willReturn([1 => 2, 2 => 9, 3 => 5]);
        $this->platformMapper->method('find')->willReturnCallback(
            fn (int $id) => $this->makePlatform($id, $names[$id])
        );

        $result = $this->service->getStatsByPlatform('testuser', self::LIBRARY_ID);

        $this->assertSame(['HBO Max', 'Cinema', 'Netflix'], array_column($result, 'name'));
    }

    public function testGetStatsByPlatformSkipsDeletedPlatforms(): void {
        $this->watchMapper->method('getCountByPlatform')->willReturn([1 => 2, 999 => 4]);
        $this->platformMapper->method('find')->willReturnCallback(function (int $id) {
            if ($id === 999) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
            }
            return $this->makePlatform($id, 'Netflix');
        });

        $result = $this->service->getStatsByPlatform('testuser', self::LIBRARY_ID);

        $this->assertCount(1, $result);
        $this->assertSame('Netflix', $result[0]['name']);
    }

    private function makePlatform(int $id, string $name): \OCA\MovieDB\Db\Platform {
        $platform = new \OCA\MovieDB\Db\Platform();
        $platform->setId($id);
        $platform->setName($name);
        return $platform;
    }

    /**
     * @param array<int, string> $rowDates series id => date of the show's own watch row
     * @param array<int, ?string> $episodeActivity series id => latest watched-episode change (null: no timestamp)
     * @param int[] $ids the shows of the library
     */
    private function recentSeries(array $ids, array $rowDates, array $episodeActivity, int $limit = 5): array {
        $this->seriesMapper->method('lastWatchDatesByLibrary')->willReturn($rowDates);
        $this->episodeMapper->method('lastWatchActivityByLibrary')->willReturn($episodeActivity);
        $this->seriesMapper->method('findAllByLibrary')->willReturn(array_map(fn (int $id) => $this->seriesAddedOn($id, '2026-10-01 10:00:00'), $ids));
        $this->seriesMapper->method('findAll')->willReturnCallback(
            fn (int $library, array $filters) => array_map(fn (int $id) => $this->seriesAddedOn($id, '2026-10-01 10:00:00'), array_reverse($filters['ids']))
        );

        return $this->service->getRecentSeries('testuser', self::LIBRARY_ID, $limit);
    }

    private function seriesAddedOn(int $id, string $createdAt): Series {
        $series = new Series();
        $series->setId($id);
        $series->setCreatedAt($createdAt);
        return $series;
    }

    public function testGetRecentSeriesRanksByNewestEpisodeActivity(): void {
        // #122: ticking off an episode only moves episodes.updated_at, never the show's own watch row.
        $result = $this->recentSeries(
            [1, 2],
            [1 => '2026-11-06', 2 => '2026-11-01'],
            [2 => '2026-11-08 12:00:00'],
        );

        $this->assertSame([2, 1], array_map(static fn (Series $s) => $s->getId(), $result));
        $this->assertSame('2026-11-08', $result[0]->getLastWatchedAt());
        $this->assertSame('2026-11-06', $result[1]->getLastWatchedAt());
    }

    public function testGetRecentSeriesIncludesShowsWithoutWatchRow(): void {
        $result = $this->recentSeries([1], [], [1 => '2026-11-08 09:30:00']);

        $this->assertSame([1], array_map(static fn (Series $s) => $s->getId(), $result));
        $this->assertSame('2026-11-08', $result[0]->getLastWatchedAt());
    }

    public function testGetRecentSeriesCountsImportedEpisodesFromTheDayTheShowWasAdded(): void {
        // Imports keep only the watched flag, so the episodes carry no timestamp.
        $result = $this->recentSeries([1], [], [1 => null]);

        $this->assertSame([1], array_map(static fn (Series $s) => $s->getId(), $result));
        $this->assertSame('2026-10-01', $result[0]->getLastWatchedAt());
    }

    public function testGetRecentSeriesDropsShowsNeverWatched(): void {
        $this->assertSame([], $this->recentSeries([1, 2], [], []));
    }

    public function testGetRecentSeriesKeepsWatchRowWhenEpisodesAreOlder(): void {
        $result = $this->recentSeries([1], [1 => '2026-11-07'], [1 => '2026-10-01 20:00:00']);

        $this->assertSame('2026-11-07', $result[0]->getLastWatchedAt());
    }

    public function testGetRecentSeriesRanksTheWholeLibraryAndSlicesToLimit(): void {
        // The newest activity belongs to the last of 3000 shows: nothing is cut off by a scan limit.
        $ids = range(1, 3000);
        $rowDates = [];
        foreach ($ids as $id) {
            $rowDates[$id] = '2026-11-01';
        }
        $result = $this->recentSeries($ids, $rowDates, [3000 => '2026-11-08 08:00:00', 7 => '2026-11-07 08:00:00'], 2);

        $this->assertSame([3000, 7], array_map(static fn (Series $s) => $s->getId(), $result));
    }

    private function makeSeries(int $id, ?int $lastRating): Series {
        $series = new Series();
        $series->setId($id);
        $series->setLastRating($lastRating);
        return $series;
    }
}
