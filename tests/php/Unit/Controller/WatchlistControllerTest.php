<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\WatchlistController;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Service\MovieWatchService;
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
    private WatchlistController $controller;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(WatchlistService::class);
        $this->movieService = $this->createMock(MovieService::class);
        $this->watchService = $this->createMock(MovieWatchService::class);
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIBRARY_ID);
        $this->libraryService->method('canEdit')->willReturn(true);

        $this->db->method('beginTransaction');
        $this->db->method('commit');

        $this->controller = new WatchlistController(
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

        $response = $this->controller->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayNotHasKey('movie', $data);
        $this->assertSame(42, $data['series']->getId());
    }

    public function testMoveMovieCreatesMovieAndReturnsMovie(): void {
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

        $response = $this->controller->moveToWatched(7);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('movie', $data);
        $this->assertArrayNotHasKey('series', $data);
        $this->assertSame(99, $data['movie']->getId());
    }

    public function testIndexDefaultsToPageOneAndLimitFifty(): void {
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

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(50, $data['limit']);
    }

    public function testIndexAppliesPageAndLimitParams(): void {
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

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(3, $data['page']);
        $this->assertSame(10, $data['limit']);
        $this->assertSame(3, (int)$data['totalPages']);
    }

    public function testIndexCapsLimitAtOneHundred(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'limit' ? '500' : $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->anything(), 100, 0)
            ->willReturn([]);
        $this->service->method('count')->willReturn(0);

        $response = $this->controller->index();

        $this->assertSame(100, $response->getData()['limit']);
    }

    public function testIndexForwardsMediaTypeFilter(): void {
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

        $this->controller->index();
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

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(3, $data['total']);
        $this->assertSame(50, $data['totalUnfiltered']);
        $this->assertSame(2, $callCount);
    }

    public function testIndexTotalUnfilteredMatchesTotalWhenNoFilterApplied(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(fn($k, $d = null) => $d);

        $this->service->expects($this->once())
            ->method('count')
            ->with(self::LIBRARY_ID, $this->anything())
            ->willReturn(12);
        $this->service->method('findAll')->willReturn([]);

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(12, $data['total']);
        $this->assertSame(12, $data['totalUnfiltered']);
    }

    public function testIndexIgnoresInvalidMediaType(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIBRARY_ID);
        $this->request->method('getParam')->willReturnCallback(
            fn($k, $d = null) => $k === 'mediaType' ? 'bogus' : $d
        );

        $this->service->expects($this->once())
            ->method('findAll')
            ->with(self::LIBRARY_ID, $this->callback(fn($f) => ($f['mediaType'] ?? null) === null), $this->anything(), $this->anything())
            ->willReturn([]);
        $this->service->method('count')->willReturn(0);

        $this->controller->index();
    }

    public function testIndexClampsInvalidPaginationParams(): void {
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

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['limit']);
        $this->assertSame(5, $data['totalPages']);
        $this->assertIsInt($data['totalPages']);
    }

    public function testIndexClampsNegativePageAndLimit(): void {
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

        $response = $this->controller->index();

        $data = $response->getData();
        $this->assertSame(1, $data['page']);
        $this->assertSame(1, $data['limit']);
    }
}
