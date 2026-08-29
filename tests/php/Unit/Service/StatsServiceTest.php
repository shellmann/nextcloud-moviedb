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
            ->with('testuser', ['sort' => 'rating', 'dir' => 'DESC'], 20, 0)
            ->willReturn([
                $this->makeSeries(1, 9),
                $this->makeSeries(2, 7),
            ]);

        $result = $this->service->getTopRatedSeries('testuser', 5);

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

        $result = $this->service->getTopRatedSeries('testuser', 5);

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

        $result = $this->service->getTopRatedSeries('testuser', 2);

        $this->assertCount(2, $result);
        $this->assertSame([1, 2], array_map(static fn (Series $s) => $s->getId(), $result));
    }

    public function testGetTopRatedSeriesOverFetchesFourTimesTheLimit(): void {
        $this->seriesMapper->expects($this->once())
            ->method('findAll')
            ->with('testuser', ['sort' => 'rating', 'dir' => 'DESC'], 12, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->getTopRatedSeries('testuser', 3));
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

        $overview = $this->service->getOverview('testuser');

        $this->assertSame(7.5, $overview['averageRating']);
    }

    private function makeSeries(int $id, ?int $lastRating): Series {
        $series = new Series();
        $series->setId($id);
        $series->setLastRating($lastRating);
        return $series;
    }
}
