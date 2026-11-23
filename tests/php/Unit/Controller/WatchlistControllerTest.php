<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\WatchlistController;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Service\MovieWatchService;
use OCA\MovieDB\Service\ImportLockedException;
use OCA\MovieDB\Service\SeriesService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Service\WatchlistService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Tests WatchlistController::moveToWatched branching on media type.
 *
 * A movie item logs a watch (or a rewatch on an already-tracked movie) and
 * returns `movie`. A series item imports the whole show via
 * SeriesService::createFromTmdb, deletes the watchlist row, returns `series`,
 * and must NOT create a movie or mark any episodes watched.
 */
class WatchlistControllerTest extends TestCase {
    private IRequest $request;
    private WatchlistService $service;
    private MovieService $movieService;
    private MovieWatchService $watchService;
    private SeriesService $seriesService;
    private TmdbService $tmdbService;
    private LibraryService $libraryService;
    private IDBConnection $db;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private ?WatchlistController $controller = null;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createStub(IRequest::class);
        $this->service = $this->createStub(WatchlistService::class);
        $this->movieService = $this->createStub(MovieService::class);
        $this->watchService = $this->createStub(MovieWatchService::class);
        $this->seriesService = $this->createStub(SeriesService::class);
        $this->tmdbService = $this->createStub(TmdbService::class);
        $this->libraryService = $this->createStub(LibraryService::class);
        $this->db = $this->createStub(IDBConnection::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->userSession = $this->createStub(IUserSession::class);

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIBRARY_ID);
        $this->libraryService->method('canEdit')->willReturn(true);

