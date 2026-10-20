<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\SeriesController;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\SeriesProgressService;
use OCA\MovieDB\Service\SeriesRefreshService;
use OCA\MovieDB\Service\SeriesService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class SeriesControllerTest extends TestCase {

    private IRequest&MockObject $request;
    private SeriesService&MockObject $service;
    private LibraryService&MockObject $libraryService;
    private SeriesProgressService&MockObject $progressService;
    private SeriesRefreshService&MockObject $refreshService;
    private IUserSession&MockObject $userSession;

    private const LIB = 4;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(SeriesService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->progressService = $this->createMock(SeriesProgressService::class);
        $this->refreshService = $this->createMock(SeriesRefreshService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(?IUserSession $session = null): SeriesController {
        return new SeriesController(
            $this->request,
            $this->service,
            $this->libraryService,
            $session ?? $this->userSession,
            $this->createMock(LoggerInterface::class),
            $this->progressService,
            $this->refreshService,
        );
    }

    private function params(array $params): void {
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
    }

    public function testNewEndpointsRequireAuthentication(): void {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $controller = $this->controller($session);

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->continueWatching()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->stopped(1)->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->comingUp()->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->refresh(1)->getStatus());
    }

    public function testContinueWatchingPassesLibraryAndLimit(): void {
        $this->params(['limit' => '7']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->expects($this->once())->method('continueWatching')->with(self::LIB, 7)
            ->willReturn([['id' => 1]]);

        $this->assertSame(['series' => [['id' => 1]]], $this->controller()->continueWatching()->getData());
    }

    public function testContinueWatchingDefaultsToTen(): void {
        $this->params([]);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->expects($this->once())->method('continueWatching')->with(self::LIB, 10)->willReturn([]);

        $this->controller()->continueWatching();
    }

    public function testComingUpPassesLibraryDaysAndLimit(): void {
        $this->params(['days' => '14', 'limit' => '5']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->expects($this->once())->method('comingUp')->with(self::LIB, 14, 5)
            ->willReturn([['id' => 3]]);

        $this->assertSame(['series' => [['id' => 3]]], $this->controller()->comingUp()->getData());
    }

    public function testComingUpDefaultsToThirtyDaysAndTenShows(): void {
        $this->params([]);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->expects($this->once())->method('comingUp')->with(self::LIB, 30, 10)->willReturn([]);

        $this->controller()->comingUp();
    }

    public function testRefreshRequiresEditPermission(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(false);
        $this->refreshService->expects($this->never())->method('refresh');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->refresh(1)->getStatus());
    }

    public function testRefreshForInaccessibleLibraryIsForbidden(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willThrowException(new \InvalidArgumentException());

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->refresh(1)->getStatus());
    }

    public function testRefreshWithoutAnyTmdbKeyIsABadRequestWithACode(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->refreshService->method('hasApiKey')->with('alice')->willReturn(false);
        $this->refreshService->expects($this->never())->method('refresh');

        $response = $this->controller()->refresh(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('no_api_key', $response->getData()['code']);
    }

    public function testRefreshForUnknownSeriesIsNotFound(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->refreshService->method('hasApiKey')->willReturn(true);
        $this->service->method('find')->willThrowException(new DoesNotExistException('x'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->refresh(99)->getStatus());
    }

    public function testRefreshReadsEverySeasonWithTheCurrentUsersKeyAndTheRequestedLanguage(): void {
        $this->params(['language' => 'de-DE']);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->refreshService->method('hasApiKey')->willReturn(true);
        $series = new \OCA\MovieDB\Db\Series();
        $this->service->method('find')->with(1, self::LIB)->willReturn($series);
        $this->service->method('findWithProgress')->with(1, self::LIB)->willReturn(['id' => 1]);
        $result = ['newEpisodes' => 2, 'updatedEpisodes' => 0, 'newSeasons' => 1];
        $this->refreshService->expects($this->once())->method('refresh')->with($series, 'alice', 'de-DE', true)->willReturn($result);

        $response = $this->controller()->refresh(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['series' => ['id' => 1], 'result' => $result], $response->getData());
    }

    public function testRefreshReportsTmdbFailuresWithoutLeakingDetails(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->refreshService->method('hasApiKey')->willReturn(true);
        $this->service->method('find')->willReturn(new \OCA\MovieDB\Db\Series());
        $this->refreshService->method('refresh')->willThrowException(new \RuntimeException('secret url with key', 500));

        $response = $this->controller()->refresh(1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('tmdb_error', $response->getData()['code']);
        $this->assertStringNotContainsString('secret', json_encode($response->getData()));
    }

    public function testRefreshTellsWhenTmdbIsBusy(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->refreshService->method('hasApiKey')->willReturn(true);
        $this->service->method('find')->willReturn(new \OCA\MovieDB\Db\Series());
        $this->refreshService->method('refresh')->willThrowException(new \RuntimeException('429', 429));

        $this->assertSame('tmdb_busy', $this->controller()->refresh(1)->getData()['code']);
    }

    public function testStoppedRequiresEditPermission(): void {
        $this->params(['stopped' => 'true']);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(false);
        $this->service->expects($this->never())->method('setStoppedWatching');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->stopped(1)->getStatus());
    }

    public function testStoppedForInaccessibleLibraryIsForbidden(): void {
        $this->params([]);
        $this->libraryService->method('resolveLibraryId')->willThrowException(new \InvalidArgumentException());

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->stopped(1)->getStatus());
    }

    public function testStoppedForUnknownSeriesIsNotFound(): void {
        $this->params(['stopped' => 'true']);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->service->method('setStoppedWatching')->willThrowException(new DoesNotExistException('x'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->stopped(99)->getStatus());
    }

    /**
     * @dataProvider stoppedProvider
     */
    public function testStoppedSetsOrClearsTheFlag(string $param, bool $stopped): void {
        $this->params(['stopped' => $param]);
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->method('canEdit')->willReturn(true);
        $this->service->expects($this->once())->method('setStoppedWatching')->with(1, self::LIB, $stopped);
        $this->service->method('findWithProgress')->with(1, self::LIB)->willReturn(['id' => 1]);

        $response = $this->controller()->stopped(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['series' => ['id' => 1]], $response->getData());
    }

    public static function stoppedProvider(): array {
        return [['true', true], ['1', true], ['false', false], ['0', false]];
    }

    public function testIndexPassesAKnownWatchStatusToTheQuery(): void {
        $this->params(['watchStatus' => 'finished']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->service->expects($this->once())->method('findAll')
            ->with(self::LIB, $this->callback(fn (array $f) => $f['watchStatus'] === 'finished'))->willReturn([]);
        $this->service->expects($this->once())->method('count')
            ->with(self::LIB, $this->callback(fn (array $f) => $f['watchStatus'] === 'finished'))->willReturn(0);
        $this->progressService->method('attachProgress')->willReturn([]);

        $this->assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
    }

    public function testIndexWithoutWatchStatusListsAllShows(): void {
        $this->params(['watchStatus' => '']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->service->expects($this->once())->method('findAll')
            ->with(self::LIB, $this->callback(fn (array $f) => !isset($f['watchStatus'])))->willReturn([]);
        $this->progressService->method('attachProgress')->willReturn([]);

        $this->controller()->index();
    }

    /**
     * @dataProvider badWatchStatusProvider
     */
    public function testIndexRejectsAnUnknownWatchStatus(mixed $value): void {
        $this->params(['watchStatus' => $value]);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->service->expects($this->never())->method('findAll');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->index()->getStatus());
    }

    public static function badWatchStatusProvider(): array {
        return [['bogus'], [['finished']]];
    }
}
