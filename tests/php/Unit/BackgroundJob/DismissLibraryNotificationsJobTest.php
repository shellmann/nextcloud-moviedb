<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\BackgroundJob;

use OCA\MovieDB\BackgroundJob\DismissLibraryNotificationsJob;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

class DismissLibraryNotificationsJobTest extends TestCase {
    private function job(ReleaseNotificationService $service): DismissLibraryNotificationsJob {
        return new DismissLibraryNotificationsJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
    }

    public function testDismissesTheLibraryNotificationsOfTheFormerMember(): void {
        $service = $this->createMock(ReleaseNotificationService::class);
        $service->expects($this->once())->method('dismissLibrary')->with(5, 'bob');

        $this->job($service)->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testIgnoresIncompleteArguments(): void {
        $service = $this->createMock(ReleaseNotificationService::class);
        $service->expects($this->never())->method('dismissLibrary');

        $this->job($service)->runNow(['libraryId' => 5]);
        $this->job($service)->runNow([]);
    }

    public function testAFailureIsLoggedNotThrown(): void {
        $service = $this->createMock(ReleaseNotificationService::class);
        $service->method('dismissLibrary')->willThrowException(new \RuntimeException('boom'));

        $this->job($service)->runNow(['libraryId' => 5, 'userId' => 'bob']);
        $this->addToAssertionCount(1);
    }
}
