<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Unit tests for SeriesService — the episode-level TV layer. Focuses on the
 * derived-progress math (specials excluded, unaired excluded, next-episode
 * selection), fan-out idempotency, and cascade delete.
 */
class SeriesServiceTest extends TestCase {
    private SeriesMapper $mapper;
    private EpisodeMapper $episodeMapper;
    private MovieWatchMapper $watchMapper;
    private TmdbService $tmdbService;
    private IDBConnection $db;
    private SeriesService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->service = new SeriesService(
            $this->mapper,
            $this->episodeMapper,
            $this->watchMapper,
            $this->tmdbService,
            $this->db,
        );
    }

    // ---- findWithProgress: derived aggregation ----

    public function testProgressExcludesSpecialsAndUnaired(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        // Season 0 special (aired), S1E1 aired+watched, S1E2 aired+unwatched,
        // S1E3 unaired (future). Denominator should be aired non-specials = 2,
        // watched = 1 → 50%.
        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01'), // special, aired
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02'), // aired, watched
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03'), // aired, unwatched
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01'), // unaired
        ];

        $this->mapper->method('find')->with($seriesId, $userId)->willReturn($series);
        $this->episodeMapper->method('findBySeries')->with($seriesId)->willReturn($episodes);
        // Special (10) also watched — must NOT count toward progress.
        $this->watchMapper->method('countWatchesPerEpisode')
            ->with($seriesId, $userId)
            ->willReturn([10 => 1, 11 => 1]);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertSame(2, $result['airedEpisodeCount']);
        $this->assertSame(1, $result['watchedEpisodeCount']);
        $this->assertSame(50, $result['progress']);
        $this->assertFalse($result['caughtUp']);
    }

    public function testNextEpisodeIsFirstAiredUnwatchedNonSpecial(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01'), // special, unwatched — skip
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02'), // aired, watched
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03'), // aired, unwatched → next
            $this->makeEpisode(13, $seriesId, 1, 3, '2000-01-04'), // aired, unwatched
        ];

        $this->mapper->method('find')->willReturn($series);
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);
        $this->watchMapper->method('countWatchesPerEpisode')->willReturn([11 => 1]);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertNotNull($result['nextEpisode']);
        $this->assertSame(12, $result['nextEpisode']['id']);
    }

    public function testCaughtUpWhenAllAiredWatched(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02'),
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03'),
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01'), // unaired — ignored
        ];

        $this->mapper->method('find')->willReturn($series);
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);
        $this->watchMapper->method('countWatchesPerEpisode')->willReturn([11 => 1, 12 => 2]);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertSame(100, $result['progress']);
        $this->assertTrue($result['caughtUp']);
        $this->assertNull($result['nextEpisode']);
        // Per-episode annotations surface through the seasons array.
        $season1 = $result['seasons'][0];
        $this->assertSame(1, $season1['seasonNumber']);
        $this->assertSame(2, $season1['episodes'][1]['watchCount']);
        $this->assertTrue($season1['episodes'][1]['watched']);
    }

    // ---- mark-watched fan-out idempotency ----

    public function testMarkSeasonWatchedSkipsAlreadyWatchedAndUnaired(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $season = 1;

        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02'), // already watched → skip
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03'), // aired, new → insert
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01'), // unaired → skip
        ];

        $this->mapper->method('find')->with($seriesId, $userId)->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeriesAndSeason')
            ->with($seriesId, $season)
            ->willReturn($episodes);
        $this->watchMapper->method('findWatchedEpisodeIds')
            ->with($seriesId, $userId)
            ->willReturn([11]);

        // Only episode 12 should be inserted.
        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $w) use ($seriesId) {
                return $w->getEpisodeId() === 12
                    && $w->getSeriesId() === $seriesId
                    && $w->getMovieId() === null;
            }))
            ->willReturnArgument(0);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $inserted = $this->service->markSeasonWatched($seriesId, $season, $userId);

        $this->assertSame(1, $inserted);
    }

    public function testMarkSeriesWatchedExcludesSpecials(): void {
        $seriesId = 1;
        $userId = 'testuser';

        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01'), // special → excluded
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02'), // aired, new → insert
            $this->makeEpisode(12, $seriesId, 1, 2, '2999-01-01'), // unaired → skip
        ];

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);
        $this->watchMapper->method('findWatchedEpisodeIds')->willReturn([]);

        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (MovieWatch $w) => $w->getEpisodeId() === 11))
            ->willReturnArgument(0);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $inserted = $this->service->markSeriesWatched($seriesId, $userId);

        $this->assertSame(1, $inserted);
    }

    public function testMarkEpisodeWatchedIsIdempotent(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $episode = $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02');

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->with(11)->willReturn($episode);
        $this->watchMapper->method('findWatchedEpisodeIds')->willReturn([11]);

        // Already watched → no insert.
        $this->watchMapper->expects($this->never())->method('insert');

        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    public function testMarkEpisodeWatchedInsertsWhenUnwatched(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $episode = $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02');

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->willReturn($episode);
        $this->watchMapper->method('findWatchedEpisodeIds')->willReturn([]);

        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $w) use ($seriesId) {
                return $w->getEpisodeId() === 11 && $w->getSeriesId() === $seriesId;
            }))
            ->willReturnArgument(0);

        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    public function testMarkEpisodeWatchedRejectsForeignEpisode(): void {
        $seriesId = 1;
        $userId = 'testuser';
        // Episode belongs to a different series.
        $episode = $this->makeEpisode(11, 999, 1, 1, '2000-01-02');

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->willReturn($episode);
        $this->watchMapper->expects($this->never())->method('insert');

        $this->expectException(DoesNotExistException::class);
        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    // ---- cascade delete ----

    public function testDeleteCascadesEpisodesAndWatchRows(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $this->mapper->method('find')->with($seriesId, $userId)->willReturn($series);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->watchMapper->expects($this->once())->method('deleteBySeries')->with($seriesId, $userId);
        $this->episodeMapper->expects($this->once())->method('deleteBySeries')->with($seriesId);
        $this->mapper->expects($this->once())->method('delete')->with($series);
        $this->db->expects($this->once())->method('commit');

        $this->service->delete($seriesId, $userId);
    }

    // ---- createFromTmdb: eager episode fetch ----

    public function testCreateFromTmdbFetchesAndStoresEpisodes(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 1399,
            'title' => 'Test Show',
            'numberOfSeasons' => 1,
            'seasons' => [['season_number' => 1]],
        ];

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Series $s) {
                $s->setId(1);
                return $s;
            });

        $this->tmdbService->expects($this->once())
            ->method('getSeasonDetails')
            ->with(1399, 1, $userId, 'en-US')
            ->willReturn(['episodes' => [
                ['id' => 501, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Pilot', 'air_date' => '2000-01-01', 'runtime' => 60],
                ['id' => 502, 'season_number' => 1, 'episode_number' => 2, 'name' => 'Ep 2', 'air_date' => '2000-01-08', 'runtime' => 58],
            ]]);

        // Neither episode exists yet.
        $this->episodeMapper->method('findByTmdbId')->willReturn(null);
        $this->episodeMapper->expects($this->exactly(2))
            ->method('insert')
            ->willReturnArgument(0);

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $result = $this->service->createFromTmdb($userId, $data);

        $this->assertInstanceOf(Series::class, $result);
        $this->assertSame(1, $result->getId());
    }

    public function testCreateFromTmdbSkipsExistingEpisodes(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 1399,
            'title' => 'Test Show',
            'numberOfSeasons' => 1,
            'seasons' => [['season_number' => 1]],
        ];

        $this->mapper->method('insert')->willReturnCallback(function (Series $s) {
            $s->setId(1);
            return $s;
        });
        $this->tmdbService->method('getSeasonDetails')->willReturn(['episodes' => [
            ['id' => 501, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Pilot'],
        ]]);
        // Episode 501 already stored → skip insert.
        $this->episodeMapper->method('findByTmdbId')->with(501)->willReturn($this->makeEpisode(77, 1, 1, 1, '2000-01-01'));
        $this->episodeMapper->expects($this->never())->method('insert');

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service->createFromTmdb($userId, $data);
    }

    /**
     * Regression: a specials season (season_number 0) must be imported. The
     * Episode entity defaults seasonNumber to 0, and Nextcloud's magic setter
     * only marks a field dirty on a value change — so setSeasonNumber(0) leaves
     * the column out of the QBMapper INSERT. The DB column carries a default 0
     * to fill it in; this test locks in that the specials episode is produced
     * with seasonNumber 0 rather than being dropped or mis-seasoned.
     */
    public function testCreateFromTmdbImportsSpecialsSeasonZero(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 1396,
            'title' => 'Breaking Bad',
            'numberOfSeasons' => 1,
            // TMDB lists specials as season 0 alongside real seasons.
            'seasons' => [['season_number' => 0], ['season_number' => 1]],
        ];

        $this->mapper->method('insert')->willReturnCallback(function (Series $s) {
            $s->setId(1);
            return $s;
        });

        $this->tmdbService->method('getSeasonDetails')->willReturnCallback(
            function (int $tmdbId, int $season) {
                return ['episodes' => [[
                    'id' => 900 + $season,
                    'season_number' => $season,
                    'episode_number' => 1,
                    'name' => 'S' . $season . 'E1',
                    'air_date' => '2008-02-17',
                ]]];
            }
        );
        $this->episodeMapper->method('findByTmdbId')->willReturn(null);

        $insertedSeasons = [];
        $this->episodeMapper->expects($this->exactly(2))
            ->method('insert')
            ->willReturnCallback(function (Episode $ep) use (&$insertedSeasons) {
                $insertedSeasons[] = $ep->getSeasonNumber();
                return $ep;
            });

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service->createFromTmdb($userId, $data);

        sort($insertedSeasons);
        $this->assertSame([0, 1], $insertedSeasons, 'Specials (season 0) must be imported, not dropped');
    }

    // ---- helpers ----

    private function makeSeries(int $id): Series {
        $series = new Series();
        $series->setId($id);
        $series->setUserId('testuser');
        $series->setTitle('Test Show');
        $series->setCreatedAt('2026-01-01 12:00:00');
        return $series;
    }

    private function makeEpisode(int $id, int $seriesId, int $season, int $number, ?string $airDate): Episode {
        $ep = new Episode();
        $ep->setId($id);
        $ep->setSeriesId($seriesId);
        $ep->setSeasonNumber($season);
        $ep->setEpisodeNumber($number);
        $ep->setName('Episode ' . $number);
        $ep->setAirDate($airDate);
        $ep->setRuntime(45);
        $ep->setCreatedAt('2026-01-01 12:00:00');
        return $ep;
    }
}
