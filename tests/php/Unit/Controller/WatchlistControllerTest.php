<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\WatchlistController;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\WatchlistItem;
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
    private IDBConnection $db;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private WatchlistController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(WatchlistService::class);
        $this->movieService = $this->createMock(MovieService::class);
        $this->watchService = $this->createMock(MovieWatchService::class);
        $this->seriesService = $this->createMock(SeriesService::class);
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new WatchlistController(
            $this->request,
            $this->service,
            $this->movieService,
            $this->watchService,
            $this->seriesService,
            $this->tmdbService,
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

        $this->service->method('find')->with(5, 'testuser')->willReturn($item);
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
            ->with('testuser', $this->callback(fn($d) => ($d['tmdbId'] ?? null) === 1399), 'en-US')
            ->willReturn($series);
        $this->movieService->expects($this->never())->method('create');
        $this->watchService->expects($this->never())->method('create');

        // Watchlist row removed in the same flow.
        $this->service->expects($this->once())->method('delete')->with(5, 'testuser');

        $response = $this->controller->moveToWatched(5);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayNotHasKey('movie', $data);
        $this->assertSame(42, $data['series']->getId());
    }

    public function testMoveMovieCreatesMovieAndReturnsMovie(): void {
        $item = $this->makeItem(7, 'movie', 27205);

        $this->service->method('find')->with(7, 'testuser')->willReturn($item);
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
        $this->service->expects($this->once())->method('delete')->with(7, 'testuser');

        $response = $this->controller->moveToWatched(7);

        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('movie', $data);
        $this->assertArrayNotHasKey('series', $data);
        $this->assertSame(99, $data['movie']->getId());
    }
}
