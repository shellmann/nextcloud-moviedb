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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;

class SettingsControllerTest extends TestCase {
    private IRequest $request;
    private IUserConfig $userConfig;
    private SettingsService $settingsService;
    private TmdbService $tmdbService;
    private IUserSession&Stub $userSession;
    private ?SettingsController $controller = null;

    protected function setUp(): void {
        parent::setUp();

        $this->request = $this->createStub(IRequest::class);
        $this->userConfig = $this->createStub(IUserConfig::class);
        $this->settingsService = $this->createStub(SettingsService::class);
        $this->tmdbService = $this->createStub(TmdbService::class);

        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession = $this->createStub(IUserSession::class);
        $this->userSession->method('getUser')->willReturn($user);
    }

    private function controller(): SettingsController {
        return $this->controller ??= new SettingsController(
            $this->request,
            $this->userConfig,
            $this->userSession,
            $this->settingsService,
            $this->tmdbService
        );
    }

    public function testGetReturnsSettingsFromService(): void {
        $this->settingsService = $this->createMock(SettingsService::class);
        $settings = ['hasApiKey' => true, 'hasUserApiKey' => false, 'hasInstanceApiKey' => true];
        $this->settingsService->method('getUserSettings')->with('alice')->willReturn($settings);

        $response = $this->controller()->get();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($settings, $response->getData());
    }

    public function testSavesVerifiedKeyAsSensitive(): void {
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->with('new-key')->willReturn(true);

        $this->userConfig->expects($this->once())
            ->method('setValueString')
            ->with('alice', 'moviedb', 'tmdb_api_key', 'new-key', false, IUserConfig::FLAG_SENSITIVE);

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public function testEmptyKeyRemovesItWithoutAskingTmdb(): void {
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => '']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->once())
            ->method('deleteUserConfig')
            ->with('alice', 'moviedb', 'tmdb_api_key');

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public function testLanguageOnlyDoesNotTouchKey(): void {
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['defaultLanguage' => 'en-US']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->never())->method('deleteUserConfig');
        $this->userConfig->expects($this->once())
            ->method('setValueString')
            ->with('alice', 'moviedb', 'default_language', 'en-US');

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public function testInvalidKeyFormatIsRejected(): void {
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'not a key']);
        $this->tmdbService->expects($this->never())->method('verifyApiKey');
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    public function testBadLanguageWithValidKeyWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'tmdbApiKey' => 'new-key',
            'defaultLanguage' => 'not-a-language',
        ]);
        $this->tmdbService->method('verifyApiKey')->willReturn(true);
        $this->userConfig->expects($this->never())->method('setValueString');
        $this->userConfig->expects($this->never())->method('deleteUserConfig');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    public function testBadAppLanguageWithValidLanguageWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'defaultLanguage' => 'en-US',
            'appLanguage' => 'nope!',
        ]);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    #[DataProvider('continueWatchingProvider')]
    public function testSavesContinueWatchingAsString(mixed $value, string $stored): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['showContinueWatching' => $value]);
        $this->userConfig->expects($this->once())->method('setValueString')
            ->with('alice', 'moviedb', 'show_continue_watching', $stored);

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public static function continueWatchingProvider(): array {
        return [[true, '1'], ['true', '1'], ['1', '1'], [false, '0'], ['false', '0'], ['0', '0']];
    }

    public function testBadContinueWatchingValueWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'defaultLanguage' => 'en-US',
            'showContinueWatching' => 'maybe',
        ]);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    #[DataProvider('continueWatchingProvider')]
    public function testSavesComingUpAsString(mixed $value, string $stored): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['showComingUp' => $value]);
        $this->userConfig->expects($this->once())->method('setValueString')
            ->with('alice', 'moviedb', 'show_coming_up', $stored);

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public function testBadComingUpValueWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'defaultLanguage' => 'en-US',
            'showComingUp' => 'maybe',
        ]);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    #[DataProvider('continueWatchingProvider')]
    public function testSavesNotifyReleasesAndShowCalendarAsStrings(mixed $value, string $stored): void {
        $this->request->method('getParams')->willReturn(['notifyReleases' => $value, 'showCalendar' => $value]);
        $written = [];
        $this->userConfig->method('setValueString')->willReturnCallback(function ($user, $app, $key, $v) use (&$written) {
            $written[$key] = $v;
            return true;
        });

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
        $this->assertSame(['notify_releases' => $stored, 'show_calendar' => $stored], $written);
    }

    public function testBadNotificationValueWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['defaultLanguage' => 'en-US', 'showCalendar' => 'maybe']);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    public function testSavesBothDashboardSwitchesTogether(): void {
        $this->request->method('getParams')->willReturn(['showContinueWatching' => false, 'showComingUp' => true]);
        $written = [];
        $this->userConfig->method('setValueString')->willReturnCallback(function ($user, $app, $key, $value) use (&$written) {
            $written[$key] = $value;
            return true;
        });

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
        $this->assertSame(['show_continue_watching' => '0', 'show_coming_up' => '1'], $written);
    }

    public function testSavesTheLastSeenVersion(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['lastSeenVersion' => '1.9.0']);
        $this->userConfig->expects($this->once())->method('setValueString')
            ->with('alice', 'moviedb', 'last_seen_version', '1.9.0');

        $this->assertSame(Http::STATUS_OK, $this->controller()->update()->getStatus());
    }

    public function testBadLastSeenVersionWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'defaultLanguage' => 'en-US',
            'lastSeenVersion' => ['1.9.0'],
        ]);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update()->getStatus());
    }

    public function testKeyRejectedByTmdbWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn([
            'tmdbApiKey' => 'wrong-key',
            'defaultLanguage' => 'en-US',
        ]);
        $this->tmdbService->method('verifyApiKey')->willReturn(false);
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->update()->getStatus());
    }

    public function testTmdbUnreachableWritesNothing(): void {
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->request->method('getParams')->willReturn(['tmdbApiKey' => 'new-key']);
        $this->tmdbService->method('verifyApiKey')->willThrowException(new \RuntimeException('Could not reach TMDB'));
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller()->update()->getStatus());
    }
}
