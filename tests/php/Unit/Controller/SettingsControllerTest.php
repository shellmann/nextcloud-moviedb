<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Controller;

use OCA\MovieDB\Controller\SettingsController;
use OCA\MovieDB\Service\SettingsService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Http;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

class SettingsControllerTest extends TestCase {
    private IRequest $request;
    private IUserConfig $userConfig;
    private SettingsService $settingsService;
    private TmdbService $tmdbService;
    private SettingsController $controller;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->tmdbService = $this->createMock(TmdbService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new SettingsController(
            $this->request,
            $this->userConfig,
            $userSession,
            $this->settingsService,
            $this->tmdbService
        );
    }

    public function testGetReturnsSettingsFromService(): void {
        $settings = ['hasApiKey' => true, 'hasUserApiKey' => false, 'hasInstanceApiKey' => true];
        $this->settingsService->method('getUserSettings')->with('alice')->willReturn($settings);

        $response = $this->controller->get();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($settings, $response->getData());
    }

    public function testSavesVerifiedKeyAsSensitive(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->with('new-key')->willReturn(true);

        $this->userConfig->expects($this->once())
            ->method('setValueString')
            ->with('alice', 'moviedb', 'tmdb_api_key', 'new-key', false, IUserConfig::FLAG_SENSITIVE);

        $this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
    }

    public function testEmptyKeyRemovesItWithoutAskingTmdb(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => '']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->once())
            ->method('deleteUserConfig')
            ->with('alice', 'moviedb', 'tmdb_api_key');

        $this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
    }

    public function testLanguageOnlyDoesNotTouchKey(): void {
        $this->request->method('getParams')->willReturn(['defaultLanguage' => 'en-US']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->never())->method('deleteUserConfig');
        $this->userConfig->expects($this->once())
            ->method('setValueString')
            ->with('alice', 'moviedb', 'default_language', 'en-US');

        $this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
    }

    public function testInvalidKeyFormatIsRejected(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'not a key']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
    }

    public function testBadLanguageWithValidKeyWritesNothing(): void {
        $this->request->method('getParams')->willReturn([
            'tmdbApiKey' => 'new-key',
            'defaultLanguage' => 'not-a-language',
        ]);
        $this->tmdbService->method('verifyApiKey')->willReturn(true);
        $this->userConfig->expects($this->never())->method('setValueString');
        $this->userConfig->expects($this->never())->method('deleteUserConfig');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
    }

    public function testBadAppLanguageWithValidLanguageWritesNothing(): void {
        $this->request->method('getParams')->willReturn([
            'defaultLanguage' => 'en-US',
            'appLanguage' => 'nope!',
        ]);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
    }

    public function testKeyRejectedByTmdbWritesNothing(): void {
        $this->request->method('getParams')->willReturn([
            'tmdbApiKey' => 'wrong-key',
            'defaultLanguage' => 'en-US',
        ]);
        $this->tmdbService->method('verifyApiKey')->willReturn(false);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller->update()->getStatus());
    }

    public function testTmdbUnreachableWritesNothing(): void {
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->willThrowException(new \RuntimeException('Could not reach TMDB'));
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller->update()->getStatus());
    }
}
