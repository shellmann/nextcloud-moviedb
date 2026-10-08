<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\SettingsService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\Config\IUserConfig;
use OCP\IGroupManager;

class SettingsServiceTest extends TestCase {
    private IUserConfig $userConfig;
    private TmdbService $tmdbService;
    private IGroupManager $groupManager;
    private SettingsService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->tmdbService = $this->createMock(TmdbService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new SettingsService($this->userConfig, $this->tmdbService, $this->groupManager);
    }

    private function givenUserKey(string $userKey): void {
        $this->userConfig->method('getValueString')->willReturnCallback(
            fn (string $userId, string $app, string $key, string $default) => $key === 'tmdb_api_key' ? $userKey : $default
        );
    }

    /**
     * @dataProvider keyProvider
     */
    public function testKeyFlags(string $userKey, bool $instanceKey, bool $hasApiKey, bool $hasUserApiKey): void {
        $this->givenUserKey($userKey);
        $this->tmdbService->method('hasInstanceApiKey')->willReturn($instanceKey);

        $settings = $this->service->getUserSettings('alice');

        $this->assertSame($hasApiKey, $settings['hasApiKey']);
        $this->assertSame($hasUserApiKey, $settings['hasUserApiKey']);
        $this->assertSame($instanceKey, $settings['hasInstanceApiKey']);
    }

    public static function keyProvider(): array {
        return [
            'no key' => ['', false, false, false],
            'personal key only' => ['user-key', false, true, true],
            'instance key only' => ['', true, true, false],
            'both keys' => ['user-key', true, true, true],
        ];
    }

    public function testKeyIsMasked(): void {
        $this->givenUserKey('user-key');

        $settings = $this->service->getUserSettings('alice');

        $this->assertSame('••••••••', $settings['tmdbApiKey']);
        $this->assertNotContains('user-key', $settings);
    }

    public function testLanguagesUseDefaults(): void {
        $this->givenUserKey('');

        $settings = $this->service->getUserSettings('alice');

        $this->assertSame('de-DE', $settings['defaultLanguage']);
        $this->assertSame('auto', $settings['appLanguage']);
    }

    public function testIsAdmin(): void {
        $this->givenUserKey('');
        $this->groupManager->method('isAdmin')->with('alice')->willReturn(true);

        $this->assertTrue($this->service->getUserSettings('alice')['isAdmin']);
    }
}
