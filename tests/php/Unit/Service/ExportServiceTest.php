<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Db\MovieWatchMapper;
use OCA\MovieDB\Db\Platform;
use OCA\MovieDB\Db\PlatformMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\ExportService;
use OCA\MovieDB\Service\ImportService;
use OCA\MovieDB\Service\ImportValidator;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

class ExportServiceTest extends TestCase {
    private MovieMapper $movieMapper;
    private MovieWatchMapper $watchMapper;
    private SeriesMapper $seriesMapper;
    private EpisodeMapper $episodeMapper;
    private WatchlistMapper $watchlistMapper;
    private PlatformMapper $platformMapper;
    private LibraryMapper $libraryMapper;
    private ExportService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->movieMapper = $this->createMock(MovieMapper::class);
        $this->watchMapper = $this->createMock(MovieWatchMapper::class);
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->watchlistMapper = $this->createMock(WatchlistMapper::class);
        $this->platformMapper = $this->createMock(PlatformMapper::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);

        $library = new Library();
        $library->setName('Family');
        $this->libraryMapper->method('find')->willReturn($library);

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->willReturn('1.6.0');

        $this->service = new ExportService(
            $this->movieMapper,
            $this->watchMapper,
            $this->seriesMapper,
            $this->episodeMapper,
            $this->watchlistMapper,
            $this->platformMapper,
            $this->libraryMapper,
            $appManager
        );
    }

    private function movie(int $id, string $title, array $extra = []): Movie {
        $m = new Movie();
        $m->setId($id);
        $m->setTitle($title);
        foreach ($extra as $setter => $value) {
            $m->$setter($value);
        }
        return $m;
    }

    private function watch(?int $movieId, ?int $seriesId, array $extra = []): MovieWatch {
        $w = new MovieWatch();
        $w->setMovieId($movieId);
        $w->setSeriesId($seriesId);
        foreach ($extra as $setter => $value) {
            $w->$setter($value);
        }
        return $w;
    }

    private function platform(int $id, string $name, bool $default, ?string $icon = null): Platform {
        $p = new Platform();
        $p->setId($id);
        $p->setName($name);
        $p->setIsDefault($default);
        $p->setIcon($icon);
        return $p;
    }

    /** Make PlatformMapper::find() resolve the given platforms and throw for any other id. */
    private function knowPlatforms(Platform ...$platforms): void {
        $byId = [];
        foreach ($platforms as $p) {
            $byId[$p->getId()] = $p;
        }
        $this->platformMapper->method('find')->willReturnCallback(
            fn (int $id) => $byId[$id] ?? throw new DoesNotExistException('no platform')
        );
    }

    public function testEnvelopeCarriesAppVersionAndLibraryName(): void {
        $data = $this->service->export(1);

        $this->assertSame('moviedb', $data['app']);
        $this->assertSame(1, $data['formatVersion']);
        $this->assertSame('1.6.0', $data['appVersion']);
        $this->assertSame('Family', $data['library']['name']);
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $data['exportedAt']));
        $this->assertSame([], $data['movies']);
        $this->assertSame([], $data['series']);
        $this->assertSame([], $data['watchlist']);
        $this->assertSame([], $data['platforms']);
    }

    public function testWatchesAreGroupedUnderTheirMovieInOrder(): void {
        $this->movieMapper->method('findAllByLibrary')->willReturn([
            $this->movie(1, 'The Matrix'),
            $this->movie(2, 'Alien'),
            $this->movie(3, 'Never Watched'),
        ]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setRating' => 9, 'setWatchedAt' => '2024-01-02']),
            $this->watch(2, null, ['setRating' => 7, 'setWatchedAt' => '2023-05-05']),
            $this->watch(1, null, ['setRating' => 8, 'setWatchedAt' => '2025-01-02']),
        ]);

        $movies = $this->service->export(1)['movies'];

        $this->assertSame([9, 8], array_column($movies[0]['watches'], 'rating'));
        $this->assertSame(['2024-01-02', '2025-01-02'], array_column($movies[0]['watches'], 'watchedAt'));
        $this->assertSame([7], array_column($movies[1]['watches'], 'rating'));
        $this->assertSame([], $movies[2]['watches']);
    }

    public function testKeepsOnlyFirstSeriesLevelWatchWhenDuplicatesExist(): void {
        $series = new Series();
        $series->setId(10);
        $series->setTitle('Dark');
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$series]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(null, 10, ['setRating' => 9]),
            $this->watch(null, 10, ['setRating' => 2]),
        ]);

        $exported = $this->service->export(1)['series'][0];

        $this->assertSame(9, $exported['watch']['rating']);
    }

    public function testSeriesWithoutWatchHasNullWatch(): void {
        $series = new Series();
        $series->setId(10);
        $series->setTitle('Dark');
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$series]);

        $this->assertNull($this->service->export(1)['series'][0]['watch']);
    }

    public function testOnlyCustomPlatformsAreListedButDefaultNamesStayOnWatches(): void {
        $this->knowPlatforms(
            $this->platform(1, 'Netflix', true),
            $this->platform(2, 'My Cinema', false, 'mdi-movie')
        );
        $this->movieMapper->method('findAllByLibrary')->willReturn([$this->movie(1, 'A'), $this->movie(2, 'B')]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setPlatformId' => 1]),
            $this->watch(2, null, ['setPlatformId' => 2]),
            $this->watch(2, null, ['setPlatformId' => 2]),
        ]);

        $data = $this->service->export(1);

        $this->assertSame([['name' => 'My Cinema', 'icon' => 'mdi-movie']], $data['platforms']);
        $this->assertSame('Netflix', $data['movies'][0]['watches'][0]['platform']);
        $this->assertSame('My Cinema', $data['movies'][1]['watches'][0]['platform']);
    }

    public function testPlatformIsLookedUpOncePerId(): void {
        $this->platformMapper->expects($this->once())->method('find')
            ->willReturn($this->platform(1, 'Netflix', true));
        $this->movieMapper->method('findAllByLibrary')->willReturn([$this->movie(1, 'A')]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setPlatformId' => 1]),
            $this->watch(1, null, ['setPlatformId' => 1]),
        ]);

        $this->service->export(1);
    }

    public function testWatchOnDeletedPlatformIsExportedWithoutPlatform(): void {
        $this->knowPlatforms();
        $this->movieMapper->method('findAllByLibrary')->willReturn([$this->movie(1, 'A')]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setPlatformId' => 99, 'setRating' => 6]),
        ]);

        $data = $this->service->export(1);

        $this->assertNull($data['movies'][0]['watches'][0]['platform']);
        $this->assertSame(6, $data['movies'][0]['watches'][0]['rating']);
        $this->assertSame([], $data['platforms']);
    }

    public function testDatesAreTruncatedToPlainDays(): void {
        $this->movieMapper->method('findAllByLibrary')->willReturn([
            $this->movie(1, 'A', ['setReleaseDate' => '1999-03-31 00:00:00']),
            $this->movie(2, 'B', ['setReleaseDate' => '']),
            $this->movie(3, 'C'),
        ]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setWatchedAt' => '2024-01-02 20:15:00']),
        ]);
        $series = new Series();
        $series->setId(10);
        $series->setTitle('Dark');
        $series->setFirstAirDate('2017-12-01 00:00:00');
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$series]);
        $episode = new Episode();
        $episode->setSeriesId(10);
        $episode->setAirDate('2017-12-01 12:00:00');
        $this->episodeMapper->method('findAllByLibrary')->willReturn([$episode]);
        $item = new WatchlistItem();
        $item->setTitle('Later');
        $item->setReleaseDate('2030-01-01 00:00:00');
        $this->watchlistMapper->method('findAllByLibrary')->willReturn([$item]);

        $data = $this->service->export(1);

        $this->assertSame('1999-03-31', $data['movies'][0]['releaseDate']);
        $this->assertNull($data['movies'][1]['releaseDate']);
        $this->assertNull($data['movies'][2]['releaseDate']);
        $this->assertSame('2024-01-02', $data['movies'][0]['watches'][0]['watchedAt']);
        $this->assertSame('2017-12-01', $data['series'][0]['firstAirDate']);
        $this->assertSame('2017-12-01', $data['series'][0]['episodes'][0]['airDate']);
        $this->assertSame('2030-01-01', $data['watchlist'][0]['releaseDate']);
    }

    /**
     * The exact key sets are the file format: a rename or removal here breaks
     * every previously exported file and needs a formatVersion bump.
     */
    public function testFormatShapeIsStable(): void {
        $this->movieMapper->method('findAllByLibrary')->willReturn([$this->movie(1, 'A')]);
        $series = new Series();
        $series->setId(10);
        $series->setTitle('Dark');
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$series]);
        $episode = new Episode();
        $episode->setSeriesId(10);
        $this->episodeMapper->method('findAllByLibrary')->willReturn([$episode]);
        $item = new WatchlistItem();
        $item->setTitle('Later');
        $this->watchlistMapper->method('findAllByLibrary')->willReturn([$item]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null),
            $this->watch(null, 10),
        ]);

        $data = $this->service->export(1);

        $this->assertSame(
            ['app', 'formatVersion', 'appVersion', 'exportedAt', 'library', 'platforms', 'movies', 'series', 'watchlist'],
            array_keys($data)
        );
        $this->assertSame(
            ['tmdbId', 'title', 'originalTitle', 'posterPath', 'backdropPath', 'overview', 'genreIds', 'releaseDate',
                'releaseYear', 'runtime', 'castData', 'director', 'isFavorite', 'watches'],
            array_keys($data['movies'][0])
        );
        $this->assertSame(
            ['tmdbId', 'title', 'originalTitle', 'posterPath', 'backdropPath', 'overview', 'genreIds', 'firstAirDate',
                'firstAirYear', 'numberOfSeasons', 'numberOfEpisodes', 'status', 'castData', 'director', 'isFavorite',
                'watch', 'episodes'],
            array_keys($data['series'][0])
        );
        $this->assertSame(
            ['tmdbId', 'season', 'episode', 'name', 'overview', 'airDate', 'runtime', 'stillPath', 'watched'],
            array_keys($data['series'][0]['episodes'][0])
        );
        $this->assertSame(
            ['tmdbId', 'mediaType', 'title', 'posterPath', 'overview', 'genreIds', 'releaseDate', 'priority', 'notes', 'addedAt'],
            array_keys($data['watchlist'][0])
        );
        $watchKeys = ['watchedAt', 'rating', 'review', 'platform', 'language'];
        $this->assertSame($watchKeys, array_keys($data['movies'][0]['watches'][0]));
        $this->assertSame($watchKeys, array_keys($data['series'][0]['watch']));
    }

    public function testExportContainsNoDatabaseIdsOrOwners(): void {
        $this->movieMapper->method('findAllByLibrary')->willReturn([
            $this->movie(1, 'A', ['setUserId' => 'alice', 'setLibraryId' => 7]),
        ]);

        $json = json_encode($this->service->export(7));

        $this->assertStringNotContainsString('alice', $json);
        $this->assertStringNotContainsString('userId', $json);
        $this->assertStringNotContainsString('libraryId', $json);
    }

    public function testExportedFileSurvivesValidationAndImportWithoutLoss(): void {
        // Source library.
        $this->knowPlatforms(
            $this->platform(1, 'Netflix', true),
            $this->platform(2, 'My Cinema', false, 'mdi-movie')
        );
        $this->movieMapper->method('findAllByLibrary')->willReturn([
            $this->movie(1, 'The Matrix', [
                'setTmdbId' => 603, 'setReleaseDate' => '1999-03-31 00:00:00', 'setReleaseYear' => 1999,
                'setGenreIds' => [28, 878], 'setCastData' => [['name' => 'Keanu Reeves', 'character' => 'Neo', 'profilePath' => '/abc.jpg']],
                'setPosterPath' => '/poster.jpg', 'setIsFavorite' => true, 'setRuntime' => 136,
            ]),
        ]);
        $series = new Series();
        $series->setId(10);
        $series->setTmdbId(70523);
        $series->setTitle('Dark');
        $series->setFirstAirDate('2017-12-01 00:00:00');
        $series->setNumberOfSeasons(3);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$series]);
        $e1 = new Episode();
        $e1->setSeriesId(10);
        $e1->setSeasonNumber(1);
        $e1->setEpisodeNumber(1);
        $e1->setName('Secrets');
        $e1->setWatched(true);
        $e2 = new Episode();
        $e2->setSeriesId(10);
        $e2->setSeasonNumber(1);
        $e2->setEpisodeNumber(2);
        $e2->setName('Lies');
        $e2->setWatched(false);
        $this->episodeMapper->method('findAllByLibrary')->willReturn([$e1, $e2]);
        $item = new WatchlistItem();
        $item->setTmdbId(155);
        $item->setTitle('The Dark Knight');
        $item->setMediaType('movie');
        $item->setPriority(2);
        $item->setNotes('with friends');
        $item->setAddedAt('2026-01-01 10:00:00');
        $this->watchlistMapper->method('findAllByLibrary')->willReturn([$item]);
        $this->watchMapper->method('findAllByLibrary')->willReturn([
            $this->watch(1, null, ['setWatchedAt' => '2024-01-02', 'setRating' => 9, 'setReview' => 'Great', 'setPlatformId' => 2, 'setLanguageWatched' => 'en']),
            $this->watch(1, null, ['setWatchedAt' => '2025-01-02', 'setRating' => 8, 'setPlatformId' => 1]),
            $this->watch(null, 10, ['setWatchedAt' => '2026-02-02', 'setRating' => 10]),
        ]);

        // Through the file: JSON out, JSON in, validated, imported into another library.
        $file = json_decode(json_encode($this->service->export(1)), true);
        $validated = (new ImportValidator())->validate($file);
        $this->assertSame(['platforms' => 0, 'movies' => 0, 'watches' => 0, 'series' => 0, 'episodes' => 0, 'watchlist' => 0], $validated['invalid']);

        [$import, $inserted] = $this->importService();
        $result = $import->import(2, 'bob', $validated);

        $this->assertSame(
            ['platforms' => 2, 'movies' => 1, 'watches' => 3, 'series' => 1, 'episodes' => 2, 'watchlist' => 1],
            $result['imported']
        );
        $this->assertSame(['movies' => 0, 'series' => 0, 'watchlist' => 0], $result['skippedDuplicates']);

        $of = fn (string $class) => array_values(array_filter($inserted(), fn ($e) => $e instanceof $class));
        $movie = $of(Movie::class)[0];
        $this->assertSame('The Matrix', $movie->getTitle());
        $this->assertSame(603, $movie->getTmdbId());
        $this->assertSame('1999-03-31', $movie->getReleaseDate());
        $this->assertSame([28, 878], $movie->getGenreIds());
        $this->assertTrue($movie->getIsFavorite());
        $this->assertSame(136, $movie->getRuntime());
        $this->assertSame('Keanu Reeves', $movie->getCastData()[0]['name']);

        $this->assertSame(['Secrets', 'Lies'], array_map(fn ($e) => $e->getName(), $of(Episode::class)));
        $this->assertSame([true, false], array_map(fn ($e) => $e->getWatched(), $of(Episode::class)));
        $this->assertSame('with friends', $of(WatchlistItem::class)[0]->getNotes());
        $this->assertSame([9, 8, 10], array_map(fn ($w) => $w->getRating(), $of(MovieWatch::class)));
    }

    /**
     * A real ImportService on mocked mappers that hand out ids.
     *
     * @return array{0: ImportService, 1: callable(): Entity[]}
     */
    private function importService(): array {
        $inserted = [];
        $nextId = 500;
        $assignId = function (Entity $e) use (&$inserted, &$nextId): Entity {
            $e->setId($nextId++);
            $inserted[] = $e;
            return $e;
        };
        $mappers = [];
        foreach ([MovieMapper::class, MovieWatchMapper::class, SeriesMapper::class, EpisodeMapper::class, WatchlistMapper::class, PlatformMapper::class] as $class) {
            $mapper = $this->createMock($class);
            $mapper->method('insert')->willReturnCallback($assignId);
            $mappers[] = $mapper;
        }

        return [
            new ImportService(...[...$mappers, $this->createMock(IDBConnection::class)]),
            function () use (&$inserted): array {
                return $inserted;
            },
        ];
    }
}