        $this->db->method('beginTransaction');
        $this->db->method('commit');
    }

    private function controller(): WatchlistController {
        return $this->controller ??= new WatchlistController(
            $this->request,
            $this->service,
            $this->movieService,
            $this->watchService,
            $this->seriesService,
            $this->tmdbService,
            $this->libraryService,
            $this->db,
            $this->userSession,
            $this->logger
        );
    }

    /** Lets a SeriesService double run the callback of withImportLock, as the real lock does. */
    private function runImportLockCallbacks(): void {
        $this->seriesService->method('withImportLock')
            ->willReturnCallback(fn (int $libraryId, int $tmdbId, callable $fn) => $fn());
    }

    private function makeItem(int $id, string $mediaType, ?int $tmdbId = null): WatchlistItem {
        $item = new WatchlistItem();
        $item->setId($id);
        $item->setUserId('testuser');
        $item->setTitle('Test Title');
        $item->setMediaType($mediaType);
        $item->setTmdbId($tmdbId);
        return $item;
    }

    public function testMoveSeriesImportsShowAndReturnsSeries(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->runImportLockCallbacks();
        $this->watchService = $this->createMock(MovieWatchService::class);
        $this->movieService = $this->createMock(MovieService::class);
        $this->service = $this->createMock(WatchlistService::class);
        $item = $this->makeItem(5, 'series', 1399);

        $this->service->method('find')->with(5, self::LIBRARY_ID)->willReturn($item);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'language' ? 'en-US' : $d
        );
        $this->tmdbService->method('getSeriesDetails')->willReturn([
            'name' => 'Game of Thrones',
            'number_of_seasons' => 8,
            'seasons' => [['season_number' => 1]],
        ]);

        $series = new Series();
        $series->setId(42);
        $series->setTitle('Game of Thrones');

        // Series import path must be taken; movie creation must NOT happen.
        $this->seriesService->expects($this->once())
            ->method('createFromTmdb')
            ->with('testuser', self::LIBRARY_ID, $this->callback(fn($d) => ($d['tmdbId'] ?? null) === 1399), 'en-US')
            ->willReturn($series);
        $this->movieService->expects($this->never())->method('create');
        $this->watchService->expects($this->never())->method('create');

        // Watchlist row removed in the same flow.
        $this->service->expects($this->once())->method('delete')->with(5, self::LIBRARY_ID);

        $response = $this->controller()->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayNotHasKey('movie', $data);
        $this->assertSame(42, $data['series']->getId());
    }

    public function testMoveSeriesThatIsAlreadyTrackedReturnsTheExistingShow(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->runImportLockCallbacks();
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->service = $this->createMock(WatchlistService::class);
        $item = $this->makeItem(5, 'series', 1399);

        $existing = new Series();
        $existing->setId(42);
        $existing->setTitle('Game of Thrones');

        $this->service->method('find')->with(5, self::LIBRARY_ID)->willReturn($item);
        $this->seriesService->method('findByTmdbId')->with(self::LIBRARY_ID, 1399)->willReturn($existing);

        // No second import, no TMDB round trips — the existing show is reused (#147).
        $this->seriesService->expects($this->never())->method('createFromTmdb');
        $this->tmdbService->expects($this->never())->method('getSeriesDetails');
        $this->service->expects($this->once())->method('delete')->with(5, self::LIBRARY_ID);

        $response = $this->controller()->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(42, $data['series']->getId());
        $this->assertTrue($data['alreadyTracked']);
    }

    public function testMoveSeriesChecksAndImportsUnderTheShowsLock(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->service->method('find')->willReturn($this->makeItem(5, 'series', 1399));
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);

        // Nothing may look up or import the show outside the lock (#147 review).
        $insideLock = false;
        $this->seriesService->expects($this->once())
            ->method('withImportLock')
            ->with(self::LIBRARY_ID, 1399, $this->anything())
            ->willReturnCallback(function (int $libraryId, int $tmdbId, callable $fn) use (&$insideLock) {
                $insideLock = true;
                try {
                    return $fn();
                } finally {
                    $insideLock = false;
                }
            });
        $this->seriesService->method('findByTmdbId')->willReturnCallback(function () use (&$insideLock) {
            $this->assertTrue($insideLock, 'duplicate check ran outside the import lock');
            return null;
        });
        $this->seriesService->expects($this->once())->method('createFromTmdb')
            ->willReturnCallback(function () use (&$insideLock) {
                $this->assertTrue($insideLock, 'import ran outside the import lock');
                return new Series();
            });

        $this->assertEquals(Http::STATUS_OK, $this->controller()->moveToWatched(5)->getStatus());
    }

    public function testSecondOfTwoConcurrentMovesGetsTheShowTheFirstImported(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->runImportLockCallbacks();
        $this->service = $this->createMock(WatchlistService::class);
        $existing = new Series();
        $existing->setId(42);

        // The second request found the row before it waited; the first one has removed it since.
        $this->service->method('find')->willReturn($this->makeItem(5, 'series', 1399));
        $this->service->expects($this->once())->method('delete')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
        $this->seriesService->method('findByTmdbId')->willReturn($existing);
        $this->seriesService->expects($this->never())->method('createFromTmdb');

        $response = $this->controller()->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(42, $response->getData()['series']->getId());
        $this->assertTrue($response->getData()['alreadyTracked']);
    }

    public function testMoveSeriesStillReturnsTheShowWhenTheWatchlistRowIsAlreadyGone(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->runImportLockCallbacks();
        $this->service = $this->createMock(WatchlistService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->service->method('find')->willReturn($this->makeItem(5, 'series', 1399));
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);
        $series = new Series();
        $series->setId(42);
        $this->seriesService->method('findByTmdbId')->willReturn(null);
        $this->seriesService->expects($this->once())->method('createFromTmdb')->willReturn($series);
        $this->service->expects($this->once())->method('delete')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
        // createFromTmdb has its own transaction; an outer one would hide the lease renewals.
        $this->db->expects($this->never())->method('beginTransaction');

        $response = $this->controller()->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(42, $response->getData()['series']->getId());
    }

    public function testMoveSeriesAnswersConflictWhenTheOtherImportTakesTooLong(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->service->method('find')->willReturn($this->makeItem(5, 'series', 1399));
        $this->seriesService->method('withImportLock')->willThrowException(new ImportLockedException('busy'));
        $this->seriesService->expects($this->never())->method('createFromTmdb');

        $this->assertEquals(Http::STATUS_CONFLICT, $this->controller()->moveToWatched(5)->getStatus());
    }

    public function testCreateFlagsAShowThatIsAlreadyTracked(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->request->method('getParams')->willReturn([
            'title' => 'Game of Thrones',
            'tmdbId' => 1399,
            'mediaType' => 'series',
        ]);
        $this->service->method('existsByTmdbId')->willReturn(false);
        $this->seriesService->method('findByTmdbId')->willReturn(new Series());
        $this->service->expects($this->once())->method('create')->willReturn(new WatchlistItem());

        $response = $this->controller()->create();

        $this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['alreadyTracked']);
        $this->assertFalse($data['alreadyWatched']);
    }

    public function testCreateDoesNotFlagANewShow(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Severance',
            'tmdbId' => 95396,
            'mediaType' => 'series',
        ]);
        $this->service->method('existsByTmdbId')->willReturn(false);
        $this->seriesService->method('findByTmdbId')->willReturn(null);
        $this->service->method('create')->willReturn(new WatchlistItem());

        $response = $this->controller()->create();

        $this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
        $this->assertFalse($response->getData()['alreadyTracked']);
    }

    public function testMoveMovieCreatesMovieAndReturnsMovie(): void {
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->movieService = $this->createMock(MovieService::class);
        $this->service = $this->createMock(WatchlistService::class);
        $item = $this->makeItem(7, 'movie', 27205);

        $this->service->method('find')->with(7, self::LIBRARY_ID)->willReturn($item);
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);
        $this->tmdbService->method('getMovieDetails')->willReturn(['runtime' => 148]);
        $this->movieService->method('findByTmdbId')->willReturn(null);

        $movie = new \OCA\MovieDB\Db\Movie();
        $movie->setId(99);
        $movie->setTitle('Inception');

        $this->movieService->expects($this->once())
            ->method('create')
            ->willReturn($movie);
        $this->seriesService->expects($this->never())->method('createFromTmdb');
        $this->service->expects($this->once())->method('delete')->with(7, self::LIBRARY_ID);

        $response = $this->controller()->moveToWatched(7);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('movie', $data);
        $this->assertArrayNotHasKey('series', $data);
        $this->assertSame(99, $data['movie']->getId());
    }

    public function testMoveMovieWithAnEmptyDateLogsAViewingWithoutDate(): void {
        $this->movieService = $this->createMock(MovieService::class);
        $this->service->method('find')->willReturn($this->makeItem(7, 'movie', 27205));
        // #137: the Mark as Watched form can start blank; '' must not become today.
        $this->request->method('getParams')->willReturn(['dateWatched' => '', 'languageWatched' => 'en']);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);
        $this->movieService->method('findByTmdbId')->willReturn(null);
        $this->movieService->expects($this->once())
            ->method('create')
            ->with('testuser', self::LIBRARY_ID, $this->callback(fn($d) => array_key_exists('dateWatched', $d) && $d['dateWatched'] === null))
            ->willReturn(new \OCA\MovieDB\Db\Movie());

        $this->assertEquals(Http::STATUS_OK, $this->controller()->moveToWatched(7)->getStatus());
    }

    public function testMoveMovieWithOnlyAnEmptyDateStillCreatesTheMovie(): void {
        $this->movieService = $this->createMock(MovieService::class);
        $this->service->method('find')->willReturn($this->makeItem(7, 'movie', 27205));
        // Date-only request (no language, rating or platform), the date left blank.
        $this->request->method('getParams')->willReturn(['dateWatched' => '']);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);
        $this->movieService->method('findByTmdbId')->willReturn(null);
        // The watch field is passed on (as null), so MovieService records an undated viewing.
        $this->movieService->expects($this->once())
            ->method('create')
            ->with('testuser', self::LIBRARY_ID, $this->callback(fn($d) => array_key_exists('dateWatched', $d) && $d['dateWatched'] === null))
            ->willReturn(new \OCA\MovieDB\Db\Movie());

        $this->assertEquals(Http::STATUS_OK, $this->controller()->moveToWatched(7)->getStatus());
    }

    public function testMoveMovieWithoutADateFieldStillUsesToday(): void {
        $this->movieService = $this->createMock(MovieService::class);
        $this->service->method('find')->willReturn($this->makeItem(7, 'movie', 27205));
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);
        $this->movieService->method('findByTmdbId')->willReturn(null);
        $today = date('Y-m-d');
        $this->movieService->expects($this->once())
            ->method('create')
            ->with('testuser', self::LIBRARY_ID, $this->callback(fn($d) => $d['dateWatched'] === $today))
            ->willReturn(new \OCA\MovieDB\Db\Movie());

        $this->assertEquals(Http::STATUS_OK, $this->controller()->moveToWatched(7)->getStatus());
    }

    public function testAcknowledgeReleaseDateReturnsTheItem(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $item = $this->makeItem(5, 'movie', 1);
        $this->service->expects($this->once())->method('acknowledgeReleaseDate')
            ->with(5, self::LIBRARY_ID)
            ->willReturn($item);

        $response = $this->controller()->acknowledgeReleaseDate(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($item, $response->getData()['item']);
    }

    public function testAcknowledgeReleaseDateIsForbiddenWithoutEditPermission(): void {
        $this->service = $this->createMock(WatchlistService::class);
        // A read-only member must not clear the badge for everyone who shares the library.
        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('resolveLibraryId')->willReturn(self::LIBRARY_ID);
        $libraryService->method('canEdit')->willReturn(false);

        $controller = new WatchlistController(
            $this->request,
            $this->service,
            $this->movieService,
            $this->watchService,
            $this->seriesService,
            $this->tmdbService,
            $libraryService,
            $this->db,
            $this->userSession,
            $this->logger
        );
        $this->service->expects($this->never())->method('acknowledgeReleaseDate');

        $this->assertEquals(Http::STATUS_FORBIDDEN, $controller->acknowledgeReleaseDate(5)->getStatus());
    }

    public function testAcknowledgeReleaseDateReturnsNotFoundForAnUnknownItem(): void {
        $this->service->method('acknowledgeReleaseDate')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('x'));

        $this->assertEquals(Http::STATUS_NOT_FOUND, $this->controller()->acknowledgeReleaseDate(5)->getStatus());
    }

    public function testIndexDefaultsToPageOneAndLimitFifty(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 50, 0)
            ->willReturn([]);
        $this->service->expects($this->once())
            ->method('count')
            ->with(self::LIBRARY_ID, $this->anything())
            ->willReturn(0);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(50, $data['limit']);
    }

    public function testIndexAppliesPageAndLimitParams(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => match ($k) {
                'page' => '3',
                'limit' => '10',
                default => $d,
            }
        );

        // page 3, limit 10 -> offset 20
        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 10, 20)
            ->willReturn([]);
        $this->service->method('count')->willReturn(25);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(3, $data['page']);
        $this->assertSame(10, $data['limit']);
        $this->assertSame(3, (int)$data['totalPages']);
    }

    public function testIndexCapsLimitAtOneHundred(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'limit' ? '500' : $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 100, 0)
            ->willReturn([]);
        $this->service->method('count')->willReturn(0);

        $response = $this->controller()->index();

        $this->assertSame(100, $response->getData()['limit']);
    }

    public function testIndexForwardsMediaTypeFilter(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'mediaType' ? 'series' : $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn($f) => ($f['mediaType'] ?? null) === 'series'), $this->anything(), $this->anything())
            ->willReturn([]);
        // count() is called twice: once with the mediaType filter (for
        // pagination's `total`), once without any filter (for the sidebar's
        // `totalUnfiltered`).
        $this->service->expects($this->exactly(2))
            ->method('count')
            ->willReturnCallback(function ($libId, $filters) {
                $this->assertSame(self::LIBRARY_ID, $libId);
                return 0;
            });

        $this->controller()->index();
    }

    public function testIndexReturnsTotalUnfilteredWhenFilterApplied(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'search' ? 'dune' : $d
        );

        $callCount = 0;
        $this->service->method('count')->willReturnCallback(function ($libId, $filters) use (&$callCount) {
            $callCount++;
            return empty($filters) ? 50 : 3;
        });
        $this->service->method('findAll')->willReturn([]);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(3, $data['total']);
        $this->assertSame(50, $data['totalUnfiltered']);
        $this->assertSame(2, $callCount);
    }

    public function testIndexTotalUnfilteredMatchesTotalWhenNoFilterApplied(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);

        $this->service->expects($this->once())
            ->method('count')
            ->with(self::LIBRARY_ID, $this->anything())
            ->willReturn(12);
        $this->service->method('findAll')->willReturn([]);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(12, $data['total']);
        $this->assertSame(12, $data['totalUnfiltered']);
    }

    public function testIndexIgnoresInvalidMediaType(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'mediaType' ? 'bogus' : $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn($f) => ($f['mediaType'] ?? null) === null), $this->anything(), $this->anything())
            ->willReturn([]);
        $this->service->method('count')->willReturn(0);

        $this->controller()->index();
    }

    public function testIndexClampsInvalidPaginationParams(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => match ($k) {
                'page' => '0',
                'limit' => '0',
                default => $d,
            }
        );

        // page 0 clamps to 1, limit 0 clamps to 1 -> offset 0, no division by zero.
        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 1, 0)
            ->willReturn([]);
        $this->service->method('count')->willReturn(5);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['limit']);
        $this->assertSame(5, $data['totalPages']);
        $this->assertIsInt($data['totalPages']);
    }

    public function testIndexClampsNegativePageAndLimit(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => match ($k) {
                'page' => '-3',
                'limit' => '-10',
                default => $d,
            }
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 1, 0)
            ->willReturn([]);
        $this->service->method('count')->willReturn(0);

        $response = $this->controller()->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['limit']);
    }

    public function testRandomForwardsFiltersAndReturnsItem(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $params = ['mediaType' => 'movie', 'genre' => '878', 'minPriority' => '1', 'exclude' => '4'];
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
        $item = $this->makeItem(5, 'movie', 1);

        $this->service->expects($this->once())
            ->method('pickRandom')
            ->with(self::LIBRARY_ID, [
                'mediaType' => 'movie',
                'genre' => 878,
                'minPriority' => 1,
                'excludeId' => 4,
            ])
            ->willReturn($item);

        $response = $this->controller()->random();

        $this->assertSame($item, $response->getData()['item']);
    }

    public function testRandomClampsPriorityAndReturnsNullItem(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $params = ['minPriority' => '99', 'genre' => '-3'];
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);

        $this->service->expects($this->once())
            ->method('pickRandom')
            ->with(self::LIBRARY_ID, $this->callback(
                fn ($f) => $f['minPriority'] === 2 && $f['genre'] === 0 && $f['excludeId'] === 0
            ))
            ->willReturn(null);

        $this->assertNull($this->controller()->random()->getData()['item']);
    }

    public function testRandomWithCountReturnsASetAndKeepsItemForCompatibility(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $params = ['count' => '8', 'genre' => '28'];
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
        $a = $this->makeItem(1, 'movie', 1);
        $b = $this->makeItem(2, 'movie', 2);

        $this->service->expects($this->once())->method('pickRandomSet')
            ->with(self::LIBRARY_ID, $this->callback(fn ($f) => $f['genre'] === 28), 8)->willReturn([$a, $b]);
        $this->service->expects($this->never())->method('pickRandom');

        $data = $this->controller()->random()->getData();

        $this->assertSame([$a, $b], $data['items']);
        $this->assertSame($a, $data['item']);
    }

    public function testRandomClampsCountToTwelve(): void {
        $this->service = $this->createMock(WatchlistService::class);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $params = ['count' => '500'];
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);

        $this->service->expects($this->once())->method('pickRandomSet')->with(self::LIBRARY_ID, $this->anything(), 12)->willReturn([]);

        $this->assertNull($this->controller()->random()->getData()['item']);
    }
}
