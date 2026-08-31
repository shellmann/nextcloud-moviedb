<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\MovieController;
use OCA\MovieDB\Db\Movie;
use OCA\MovieDB\Db\MovieWatch;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\MovieService;
use OCA\MovieDB\Service\MovieWatchService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Tests that MovieController::update persists watch fields (rating, review,
 * dateWatched) to the latest watch entry, not just movie metadata.
 *
 * This is the regression test for the bug where edits to rating/review/date
 * showed a success toast but were silently dropped because MovieService::update
 * no longer handles watch fields in the rewatch model.
 */
class MovieControllerUpdateTest extends TestCase {
    private IRequest $request;
    private MovieService $movieService;
    private MovieWatchService $watchService;
    private LibraryService $libraryService;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private MovieController $controller;

    private const LIBRARY_ID = 1;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->movieService = $this->createMock(MovieService::class);
        $this->watchService = $this->createMock(MovieWatchService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        // The controller resolves the libraryId from the request via libraryService.
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIBRARY_ID);
        $this->libraryService->method('canEdit')->willReturn(true);

        $this->controller = new MovieController(
            $this->request,
            $this->movieService,
            $this->watchService,
            $this->libraryService,
            $this->userSession,
            $this->logger
        );
    }

    private function makeMovie(int $id = 1): Movie {
        $movie = new Movie();
        $movie->setId($id);
        $movie->setTitle('Inception');
        $movie->setUserId('testuser');
        return $movie;
    }

    private function makeWatch(int $id = 10): MovieWatch {
        $watch = new MovieWatch();
        $watch->setId($id);
        $watch->setMovieId(1);
        $watch->setUserId('testuser');
        $watch->setRating(8);
        $watch->setWatchedAt('2024-06-15');
        return $watch;
    }

    public function testUpdatePersistsRatingToLatestWatch(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Inception',
            'rating' => 9,
        ]);

        $this->movieService->method('update')->willReturn($this->makeMovie());
        $this->watchService->method('findByMovie')->willReturn([$this->makeWatch()]);

        $this->watchService->expects($this->once())
            ->method('update')
            ->with(10, self::LIBRARY_ID, $this->arrayHasKey('rating'));

        $response = $this->controller->update(1);
        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdatePersistsReviewToLatestWatch(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Inception',
            'review' => 'Still amazing',
        ]);

        $this->movieService->method('update')->willReturn($this->makeMovie());
        $this->watchService->method('findByMovie')->willReturn([$this->makeWatch()]);

        $this->watchService->expects($this->once())
            ->method('update')
            ->with(10, self::LIBRARY_ID, $this->callback(fn($d) => ($d['review'] ?? null) === 'Still amazing'));

        $this->controller->update(1);
    }

    public function testUpdatePersistsDateWatchedToLatestWatch(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Inception',
            'dateWatched' => '2025-03-20',
        ]);

        $this->movieService->method('update')->willReturn($this->makeMovie());
        $this->watchService->method('findByMovie')->willReturn([$this->makeWatch()]);

        $this->watchService->expects($this->once())
            ->method('update')
            ->with(10, self::LIBRARY_ID, $this->callback(fn($d) => ($d['watchedAt'] ?? null) === '2025-03-20'));

        $this->controller->update(1);
    }

    public function testUpdateSkipsWatchUpdateWhenNoWatchFields(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Inception Remastered',
        ]);

        $this->movieService->method('update')->willReturn($this->makeMovie());

        // watchService::update must NOT be called when no watch fields present
        $this->watchService->expects($this->never())->method('update');

        $response = $this->controller->update(1);
        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateCreatesWatchWhenNoWatchesExist(): void {
        $this->request->method('getParams')->willReturn([
            'title' => 'Inception',
            'rating' => 9,
        ]);

        $this->movieService->method('update')->willReturn($this->makeMovie());
        $this->watchService->method('findByMovie')->willReturn([]);

        $this->watchService->expects($this->never())->method('update');
        $this->watchService->expects($this->once())
            ->method('create')
            ->with(1, 'testuser', self::LIBRARY_ID, $this->callback(fn($d) => ($d['rating'] ?? null) === 9));

        $response = $this->controller->update(1);
        $this->assertEquals(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateUpdatesLatestWatchNotOlderOnes(): void {
        $this->request->method('getParams')->willReturn([
            'rating' => 7,
        ]);

        $latest = $this->makeWatch(20);  // most recent — id 20
        $older  = $this->makeWatch(10);  // older — id 10

        $this->movieService->method('update')->willReturn($this->makeMovie());
        // findByMovie returns DESC order (latest first)
        $this->watchService->method('findByMovie')->willReturn([$latest, $older]);

        $this->watchService->expects($this->once())
            ->method('update')
            ->with(20, self::LIBRARY_ID, $this->anything()); // must use id=20, not 10

        $this->controller->update(1);
    }
}
