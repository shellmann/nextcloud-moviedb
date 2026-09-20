<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\AuthenticatedController;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Unit tests for AuthenticatedController
 *
 * Tests the base authentication functionality for all API controllers.
 */
class AuthenticatedControllerTest extends TestCase {
    private IRequest $request;
    private IUserSession $userSession;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
    }

    public function testRequireAuthReturnsNullWhenAuthenticated(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userSession->method('getUser')->willReturn($user);

        $controller = $this->createAuthenticatedController();

        $result = $this->invokeRequireAuth($controller);

        $this->assertNull($result);
    }

    public function testRequireAuthReturnsErrorWhenNotAuthenticated(): void {
        $this->userSession->method('getUser')->willReturn(null);

        $controller = $this->createAuthenticatedController();

        $result = $this->invokeRequireAuth($controller);

        $this->assertNotNull($result);
        $this->assertEquals(Http::STATUS_UNAUTHORIZED, $result->getStatus());

        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Not authenticated', $data['error']);
    }

    public function testUserIdIsSetWhenAuthenticated(): void {
        $userId = 'testuser123';
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($userId);

        $this->userSession->method('getUser')->willReturn($user);

        $controller = $this->createAuthenticatedController();

        $this->assertEquals($userId, $this->getUserId($controller));
    }

    public function testUserIdIsNullWhenNotAuthenticated(): void {
        $this->userSession->method('getUser')->willReturn(null);

        $controller = $this->createAuthenticatedController();

        $this->assertNull($this->getUserId($controller));
    }

    /**
     * @dataProvider mediaTypeProvider
     */
    public function testMediaTypeParam(?string $rawValue, ?string $expected): void {
        $this->request->method('getParam')->with('mediaType')->willReturn($rawValue);

        $controller = $this->createAuthenticatedController();

        $this->assertSame($expected, $controller->exposeMediaTypeParam());
    }

    public static function mediaTypeProvider(): array {
        return [
            'movie is valid' => ['movie', 'movie'],
            'series is valid' => ['series', 'series'],
            'null is treated as no filter' => [null, null],
            'garbage is treated as no filter' => ['bogus', null],
            'empty string is treated as no filter' => ['', null],
        ];
    }

    /**
     * Create a concrete implementation of AuthenticatedController for testing
     */
    private function createAuthenticatedController(): AuthenticatedController {
        return new class('moviedb', $this->request, $this->userSession) extends AuthenticatedController {
            public function exposeRequireAuth() {
                return $this->requireAuth();
            }

            public function exposeUserId(): ?string {
                return $this->userId;
            }

            public function exposeMediaTypeParam(): ?string {
                return $this->mediaTypeParam();
            }
        };
    }

    /**
     * Invoke the protected requireAuth method
     */
    private function invokeRequireAuth(AuthenticatedController $controller) {
        return $controller->exposeRequireAuth();
    }

    /**
     * Get the protected userId property
     */
    private function getUserId(AuthenticatedController $controller): ?string {
        return $controller->exposeUserId();
    }
}
