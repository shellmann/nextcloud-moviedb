<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Service\WhatsNewService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;

class WhatsNewServiceTest extends TestCase {
    private IUserConfig&MockObject $userConfig;
    private LibraryMapper&MockObject $libraryMapper;
    private WhatsNewService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppVersion')->with('moviedb')->willReturn('1.9.0');
        $this->service = new WhatsNewService($this->userConfig, $appManager, $this->libraryMapper);
    }

    private function givenStored(string $version): void {
        $this->userConfig->method('getValueString')
            ->with('alice', 'moviedb', 'last_seen_version', '')
            ->willReturn($version);
    }

    public function testReturnsTheStoredVersion(): void {
        $this->givenStored('1.9.0');
        $this->libraryMapper->expects($this->never())->method('findAccessible');
        $this->userConfig->expects($this->never())->method('setValueString');

        $this->assertSame(['currentVersion' => '1.9.0', 'lastSeenVersion' => '1.9.0'], $this->service->state('alice'));
    }

    public function testExistingUserWithoutRecordStartsFromTheBaseline(): void {
        $this->givenStored('');
        $this->libraryMapper->method('findAccessible')->with('alice')->willReturn([new Library()]);
        // Stored right away, so the library lookup does not repeat on every visit until the dialog is closed.
        $this->userConfig->expects($this->once())->method('setValueString')
            ->with('alice', 'moviedb', 'last_seen_version', WhatsNewService::BASELINE_VERSION);

        $this->assertSame(WhatsNewService::BASELINE_VERSION, $this->service->state('alice')['lastSeenVersion']);
    }

    public function testNewUserHasNothingToCatchUpOnAndIsRemembered(): void {
        $this->givenStored('');
        $this->libraryMapper->method('findAccessible')->willReturn([]);
        $this->userConfig->expects($this->once())->method('setValueString')
            ->with('alice', 'moviedb', 'last_seen_version', '1.9.0');

        $this->assertSame('1.9.0', $this->service->state('alice')['lastSeenVersion']);
    }

    /**
     * @dataProvider versionProvider
     */
    public function testValidatesVersions(string $version, bool $valid): void {
        $this->assertSame($valid, WhatsNewService::isValidVersion($version));
    }

    public static function versionProvider(): array {
        return [
            ['1.9.0', true],
            ['1.10.12', true],
            ['1.6.0-beta.1', true],
            ['1.9', false],
            ['v1.9.0', false],
            ['1.9.0<script>', false],
            ['', false],
            [str_repeat('1', 30) . '.0.0', false],
        ];
    }
}
