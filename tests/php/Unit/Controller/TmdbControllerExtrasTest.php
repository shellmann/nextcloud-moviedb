<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\TmdbController;
use OCA\MovieDB\Service\TmdbExtrasService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TmdbControllerExtrasTest extends TestCase {
    private IRequest $request;
    private TmdbExtrasService $extras;
    private IUserSession $userSession;
    private ?TmdbController $controller = null;

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createStub(IRequest::class);
        $this->extras = $this->createStub(TmdbExtrasService::class);
        $this->userSession = $this->createStub(IUserSession::class);
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): TmdbController {
        return $this->controller ??= new TmdbController(
            $this->request,
            $this->createStub(TmdbService::class),
            $this->userSession,
            $this->createStub(LoggerInterface::class),
            $this->extras,
        );
    }

    private function language(string $language): void {
        $this->request->method('getParam')->willReturnCallback(
            fn (string $key, $default = null) => $key === 'language' ? $language : $default
        );
    }

    public function testReturnsExtras(): void {
        $this->language('de-DE');
        $this->extras = $this->createMock(TmdbExtrasService::class);
        $this->extras->method('hasApiKey')->willReturn(true);
        $this->extras->expects($this->once())->method('get')
            ->with('tv', 42, 'alice', 'de-DE')->willReturn(['trailer' => null, 'watch' => []]);

        $response = $this->controller()->extras('tv', 42);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['trailer' => null, 'watch' => []], $response->getData());
    }

    public function testWithoutKeyIsQuietlyEmpty(): void {
        $this->extras->method('hasApiKey')->willReturn(false);

        $response = $this->controller()->extras('movie', 1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['trailer' => null, 'watch' => null, 'reason' => 'no_api_key'], $response->getData());
    }

    public function testRejectsUnknownType(): void {
        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->extras('person', 1)->getStatus());
    }

    public function testInvalidLanguageFallsBackToEnglish(): void {
        $this->language('<script>');
        $this->extras = $this->createMock(TmdbExtrasService::class);
        $this->extras->method('hasApiKey')->willReturn(true);
        $this->extras->expects($this->once())->method('get')
            ->with('movie', 1, 'alice', 'en-US')->willReturn(['trailer' => null, 'watch' => []]);

        $this->controller()->extras('movie', 1);
    }

    public function testTmdbErrorIsBadGateway(): void {
        $this->language('en-US');
        $this->extras->method('hasApiKey')->willReturn(true);
        $this->extras->method('get')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller()->extras('movie', 1);

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertSame('tmdb_error', $response->getData()['reason']);
    }
}
