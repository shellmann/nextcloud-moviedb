<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesRefreshService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

class SeriesRefreshServiceTest extends TestCase {
    private SeriesMapper&MockObject $seriesMapper;
    private EpisodeMapper&MockObject $episodeMapper;
    private TmdbService&MockObject $tmdb;
    private IDBConnection&MockObject $db;
    private SeriesRefreshService $service;

    /** @var string[] order of TMDB calls and transaction events */
    private array $events = [];
    /** @var Episode[] */
    private array $inserted = [];
    /** @var Episode[] */
    private array $updated = [];

    protected function setUp(): void {
        parent::setUp();
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('beginTransaction')->willReturnCallback(function (): void {
            $this->events[] = 'begin';
        });
        $this->db->method('commit')->willReturnCallback(function (): void {
            $this->events[] = 'commit';
        });
        $this->db->method('rollBack')->willReturnCallback(function (): void {
            $this->events[] = 'rollback';
        });

        $this->episodeMapper->method('insert')->willReturnCallback(function (Episode $e): Episode {
            $e->setId(1000 + count($this->inserted));
            $this->inserted[] = $e;
            return $e;
        });
        $this->episodeMapper->method('update')->willReturnCallback(function (Episode $e): Episode {
            $this->updated[] = $e;
            return $e;
        });

        $this->service = new SeriesRefreshService($this->seriesMapper, $this->episodeMapper, $this->tmdb, $this->db);
    }

    private function series(?string $status = 'Returning Series'): Series {
        $s = new Series();
        $s->setId(5);
        $s->setTmdbId(77);
        $s->setTitle('Show');
        $s->setStatus($status);
        $s->setNumberOfSeasons(1);
        return $s;
    }

    private function stored(int $season, int $number, ?string $airDate, bool $watched = false, ?int $tmdbId = null, string $name = 'Name'): Episode {
        $e = new Episode();
        $e->setId($season * 100 + $number);
        $e->setSeriesId(5);
        $e->setTmdbId($tmdbId ?? ($season * 1000 + $number));
        $e->setSeasonNumber($season);
        $e->setEpisodeNumber($number);
        $e->setName($name);
        $e->setAirDate($airDate);
        $e->setWatched($watched);
        // Entities loaded from the database have nothing marked as changed.
        $this->resetUpdated($e);
        return $e;
    }

    private function resetUpdated(Episode $e): void {
        $ref = new \ReflectionProperty(\OCP\AppFramework\Db\Entity::class, '_updatedFields');
        $ref->setValue($e, []);
    }

    private function tmdbEp(int $season, int $number, ?string $airDate, string $name = 'Name'): array {
        return ['id' => $season * 1000 + $number, 'season_number' => $season, 'episode_number' => $number, 'name' => $name, 'air_date' => $airDate];
    }

    private function details(array $seasons, string $status = 'Returning Series'): array {
        return [
            'status' => $status,
            'number_of_seasons' => count(array_filter(array_keys($seasons), fn ($n) => $n > 0)),
            'number_of_episodes' => array_sum($seasons),
            'seasons' => array_map(fn ($n, $c) => ['season_number' => $n, 'episode_count' => $c], array_keys($seasons), $seasons),
        ];
    }

