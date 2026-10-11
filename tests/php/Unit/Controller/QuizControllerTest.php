<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\QuizController;
use OCA\MovieDB\Db\QuizResult;
use OCA\MovieDB\Service\LibraryService;
use OCA\MovieDB\Service\QuizResultService;
use OCA\MovieDB\Service\QuizService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class QuizControllerTest extends TestCase {

    private IRequest&MockObject $request;
    private QuizService&MockObject $quizService;
    private QuizResultService&MockObject $resultService;
    private LibraryService&MockObject $libraryService;
    private IUserSession&MockObject $userSession;
    private QuizController $controller;

    private const LIB = 5;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->quizService = $this->createMock(QuizService::class);
        $this->resultService = $this->createMock(QuizResultService::class);
        $this->libraryService = $this->createMock(LibraryService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = $this->buildController();
    }

    private function buildController(): QuizController {
        return new QuizController(
            $this->request,
            $this->quizService,
            $this->resultService,
            $this->libraryService,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function params(array $params): void {
        $this->request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
    }

    public function testEveryEndpointRequiresAuthentication(): void {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $controller = new QuizController(
            $this->request, $this->quizService, $this->resultService, $this->libraryService,
            $session, $this->createMock(LoggerInterface::class)
        );

        foreach (['meta', 'generate', 'results', 'saveResult', 'resetResults'] as $method) {
            $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->$method()->getStatus(), $method);
        }
    }

    public function testMetaUsesReadLibraryAndUser(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->quizService->expects($this->once())->method('meta')->with(self::LIB, 'alice')
            ->willReturn(['maxQuestions' => 7, 'titleCount' => 9]);

        $this->assertSame(['maxQuestions' => 7, 'titleCount' => 9], $this->controller->meta()->getData());
    }

    public function testGeneratePassesCountToTheService(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $this->params(['count' => '12']);
        $this->quizService->expects($this->once())->method('generate')->with(self::LIB, 'alice', 12)
            ->willReturn([['type' => 'rating']]);

        $this->assertSame([['type' => 'rating']], $this->controller->generate()->getData()['questions']);
    }

    public function testResultsReturnsServiceData(): void {
        $this->libraryService->method('resolveReadLibraryId')->willReturn(self::LIB);
        $data = ['rounds' => [], 'best' => null, 'accuracy' => []];
        $this->resultService->expects($this->once())->method('list')->with('alice', self::LIB)->willReturn($data);

        $this->assertSame($data, $this->controller->results()->getData());
    }

    public function testSaveResultByAViewerIsAllowedAndReturns201(): void {
        // No canEdit check on purpose: viewers may keep their own results.
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->libraryService->expects($this->never())->method('canEdit');
        $this->params(['questionCount' => 2, 'score' => 15, 'maxScore' => 20, 'breakdown' => ['rating' => ['points' => 15, 'max' => 20]]]);
        $result = new QuizResult();
        $this->resultService->expects($this->once())->method('create')
            ->with('alice', self::LIB, ['questionCount' => 2, 'score' => 15, 'maxScore' => 20, 'breakdown' => ['rating' => ['points' => 15, 'max' => 20]]])
            ->willReturn($result);

        $response = $this->controller->saveResult();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame($result, $response->getData()['result']);
    }

    public function testSaveResultForInaccessibleLibraryIsForbidden(): void {
        $this->libraryService->method('resolveLibraryId')->willThrowException(new \InvalidArgumentException('nope'));
        $this->resultService->expects($this->never())->method('create');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->saveResult()->getStatus());
    }

    public function testSaveResultPassesValidationMessageAs422(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->resultService->method('create')->willThrowException(new \InvalidArgumentException('Invalid score.'));

        $response = $this->controller->saveResult();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('Invalid score.', $response->getData()['error']);
    }

    public function testSaveResultHidesUnexpectedErrors(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->resultService->method('create')->willThrowException(new \RuntimeException('db password in message'));

        $response = $this->controller->saveResult();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringNotContainsString('password', json_encode($response->getData()));
    }

    public function testResetDeletesOnlyOwnResultsOfTheActiveLibrary(): void {
        $this->libraryService->method('resolveLibraryId')->willReturn(self::LIB);
        $this->resultService->expects($this->once())->method('reset')->with('alice', self::LIB)->willReturn(3);

        $this->assertSame(['deleted' => 3], $this->controller->resetResults()->getData());
    }

    public function testResetForInaccessibleLibraryIsForbidden(): void {
        $this->libraryService->method('resolveLibraryId')->willThrowException(new \InvalidArgumentException('nope'));
        $this->resultService->expects($this->never())->method('reset');

        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->resetResults()->getStatus());
    }

    public function testEndpointsAreNotAdminOnlyAndRateLimitedWhereExpected(): void {
        foreach (['meta', 'generate', 'results', 'saveResult', 'resetResults'] as $method) {
            $attrs = array_map(
                fn ($a) => $a->getName(),
                (new \ReflectionMethod(QuizController::class, $method))->getAttributes()
            );
            $this->assertContains(\OCP\AppFramework\Http\Attribute\NoAdminRequired::class, $attrs, $method);
        }
        foreach (['generate', 'saveResult'] as $method) {
            $attrs = array_map(
                fn ($a) => $a->getName(),
                (new \ReflectionMethod(QuizController::class, $method))->getAttributes()
            );
            $this->assertContains(\OCP\AppFramework\Http\Attribute\UserRateLimit::class, $attrs, $method);
        }
    }
}
