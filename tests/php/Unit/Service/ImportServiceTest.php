<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Platform;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\ImportService;
use OCA\MovieDB\Service\ImportValidator;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

class ImportServiceTest extends TestCase {
    private MovieMapper $movieMapper;
    private MovieWatchMapper $watchMapper;
    private SeriesMapper $seriesMapper;
    private EpisodeMapper $episodeMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;
    private IDBConnection $db;
    private ImportService $service;

    /** @var Entity[] */
    private array $inserted = [];
    private int $nextId = 100;

    protected function setUp(): void {
        parent::setUp();
        $this->movieMapper = $this->createMock(MovieMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->watchlistMapper = $this->createMock(WatchlistMapper::class);
        $this->platformMapper = $this->createMock(PlatformMapper::class);
        $this->db = $this->createMock(IDBConnection::class);

        $assignId = function (Entity $e): Entity {
            $e->setId($this->nextId++);
            $this->inserted[] = $e;
            return $e;
        };
        foreach ([$this->movieMapper, $this->watchMapper, $this->seriesMapper, $this->episodeMapper, $this->watchlistMapper, $this->platformMapper] as $m) {
            $m->method('insert')->willReturnCallback($assignId);
        }

        $this->service = new ImportService(
            $this->movieMapper,
            $this->watchMapper,
            $this->seriesMapper,
            $this->episodeMapper,
            $this->watchlistMapper,
            $this->platformMapper,
            $this->db
        );
    }

    private function validated(array $file): array {
        return (new ImportValidator())->validate(array_merge(['app' => 'moviedb', 'formatVersion' => 1], $file));
    }

    private function insertedOf(string $class): array {
        return array_values(array_filter($this->inserted, fn ($e) => $e instanceof $class));
    }

    public function testImportsMoviesWithWatchesForCurrentUserAndLibrary(): void {
        $data = $this->validated(['movies' => [[
            'tmdbId' => 603, 'title' => 'The Matrix', 'releaseDate' => '1999-03-31',
            'watches' => [['watchedAt' => '2024-01-02', 'rating' => 9], ['watchedAt' => '2025-01-02', 'rating' => 8]],
        ]]]);

        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(1, $result['imported']['movies']);
        $this->assertSame(2, $result['imported']['watches']);
        $movie = $this->insertedOf(Movie::class)[0];
        $this->assertSame('alice', $movie->getUserId());
        $this->assertSame(5, $movie->getLibraryId());
        foreach ($this->insertedOf(MovieWatch::class) as $watch) {
            $this->assertSame('alice', $watch->getUserId());
            $this->assertSame(5, $watch->getLibraryId());
            $this->assertSame($movie->getId(), $watch->getMovieId());
        }
    }

    public function testSkipsMoviesAlreadyInLibraryByTmdbId(): void {
        $existing = new Movie();
        $existing->setTmdbId(603);
        $existing->setTitle('The Matrix');
        $this->movieMapper->method('findAllByLibrary')->willReturn([$existing]);

        $data = $this->validated(['movies' => [
            ['tmdbId' => 603, 'title' => 'The Matrix'],
            ['tmdbId' => 604, 'title' => 'Reloaded'],
        ]]);
        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(1, $result['imported']['movies']);
        $this->assertSame(1, $result['skippedDuplicates']['movies']);
    }

    public function testSkipsDuplicatesWithinTheFile(): void {
        $data = $this->validated(['movies' => [
            ['title' => 'No Id', 'releaseDate' => '2001-01-01'],
            ['title' => 'no id ', 'releaseDate' => '2001-05-05'],
        ]]);
        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(1, $result['imported']['movies']);
        $this->assertSame(1, $result['skippedDuplicates']['movies']);
    }

    public function testMatchesPlatformsByNameAndCreatesMissingOnes(): void {
        $netflix = new Platform();
        $netflix->setId(3);
        $netflix->setName('Netflix');
        $this->platformMapper->method('findAllForUser')->willReturn([$netflix]);

        $data = $this->validated(['movies' => [[
            'title' => 'A',
            'watches' => [['platform' => 'netflix'], ['platform' => 'My Cinema']],
        ]]]);
        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(1, $result['imported']['platforms']);
        $created = $this->insertedOf(Platform::class)[0];
        $this->assertSame('My Cinema', $created->getName());
        $this->assertSame('alice', $created->getUserId());
        $this->assertFalse($created->getIsDefault());

        $watches = $this->insertedOf(MovieWatch::class);
        $this->assertSame(3, $watches[0]->getPlatformId());
        $this->assertSame($created->getId(), $watches[1]->getPlatformId());
    }

    public function testImportsSeriesWithEpisodesWithoutDuplicateSeasonEpisodePairs(): void {
        $data = $this->validated(['series' => [[
            'tmdbId' => 1396, 'title' => 'Breaking Bad',
            'watch' => ['rating' => 10, 'watchedAt' => '2024-05-01'],
            'episodes' => [
                ['season' => 1, 'episode' => 1, 'name' => 'Pilot', 'watched' => true],
                ['season' => 1, 'episode' => 1, 'name' => 'Pilot again'],
                ['season' => 1, 'episode' => 2, 'name' => 'Cat'],
            ],
        ]]]);
        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(1, $result['imported']['series']);
        $this->assertSame(2, $result['imported']['episodes']);
        $this->assertSame(1, $result['imported']['watches']);
        $watch = $this->insertedOf(MovieWatch::class)[0];
        $this->assertNotNull($watch->getSeriesId());
        $this->assertNull($watch->getEpisodeId());
    }

    public function testWatchlistDuplicateKeyIncludesMediaType(): void {
        $data = $this->validated(['watchlist' => [
            ['tmdbId' => 10, 'title' => 'Movie', 'mediaType' => 'movie'],
            ['tmdbId' => 10, 'title' => 'Show', 'mediaType' => 'series'],
            ['tmdbId' => 10, 'title' => 'Movie again', 'mediaType' => 'movie'],
        ]]);
        $result = $this->service->import(5, 'alice', $data);

        $this->assertSame(2, $result['imported']['watchlist']);
        $this->assertSame(1, $result['skippedDuplicates']['watchlist']);
    }

    public function testRunsInOneTransactionAndCommits(): void {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->service->import(5, 'alice', $this->validated(['movies' => [['title' => 'A']]]));
    }

    public function testRollsBackAndRethrowsOnFailure(): void {
        $failing = $this->createMock(MovieMapper::class);
        $failing->method('findAllByLibrary')->willReturn([]);
        $failing->method('insert')->willThrowException(new \RuntimeException('db down'));
        $service = new ImportService(
            $failing, $this->watchMapper, $this->seriesMapper, $this->episodeMapper,
            $this->watchlistMapper, $this->platformMapper, $this->db
        );

        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');
        $this->expectException(\RuntimeException::class);

        $service->import(5, 'alice', $this->validated(['movies' => [['title' => 'A']]]));
    }
}
