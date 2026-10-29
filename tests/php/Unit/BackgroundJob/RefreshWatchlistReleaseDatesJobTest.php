<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\BackgroundJob;

use OCA\MovieDB\BackgroundJob\RefreshWatchlistReleaseDatesJob;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Service\WatchlistReleaseService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class RefreshWatchlistReleaseDatesJobTest extends TestCase {
    private WatchlistMapper&MockObject $mapper;
    private LibraryMapper&MockObject $libraryMapper;
    private WatchlistReleaseService&MockObject $releaseService;
    private TmdbService&MockObject $tmdb;
    private IUserConfig&MockObject $userConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();
        $this->mapper = $this->createMock(WatchlistMapper::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->releaseService = $this->createMock(WatchlistReleaseService::class);
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function job(): RefreshWatchlistReleaseDatesJob {
        return new class (
            $this->createMock(ITimeFactory::class),
            $this->mapper,
            $this->libraryMapper,
            $this->releaseService,
            $this->tmdb,
            $this->userConfig,
            $this->logger,
        ) extends RefreshWatchlistReleaseDatesJob {
            protected function pause(): void {
            }
        };
    }

    private function item(int $id, int $libraryId = 1): WatchlistItem {
        $i = new WatchlistItem();
        $i->setId($id);
        $i->setLibraryId($libraryId);
        $i->setUserId('creator');
        $i->setTmdbId(100 + $id);
        return $i;
    }

    private function library(string $owner): Library {
        $l = new Library();
        $l->setOwner($owner);
        return $l;
    }

    public function testRunsOnceADayWithoutBeingTimeSensitive(): void {
        $job = $this->job();

        $this->assertSame(24 * 3600, $job->getInterval());
        $this->assertSame(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE, $job->getTimeSensitivity());
    }

    public function testAsksForItemsWithoutDateOrWithADateFromTodayOn(): void {
        $this->mapper->expects($this->once())->method('findDueForReleaseCheck')
            ->with(500, date('Y-m-d'))
            ->willReturn([]);

        $this->job()->runNow();
    }

    public function testChecksWithTheLibraryOwnersKeyAndLanguage(): void {
        $this->mapper->method('findDueForReleaseCheck')->willReturn([$this->item(1, 9)]);
        $this->libraryMapper->method('find')->with(9)->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->with('owner')->willReturn(true);
        $this->userConfig->method('getValueString')->with('owner', 'moviedb', 'default_language', 'de-DE')->willReturn('fr-FR');
        $this->releaseService->expects($this->once())->method('refresh')
            ->with($this->isInstanceOf(WatchlistItem::class), 'owner', 'fr-FR')
            ->willReturn(true);

        $this->job()->runNow();
    }

    public function testFallsBackToTheCreatorWhenTheLibraryIsGone(): void {
        $this->mapper->method('findDueForReleaseCheck')->willReturn([$this->item(1, 9)]);
        $this->libraryMapper->method('find')->willThrowException(new DoesNotExistException('x'));
        $this->tmdb->method('hasApiKey')->with('creator')->willReturn(true);
        $this->releaseService->expects($this->once())->method('refresh')->with($this->anything(), 'creator', $this->anything());

        $this->job()->runNow();
    }

    public function testSkipsItemsWithoutAnyKey(): void {
        $this->mapper->method('findDueForReleaseCheck')->willReturn([$this->item(1)]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(false);
        $this->releaseService->expects($this->never())->method('refresh');

        $this->job()->runNow();
    }

    public function testOneFailingItemDoesNotStopTheBatch(): void {
        $this->mapper->method('findDueForReleaseCheck')->willReturn([$this->item(1), $this->item(2)]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(true);
        $calls = [];
        $this->releaseService->method('refresh')->willReturnCallback(function (WatchlistItem $i) use (&$calls) {
            $calls[] = $i->getId();
            if ($i->getId() === 1) {
                throw new \RuntimeException('gone');
            }
            return false;
        });
        $this->logger->expects($this->once())->method('warning');

        $this->job()->runNow();

        $this->assertSame([1, 2], $calls);
    }

    public function testStopsWhenTmdbAsksToSlowDown(): void {
        $this->mapper->method('findDueForReleaseCheck')->willReturn([$this->item(1), $this->item(2)]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(true);
        $this->releaseService->expects($this->once())->method('refresh')
            ->willThrowException(new \RuntimeException('Too many requests', 429));

        $this->job()->runNow();
    }
}
