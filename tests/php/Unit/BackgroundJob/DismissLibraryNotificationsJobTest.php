<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\BackgroundJob;

use OCA\MovieDB\BackgroundJob\DismissLibraryNotificationsJob;
use OCA\MovieDB\Db\LibraryMember;
use OCA\MovieDB\Db\LibraryMemberMapper;
use OCA\MovieDB\Service\ReleaseNotificationService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class DismissLibraryNotificationsJobTest extends TestCase {
    private ReleaseNotificationService $service;
    private LibraryMemberMapper $members;
    private IJobList $jobList;
    private LoggerInterface $logger;
    private ITimeFactory $clock;

    protected function setUp(): void {
        parent::setUp();
        $this->service = $this->createMock(ReleaseNotificationService::class);
        $this->members = $this->createStub(LibraryMemberMapper::class);
        $this->jobList = $this->createMock(IJobList::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = $this->createStub(ITimeFactory::class);
        $this->clock->method('getTime')->willReturn(1_000_000);
        // pending since shortly before "now" unless a test says otherwise
        $this->service->method('cleanupPendingSince')->willReturn(999_000);
    }

    private function job(): DismissLibraryNotificationsJob {
        return new DismissLibraryNotificationsJob($this->clock, $this->service, $this->members, $this->jobList, $this->logger);
    }

    public function testRunsHourlyUntilItSucceeds(): void {
        $job = $this->job();

        $this->assertSame(3600, $job->getInterval());
        $this->assertSame(IJob::TIME_INSENSITIVE, $job->getTimeSensitivity());
    }

    public function testDismissesTheLibraryNotificationsOfTheFormerMemberAndRemovesItself(): void {
        $this->members->method('findMembership')->willReturn(null);
        $this->service->expects($this->once())->method('dismissLibrary')->with(5, 'bob', true, $this->isInstanceOf(\Closure::class));
        $this->jobList->expects($this->once())->method('remove');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testDoesNothingWhenTheUserIsAMemberAgainButStillRemovesItself(): void {
        $this->members->method('findMembership')->willReturn(new LibraryMember());
        $this->service->expects($this->never())->method('dismissLibrary');
        $this->jobList->expects($this->once())->method('remove');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testIncompleteArgumentsAreDropped(): void {
        $this->service->expects($this->never())->method('dismissLibrary');
        $this->jobList->expects($this->exactly(2))->method('remove');

        $this->job()->runNow(['libraryId' => 5]);
        $this->job()->runNow([]);
    }

    public function testAFailureKeepsTheJobSoTheNextHourlyRunRetries(): void {
        $this->members->method('findMembership')->willReturn(null);
        $this->service->method('dismissLibrary')->willThrowException(new \RuntimeException('db timeout'));
        $this->jobList->expects($this->never())->method('remove');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testItGivesUpAfterThreeDays(): void {
        $this->service = $this->createMock(ReleaseNotificationService::class);
        $this->service->method('cleanupPendingSince')->willReturn(1_000_000 - 4 * 24 * 3600);
        $this->members->method('findMembership')->willReturn(null);
        $this->service->method('dismissLibrary')->willThrowException(new \RuntimeException('db timeout'));
        $this->logger->expects($this->once())->method('warning');
        $this->jobList->expects($this->once())->method('remove');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }

    public function testAJobWhoseCleanupIsAlreadyDoneRemovesItselfWithoutScanning(): void {
        $this->service = $this->createMock(ReleaseNotificationService::class);
        $this->service->method('cleanupPendingSince')->willReturn(null);
        $this->service->expects($this->never())->method('dismissLibrary');
        $this->jobList->expects($this->once())->method('remove');

        $this->job()->runNow(['libraryId' => 5, 'userId' => 'bob']);
    }
}
