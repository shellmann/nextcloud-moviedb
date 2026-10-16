<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\SeriesController;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\SeriesProgressService;
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
    private IUserSession&MockObject $userSession;

    private const LIB = 4;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(SeriesService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->progressService = $this->createMock(SeriesProgressService::class);

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

    public function testIndexFiltersByWatchStatusIds(): void {
        $this->params(['watchStatus' => 'finished']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->method('idsWithStatus')->with(self::LIB, 'finished')->willReturn([2, 5]);
        $this->service->expects($this->once())->method('findAll')
            ->with(self::LIB, $this->callback(fn (array $f) => $f['ids'] === [2, 5]))->willReturn([]);
        $this->service->method('count')->willReturn(0);
        $this->progressService->method('attachProgress')->willReturn([]);

        $this->controller()->index();
    }

    public function testIndexShortCircuitsWhenNoSeriesHasTheStatus(): void {
        $this->params(['watchStatus' => 'stopped']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->method('idsWithStatus')->willReturn([]);
        $this->service->expects($this->never())->method('findAll');

        $data = $this->controller()->index()->getData();
        $this->assertSame([[], 0, 0], [$data['series'], $data['total'], $data['totalPages']]);
    }

    public function testIndexIgnoresUnknownWatchStatus(): void {
        $this->params(['watchStatus' => 'bogus']);
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->progressService->expects($this->never())->method('idsWithStatus');
        $this->service->expects($this->once())->method('findAll')
            ->with(self::LIB, $this->callback(fn (array $f) => !isset($f['ids'])))->willReturn([]);
        $this->progressService->method('attachProgress')->willReturn([]);

        $this->controller()->index();
    }
}