    public function testAddsANewSeasonAndCountsIt(): void {
        $old = '2020-01-01';
        $this->episodeMapper->method('findBySeries')->willReturn([$this->stored(1, 1, $old), $this->stored(1, 2, $old)]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 2, 2 => 2]));
        $this->tmdb->method('getSeasonDetails')->willReturnCallback(fn (int $id, int $season) => ['episodes' => [
            $this->tmdbEp($season, 1, '2030-01-01'),
            $this->tmdbEp($season, 2, '2030-01-08'),
        ]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(['newEpisodes' => 2, 'updatedEpisodes' => 0, 'newSeasons' => 1], $result);
        $this->assertCount(2, $this->inserted);
        $this->assertSame(5, $this->inserted[0]->getSeriesId());
        $this->assertSame(2, $this->inserted[0]->getSeasonNumber());
        $this->assertFalse($this->inserted[0]->getWatched());
    }

    public function testAddsNewEpisodesToAKnownSeason(): void {
        $this->episodeMapper->method('findBySeries')->willReturn([$this->stored(1, 1, '2020-01-01')]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 2]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [
            $this->tmdbEp(1, 1, '2020-01-01'),
            $this->tmdbEp(1, 2, '2030-02-01'),
        ]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(['newEpisodes' => 1, 'updatedEpisodes' => 0, 'newSeasons' => 0], $result);
    }

    public function testUpdatesAirDateAndNameButNeverTheWatchedFlag(): void {
        $watched = $this->stored(1, 1, '2030-01-01', true, null, 'Old name');
        $this->episodeMapper->method('findBySeries')->willReturn([$watched]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [$this->tmdbEp(1, 1, '2030-03-03', 'New name')]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(1, $result['updatedEpisodes']);
        $this->assertSame('2030-03-03', $watched->getAirDate());
        $this->assertSame('New name', $watched->getName());
        $this->assertTrue($watched->getWatched());
        $this->assertArrayNotHasKey('watched', $watched->getUpdatedFields());
        // updated_at doubles as "last watched", so a metadata change must leave it alone.
        $this->assertArrayNotHasKey('updatedAt', $watched->getUpdatedFields());
    }

    public function testUnchangedEpisodesAreNotWritten(): void {
        $this->episodeMapper->method('findBySeries')->willReturn([$this->stored(1, 1, '2030-01-01')]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [$this->tmdbEp(1, 1, '2030-01-01')]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(['newEpisodes' => 0, 'updatedEpisodes' => 0, 'newSeasons' => 0], $result);
        $this->assertSame([], $this->updated);
    }

    public function testKeepsEpisodesTmdbNoLongerLists(): void {
        $gone = $this->stored(1, 3, '2020-01-01', true);
        $this->episodeMapper->method('findBySeries')->willReturn([$this->stored(1, 1, '2030-01-01'), $gone]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [$this->tmdbEp(1, 1, '2030-01-01')]]);
        $this->episodeMapper->expects($this->never())->method('deleteBySeries');

        $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertNotContains($gone, $this->updated);
        $this->assertTrue($gone->getWatched());
    }

    public function testMatchesByPositionWhenTheTmdbIdIsNew(): void {
        $existing = $this->stored(1, 1, '2030-01-01', true);
        $this->episodeMapper->method('findBySeries')->willReturn([$existing]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [
            ['id' => 999999, 'season_number' => 1, 'episode_number' => 1, 'name' => 'Name', 'air_date' => '2030-01-01'],
        ]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(0, $result['newEpisodes']);
        $this->assertSame([], $this->inserted);
        $this->assertTrue($existing->getWatched());
    }

    public function testOnlyFetchesSeasonsThatNeedIt(): void {
        $old = '2020-01-01';
        $this->episodeMapper->method('findBySeries')->willReturn([
            $this->stored(1, 1, $old), $this->stored(1, 2, $old),
            $this->stored(2, 1, $old), $this->stored(2, 2, $old),
            $this->stored(3, 1, $old),
        ]);
        // Season 1 and 2 are complete and old; season 3 is the latest and has a new episode.
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 2, 2 => 2, 3 => 2]));
        $this->tmdb->expects($this->once())->method('getSeasonDetails')->with(77, 3, 'alice', 'en-US')
            ->willReturn(['episodes' => [$this->tmdbEp(3, 1, $old), $this->tmdbEp(3, 2, '2030-05-05')]]);

        $result = $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(1, $result['newEpisodes']);
    }

    public function testFullModeReadsEverySeasonAndFixesOldEpisodes(): void {
        $old = '2020-01-01';
        $stale = $this->stored(1, 1, $old, true, null, 'Old name');
        $this->episodeMapper->method('findBySeries')->willReturn([$stale, $this->stored(2, 1, $old), $this->stored(3, 1, $old)]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1, 2 => 1, 3 => 1]));
        $fetched = [];
        $this->tmdb->method('getSeasonDetails')->willReturnCallback(function (int $id, int $season) use (&$fetched) {
            $fetched[] = $season;
            return ['episodes' => [$this->tmdbEp($season, 1, '2020-01-01', $season === 1 ? 'Better name' : 'Name')]];
        });

        $result = $this->service->refresh($this->series(), 'alice', 'en-US', true);

        $this->assertSame([1, 2, 3], $fetched);
        $this->assertSame(1, $result['updatedEpisodes']);
        $this->assertSame('Better name', $stale->getName());
        $this->assertTrue($stale->getWatched());
    }

    public function testBackgroundModeSkipsOldCompleteSeasons(): void {
        $old = '2020-01-01';
        $this->episodeMapper->method('findBySeries')->willReturn([$this->stored(1, 1, $old), $this->stored(2, 1, $old), $this->stored(3, 1, $old)]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1, 2 => 1, 3 => 1]));
        $this->tmdb->expects($this->once())->method('getSeasonDetails')->with(77, 3, 'alice', 'en-US')
            ->willReturn(['episodes' => [$this->tmdbEp(3, 1, $old)]]);

        $this->service->refresh($this->series(), 'alice', 'en-US');
    }

    public function testRefetchesSeasonsWithUndatedOrRecentEpisodes(): void {
        $recent = (new \DateTime('-3 days'))->format('Y-m-d');
        $this->episodeMapper->method('findBySeries')->willReturn([
            $this->stored(1, 1, null), $this->stored(2, 1, $recent), $this->stored(3, 1, '2020-01-01'),
        ]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1, 2 => 1, 3 => 1, 4 => 1]));
        $fetched = [];
        $this->tmdb->method('getSeasonDetails')->willReturnCallback(function (int $id, int $season) use (&$fetched) {
            $fetched[] = $season;
            return ['episodes' => []];
        });

        $this->service->refresh($this->series(), 'alice', 'en-US');

        // 1 (undated), 2 (recent), 4 (new and latest); 3 is old and complete.
        $this->assertSame([1, 2, 4], $fetched);
    }

    public function testUpdatesSeriesFieldsAndTheRefreshTime(): void {
        $series = $this->series('Returning Series');
        $this->episodeMapper->method('findBySeries')->willReturn([]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 0], 'Ended'));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => []]);
        $this->seriesMapper->expects($this->once())->method('update')->with($series);

        $this->service->refresh($series, 'alice', 'en-US');

        $this->assertSame('Ended', $series->getStatus());
        $this->assertSame('Show', $series->getTitle());
        $this->assertNotNull($series->getTmdbRefreshedAt());
    }

    public function testCallsTmdbBeforeOpeningTheTransaction(): void {
        $this->episodeMapper->method('findBySeries')->willReturn([]);
        $this->tmdb->method('getSeriesDetails')->willReturnCallback(function (): array {
            $this->events[] = 'tmdb';
            return $this->details([1 => 1]);
        });
        $this->tmdb->method('getSeasonDetails')->willReturnCallback(function (): array {
            $this->events[] = 'tmdb';
            return ['episodes' => [$this->tmdbEp(1, 1, '2030-01-01')]];
        });

        $this->service->refresh($this->series(), 'alice', 'en-US');

        $this->assertSame(['tmdb', 'tmdb', 'begin', 'commit'], $this->events);
    }

    public function testATmdbFailureLeavesTheShowUntouched(): void {
        $this->episodeMapper->method('findBySeries')->willReturn([]);
        $this->tmdb->method('getSeriesDetails')->willThrowException(new \RuntimeException('down'));
        $this->seriesMapper->expects($this->never())->method('update');

        try {
            $this->service->refresh($this->series(), 'alice', 'en-US');
            $this->fail('Expected the exception');
        } catch (\RuntimeException) {
            $this->assertSame([], $this->events);
            $this->assertSame([], $this->inserted);
        }
    }

    public function testADatabaseFailureRollsBack(): void {
        $this->episodeMapper->method('findBySeries')->willReturn([]);
        $this->tmdb->method('getSeriesDetails')->willReturn($this->details([1 => 1]));
        $this->tmdb->method('getSeasonDetails')->willReturn(['episodes' => [$this->tmdbEp(1, 1, '2030-01-01')]]);
        $failing = $this->createMock(EpisodeMapper::class);
        $failing->method('findBySeries')->willReturn([]);
        $failing->method('insert')->willThrowException(new \RuntimeException('db'));
        $service = new SeriesRefreshService($this->seriesMapper, $failing, $this->tmdb, $this->db);

        $this->expectException(\RuntimeException::class);
        try {
            $service->refresh($this->series(), 'alice', 'en-US');
        } finally {
            $this->assertSame(['begin', 'rollback'], $this->events);
        }
    }

    public function testRejectsAShowWithoutTmdbId(): void {
        $series = $this->series();
        $series->setTmdbId(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->refresh($series, 'alice', 'en-US');
    }

    public function testMarkRefreshedOnlyStampsTheTime(): void {
        $series = $this->series();
        $this->seriesMapper->expects($this->once())->method('update')->with($series);

        $this->service->markRefreshed($series);

        $this->assertNotNull($series->getTmdbRefreshedAt());
    }

    public function testRecognisesATooManyRequestsError(): void {
        $this->assertTrue(SeriesRefreshService::isRateLimited(new \RuntimeException('slow down', 429)));
        $this->assertFalse(SeriesRefreshService::isRateLimited(new \RuntimeException('gone', 404)));
    }
}
