<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\AdminSettingsController;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;

class AdminSettingsControllerTest extends TestCase {
    private IRequest $request;
    private TmdbService $tmdbService;
    private AdminSettingsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->tmdbService = $this->createMock(TmdbService::class);

        $this->controller = new AdminSettingsController($this->request, $this->tmdbService);
    }

    /**
     * Without #[NoAdminRequired], Nextcloud only lets admins call the
     * endpoints. Guards against opening them to every user by accident.
     */
    public function testEndpointsAreAdminOnly(): void {
        $class = new \ReflectionClass(AdminSettingsController::class);

        $this->assertEmpty($class->getAttributes(NoAdminRequired::class));
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== AdminSettingsController::class) {
                continue;
            }
            $this->assertEmpty(
                $method->getAttributes(NoAdminRequired::class),
                $method->getName() . ' must stay admin only'
            );
        }
    }

    public function testSavesVerifiedKey(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->with('new-key')->willReturn(true);
        $this->tmdbService->expects($this->once())->method('setInstanceApiKey')->with('new-key');
        $this->tmdbService->method('hasInstanceApiKey')->willReturn(true);

        $response = $this->controller->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['hasInstanceApiKey' => true], $response->getData());
    }

    public function testEmptyKeyRemovesIt(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => '']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->tmdbService->expects($this->once())->method('deleteInstanceApiKey');
        $this->tmdbService->method('hasInstanceApiKey')->willReturn(false);

        $response = $this->controller->update();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['hasInstanceApiKey' => false], $response->getData());
    }

    public function testMissingKeyChangesNothing(): void {
        $this->request->method('getParams')->willReturn([]);
        $this->tmdbService->expects($this->never())->method('deleteInstanceApiKey');
        $this->tmdbService->expects($this->never())->method('setInstanceApiKey');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
    }

    public function testInvalidKeyFormatIsRejected(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'not a key']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->tmdbService->expects($this->never())->method('setInstanceApiKey');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
    }

    public function testKeyRejectedByTmdbIsNotSaved(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'wrong-key']);
        $this->tmdbService->method('verifyApiKey')->willReturn(false);
        $this->tmdbService->expects($this->never())->method('setInstanceApiKey');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->update()->getStatus());
    }

    public function testTmdbUnreachableIsNotSaved(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->willThrowException(new \RuntimeException('Could not reach TMDB'));
        $this->tmdbService->expects($this->never())->method('setInstanceApiKey');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller->update()->getStatus());
    }

    public function testResponseNeverContainsTheKey(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->willReturn(true);
        $this->tmdbService->method('hasInstanceApiKey')->willReturn(true);

        $this->assertStringNotContainsString('new-key', json_encode($this->controller->update()->getData()));
    }
}
