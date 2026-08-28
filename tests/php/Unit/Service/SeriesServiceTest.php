<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

/**
 * Unit tests for SeriesService — the episode-level TV layer.
 *
 * Model (v1.3.0, series-owned metadata): the TV show carries its own
 * rating/platform/language/date in a single series-level watch row
 * (series_id set, episode_id NULL). Episodes are a pure watched/unwatched
 * boolean flag on moviedb_episodes. Progress is derived from that flag;
 * mark-watched flips it (no per-episode watch rows).
 */
class SeriesServiceTest extends TestCase {
    private SeriesMapper $mapper;
    private EpisodeMapper $episodeMapper;
    private MovieWatchMapper $watchMapper;
    private PlatformMapper $platformMapper;
    private TmdbService $tmdbService;
    private IDBConnection $db;
    private SeriesService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->mapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->platformMapper = $this->createMock(PlatformMapper::class);
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->db = $this->createMock(IDBConnection::class);

        // Default: no series-level watch metadata. findWithProgress tests that
        // assert on the rollup re-stub via stubSummary().
        $this->stubSummary();
    }

    /**
     * Stub the series-level watch summary used by findWithProgress. Called once
     * in setUp with nulls; tests needing real values re-create the mock.
     */
    private function stubSummary(
        ?string $watchedAt = null,
        ?int $rating = null,
        ?int $platformId = null,
        ?string $languageWatched = null,
        ?string $review = null
    ): void {
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->watchMapper->method('getSeriesWatchSummary')->willReturn([
            'watchedAt' => $watchedAt,
            'rating' => $rating,
            'platformId' => $platformId,
            'languageWatched' => $languageWatched,
            'review' => $review,
        ]);
        $this->service = new SeriesService(
            $this->mapper,
            $this->episodeMapper,
            $this->watchMapper,
            $this->platformMapper,
            $this->tmdbService,
            $this->db,
        );
    }

    // ---- findWithProgress: derived aggregation from the watched flag ----

    public function testProgressExcludesSpecialsAndUnaired(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        // Season 0 special (aired, watched), S1E1 aired+watched, S1E2 aired+
        // unwatched, S1E3 unaired. Denominator = aired non-specials = 2,
        // watched = 1 → 50%. The watched special must NOT count.
        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01', true),  // special, watched — ignored
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),  // aired, watched
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03', false), // aired, unwatched
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01', false), // unaired
        ];

        $this->mapper->method('find')->with($seriesId, $userId)->willReturn($series);
        $this->episodeMapper->method('findBySeries')->with($seriesId)->willReturn($episodes);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertSame(2, $result['airedEpisodeCount']);
        $this->assertSame(1, $result['watchedEpisodeCount']);
        $this->assertSame(50, $result['progress']);
        $this->assertFalse($result['caughtUp']);
    }

    public function testFindWithProgressExposesSeriesLevelWatchMetadata(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        // Real series-level watch row: date, rating, platform 7, language.
        $this->stubSummary('2020-03-04', 9, 7, 'de', 'Great show');

        $this->mapper->method('find')->willReturn($series);
        $this->episodeMapper->method('findBySeries')->willReturn([
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),
        ]);

        $platform = new \OCA\MovieDB\Db\Platform();
        $platform->setId(7);
        $platform->setName('Netflix');
        $this->platformMapper->method('find')->with(7)->willReturn($platform);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertSame('2020-03-04', $result['watchedAt']);
        $this->assertSame(9, $result['rating']);
        $this->assertSame('de', $result['languageWatched']);
        $this->assertSame(7, $result['platformId']);
        $this->assertSame('Netflix', $result['platformName']);
        $this->assertSame('Great show', $result['review']);
    }

    public function testNextEpisodeIsFirstAiredUnwatchedNonSpecial(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01', false), // special, unwatched — skip
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),  // aired, watched
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03', false), // aired, unwatched → next
            $this->makeEpisode(13, $seriesId, 1, 3, '2000-01-04', false), // aired, unwatched
        ];

        $this->mapper->method('find')->willReturn($series);
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertNotNull($result['nextEpisode']);
        $this->assertSame(12, $result['nextEpisode']['id']);
    }

    public function testCaughtUpWhenAllAiredWatched(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03', true),
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01', false), // unaired — ignored
        ];

        $this->mapper->method('find')->willReturn($series);
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);

        $result = $this->service->findWithProgress($seriesId, $userId);

        $this->assertSame(100, $result['progress']);
        $this->assertTrue($result['caughtUp']);
        $this->assertNull($result['nextEpisode']);
        // Per-episode watched flag surfaces through the seasons array.
        $season1 = $result['seasons'][0];
        $this->assertSame(1, $season1['seasonNumber']);
        $this->assertTrue($season1['episodes'][1]['watched']);
    }

    // ---- mark-watched: flip the boolean flag ----

    public function testMarkSeasonWatchedFlipsAiredUnwatchedNonSpecials(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $season = 1;

        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),  // already watched → skip
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03', false), // aired, unwatched → flip
            $this->makeEpisode(13, $seriesId, 1, 3, '2999-01-01', false), // unaired → skip
        ];

        $this->mapper->method('find')->with($seriesId, $userId)->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeriesAndSeason')
            ->with($seriesId, $season)
            ->willReturn($episodes);

        // Only episode 12 flips.
        $this->episodeMapper->expects($this->once())
            ->method('setWatchedForEpisodes')
            ->with([12], true)
            ->willReturn(1);
        $this->watchMapper->expects($this->never())->method('insert');

        $changed = $this->service->markSeasonWatched($seriesId, $season, $userId);

        $this->assertSame(1, $changed);
    }

    public function testMarkSeasonUnwatchedFlipsWatchedEpisodes(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $season = 1;

        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),  // watched → unflip
            $this->makeEpisode(12, $seriesId, 1, 2, '2000-01-03', false), // already unwatched → skip
        ];

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeriesAndSeason')->willReturn($episodes);

        $this->episodeMapper->expects($this->once())
            ->method('setWatchedForEpisodes')
            ->with([11], false)
            ->willReturn(1);

        $changed = $this->service->markSeasonWatched($seriesId, $season, $userId, false);

        $this->assertSame(1, $changed);
    }

    public function testMarkSeriesWatchedExcludesSpecialsAndUnaired(): void {
        $seriesId = 1;
        $userId = 'testuser';

        $episodes = [
            $this->makeEpisode(10, $seriesId, 0, 1, '2000-01-01', false), // special → excluded
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', false), // aired, unwatched → flip
            $this->makeEpisode(12, $seriesId, 1, 2, '2999-01-01', false), // unaired → skip
        ];

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);

        $this->episodeMapper->expects($this->once())
            ->method('setWatchedForEpisodes')
            ->with([11], true)
            ->willReturn(1);

        $changed = $this->service->markSeriesWatched($seriesId, $userId);

        $this->assertSame(1, $changed);
    }

    public function testMarkSeriesWatchedNoOpWhenNothingChanges(): void {
        $seriesId = 1;
        $userId = 'testuser';

        // All aired non-specials already watched → bulk setter never called.
        $episodes = [
            $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true),
        ];

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('findBySeries')->willReturn($episodes);

        $this->episodeMapper->expects($this->never())->method('setWatchedForEpisodes');

        $changed = $this->service->markSeriesWatched($seriesId, $userId);

        $this->assertSame(0, $changed);
    }

    public function testMarkEpisodeWatchedFlipsFlagAndUpdates(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $episode = $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', false);

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->with(11)->willReturn($episode);

        $this->episodeMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (Episode $e) => $e->getId() === 11 && $e->getWatched() === true))
            ->willReturnArgument(0);
        $this->watchMapper->expects($this->never())->method('insert');

        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    public function testMarkEpisodeUnwatchedFlipsFlagOff(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $episode = $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true);

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->willReturn($episode);

        $this->episodeMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (Episode $e) => $e->getWatched() === false))
            ->willReturnArgument(0);

        $this->service->markEpisodeWatched($seriesId, 11, $userId, false);
    }

    public function testMarkEpisodeWatchedIsIdempotent(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $episode = $this->makeEpisode(11, $seriesId, 1, 1, '2000-01-02', true);

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->willReturn($episode);

        // Already watched → no update.
        $this->episodeMapper->expects($this->never())->method('update');

        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    public function testMarkEpisodeWatchedRejectsForeignEpisode(): void {
        $seriesId = 1;
        $userId = 'testuser';
        // Episode belongs to a different series.
        $episode = $this->makeEpisode(11, 999, 1, 1, '2000-01-02', false);

        $this->mapper->method('find')->willReturn($this->makeSeries($seriesId));
        $this->episodeMapper->method('find')->willReturn($episode);
        $this->episodeMapper->expects($this->never())->method('update');

        $this->expectException(DoesNotExistException::class);
        $this->service->markEpisodeWatched($seriesId, 11, $userId);
    }

    // ---- series-level watch metadata upsert ----

    public function testUpsertSeriesWatchInsertsWhenNone(): void {
        $seriesId = 1;
        $userId = 'testuser';

        $this->watchMapper->method('findSeriesWatch')->with($seriesId, $userId)->willReturn(null);
        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $w) use ($seriesId, $userId) {
                return $w->getSeriesId() === $seriesId
                    && $w->getEpisodeId() === null
                    && $w->getMovieId() === null
                    && $w->getUserId() === $userId
                    && $w->getRating() === 9
                    && $w->getPlatformId() === 3
                    && $w->getLanguageWatched() === 'de'
                    && $w->getWatchedAt() === '2020-05-06';
            }))
            ->willReturnArgument(0);
        $this->watchMapper->expects($this->never())->method('update');

        $this->service->upsertSeriesWatch($seriesId, $userId, [
            'rating' => 9,
            'platformId' => 3,
            'languageWatched' => 'de',
            'watchedAt' => '2020-05-06',
        ]);
    }

    public function testUpsertSeriesWatchUpdatesExistingRow(): void {
        $seriesId = 1;
        $userId = 'testuser';

        $existing = new MovieWatch();
        $existing->setId(50);
        $existing->setSeriesId($seriesId);
        $existing->setUserId($userId);
        $existing->setRating(4);

        $this->watchMapper->method('findSeriesWatch')->willReturn($existing);
        $this->watchMapper->expects($this->never())->method('insert');
        $this->watchMapper->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (MovieWatch $w) => $w->getId() === 50 && $w->getRating() === 8))
            ->willReturnArgument(0);

        $this->service->upsertSeriesWatch($seriesId, $userId, ['rating' => 8]);
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

    // ---- createFromTmdb: eager episode fetch + optional series metadata ----

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

        // No watch metadata supplied → no series-level watch row.
        $this->watchMapper->expects($this->never())->method('insert');

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');

        $result = $this->service->createFromTmdb($userId, $data);

        $this->assertInstanceOf(Series::class, $result);
        $this->assertSame(1, $result->getId());
    }

    public function testCreateFromTmdbPersistsSeriesWatchMetadata(): void {
        $userId = 'testuser';
        $data = [
            'tmdbId' => 1399,
            'title' => 'Test Show',
            'numberOfSeasons' => 0,
            'seasons' => [],
            // Add form supplied series-level metadata.
            'rating' => 8,
            'platformId' => 2,
            'languageWatched' => 'en',
            'watchedAt' => '2021-01-01',
        ];

        $this->mapper->method('insert')->willReturnCallback(function (Series $s) {
            $s->setId(1);
            return $s;
        });

        // No episodes to fetch (0 seasons).
        $this->watchMapper->method('findSeriesWatch')->willReturn(null);
        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (MovieWatch $w) {
                return $w->getSeriesId() === 1
                    && $w->getEpisodeId() === null
                    && $w->getRating() === 8
                    && $w->getPlatformId() === 2
                    && $w->getLanguageWatched() === 'en'
                    && $w->getWatchedAt() === '2021-01-01';
            }))
            ->willReturnArgument(0);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->service->createFromTmdb($userId, $data);
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
        $this->episodeMapper->method('findByTmdbId')->with(501)->willReturn($this->makeEpisode(77, 1, 1, 1, '2000-01-01', false));
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

    // ---- update: persists series-level metadata ----

    public function testUpdatePersistsSeriesWatchMetadata(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $this->mapper->method('find')->willReturn($series);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->watchMapper->method('findSeriesWatch')->willReturn(null);
        $this->watchMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (MovieWatch $w) => $w->getRating() === 7 && $w->getPlatformId() === 4))
            ->willReturnArgument(0);

        $this->service->update($seriesId, $userId, [
            'title' => 'Renamed',
            'rating' => 7,
            'platformId' => 4,
        ]);
    }

    public function testUpdateWithoutMetadataDoesNotTouchWatchRow(): void {
        $seriesId = 1;
        $userId = 'testuser';
        $series = $this->makeSeries($seriesId);

        $this->mapper->method('find')->willReturn($series);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->watchMapper->expects($this->never())->method('insert');
        $this->watchMapper->expects($this->never())->method('update');

        $this->service->update($seriesId, $userId, ['title' => 'Renamed', 'isFavorite' => true]);
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

    private function makeEpisode(int $id, int $seriesId, int $season, int $number, ?string $airDate, bool $watched): Episode {
        $ep = new Episode();
        $ep->setId($id);
        $ep->setSeriesId($seriesId);
        $ep->setSeasonNumber($season);
        $ep->setEpisodeNumber($number);
        $ep->setName('Episode ' . $number);
        $ep->setAirDate($airDate);
        $ep->setRuntime(45);
        $ep->setWatched($watched);
        $ep->setCreatedAt('2026-01-01 12:00:00');
        return $ep;
    }
}
