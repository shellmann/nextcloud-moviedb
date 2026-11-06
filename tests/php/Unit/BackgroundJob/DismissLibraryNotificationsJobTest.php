<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\BackgroundJob;

use OCA\MovieDB\BackgroundJob\DismissLibraryNotificationsJob;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

class DismissLibraryNotificationsJobTest extends TestCase {
    private ReleaseNotificationService $service;
    private LibraryMemberMapper $members;
    private IJobList $jobList;
    private LoggerInterface $logger;

    protected function setUp(): void {
        parent::setUp();
        $this->service = $this->createMock(ReleaseNotificationService::class);
        $this->members = $this->createMock(LibraryMemberMapper::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function job(): DismissLibraryNotificationsJob {
        return new DismissLibraryNotificationsJob($this->createMock(ITimeFactory::class), $this->service, $this->members, $this->jobList, $this->logger);
    }

    public function testDismissesTheLibraryNotificationsOfTheFormerMember(): void {
        $this->members->method('findMembership')->willReturn(null);
        $this->service->expects($this->once())->method('dismissLibrary')->with(5, 'bob', true, $this->isInstanceOf(\Closure::class));

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testDoesNothingWhenTheUserIsAMemberAgain(): void {
        $this->members->method('findMembership')->willReturn(new LibraryMember());
        $this->service->expects($this->never())->method('dismissLibrary');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testIgnoresIncompleteArguments(): void {
        $this->service->expects($this->never())->method('dismissLibrary');

        $this->job()->runNow(['libraryId' => 5]);
        $this->job()->runNow([]);
    }

    public function testAFailureRequeuesTheJobAndGivesUpAfterThreeTries(): void {
        $this->members->method('findMembership')->willReturn(null);
        $this->service->method('dismissLibrary')->willThrowException(new \RuntimeException('boom'));
        $queued = [];
        $this->jobList->method('add')->willReturnCallback(function (string $class, array $arg) use (&$queued): void {
            $queued[] = $arg['attempt'];
        });
        $this->logger->expects($this->once())->method('warning');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob', 'attempt' => 1]);
        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob', 'attempt' => 2]);

        $this->assertSame([1, 2], $queued);
    }
}
