<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\BackgroundJob;

use OCA\MovieDB\BackgroundJob\RefreshSeriesJob;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesRefreshService;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class RefreshSeriesJobTest extends TestCase {
    private SeriesMapper&MockObject $seriesMapper;
    private LibraryMapper&MockObject $libraryMapper;
    private SeriesRefreshService&MockObject $refreshService;
    private TmdbService&MockObject $tmdb;
    private IUserConfig&MockObject $userConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->libraryMapper = $this->createMock(LibraryMapper::class);
        $this->refreshService = $this->createMock(SeriesRefreshService::class);
        $this->tmdb = $this->createMock(TmdbService::class);
        $this->userConfig = $this->createMock(IUserConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function job(): RefreshSeriesJob {
        return new class (
            $this->createMock(ITimeFactory::class),
            $this->seriesMapper,
            $this->libraryMapper,
            $this->refreshService,
            $this->tmdb,
            $this->userConfig,
            $this->logger,
        ) extends RefreshSeriesJob {
            protected function pause(): void {
            }
        };
    }

    private function series(int $id, int $libraryId = 1): Series {
        $s = new Series();
        $s->setId($id);
        $s->setLibraryId($libraryId);
        $s->setUserId('creator');
        $s->setTmdbId(100 + $id);
        return $s;
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

    public function testAsksForOngoingShowsAfterTwentyThreeHoursAndEndedOnesAfterThirtyDays(): void {
        $this->seriesMapper->expects($this->once())->method('findDueForRefresh')
            ->with(500, $this->callback(function (string $ongoing): bool {
                $age = time() - strtotime($ongoing);
                return $age > 22.9 * 3600 && $age < 23.1 * 3600;
            }), $this->callback(function (string $ended): bool {
                $age = time() - strtotime($ended);
                return $age > 29.9 * 86400 && $age < 30.1 * 86400;
            }))
            ->willReturn([]);

        $this->job()->runNow();
    }

    public function testRefreshesWithTheLibraryOwnersKeyAndLanguage(): void {
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$this->series(1, 9)]);
        $this->libraryMapper->method('find')->with(9)->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->with('owner')->willReturn(true);
        $this->userConfig->method('getValueString')->with('owner', 'moviedb', 'default_language', 'de-DE')->willReturn('fr-FR');
        $this->refreshService->expects($this->once())->method('refresh')
            ->with($this->isInstanceOf(Series::class), 'owner', 'fr-FR')
            ->willReturn(['newEpisodes' => 0, 'updatedEpisodes' => 0, 'newSeasons' => 0]);

        $this->job()->runNow();
    }

    public function testLooksUpEachLibraryOwnerOnce(): void {
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$this->series(1, 9), $this->series(2, 9)]);
        $this->libraryMapper->expects($this->once())->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(true);
        $this->refreshService->expects($this->exactly(2))->method('refresh');

        $this->job()->runNow();
    }

    public function testFallsBackToTheCreatorWhenTheLibraryIsGone(): void {
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$this->series(1, 9)]);
        $this->libraryMapper->method('find')->willThrowException(new DoesNotExistException('x'));
        $this->tmdb->method('hasApiKey')->with('creator')->willReturn(true);
        $this->refreshService->expects($this->once())->method('refresh')->with($this->anything(), 'creator', $this->anything());

        $this->job()->runNow();
    }

    public function testSkipsShowsWithoutAnyKeyButStampsThemSoTheyDoNotFillTheBatch(): void {
        $show = $this->series(1);
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$show]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(false);
        $this->refreshService->expects($this->never())->method('refresh');
        $this->refreshService->expects($this->once())->method('markRefreshed')->with($show);

        $this->job()->runNow();
    }

    public function testOneFailingShowDoesNotStopTheBatchAndIsMarked(): void {
        $first = $this->series(1);
        $second = $this->series(2);
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$first, $second]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(true);
        $calls = [];
        $this->refreshService->method('refresh')->willReturnCallback(function (Series $s) use (&$calls) {
            $calls[] = $s->getId();
            if ($s->getId() === 1) {
                throw new \RuntimeException('404', 404);
            }
            return ['newEpisodes' => 0, 'updatedEpisodes' => 0, 'newSeasons' => 0];
        });
        $this->refreshService->expects($this->once())->method('markRefreshed')->with($first);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->job()->runNow();

        $this->assertSame([1, 2], $calls);
    }

    public function testStopsWhenTmdbAsksToSlowDownAndLeavesTheRestForTheNextRun(): void {
        $this->seriesMapper->method('findDueForRefresh')->willReturn([$this->series(1), $this->series(2)]);
        $this->libraryMapper->method('find')->willReturn($this->library('owner'));
        $this->tmdb->method('hasApiKey')->willReturn(true);
        $this->refreshService->expects($this->once())->method('refresh')
            ->willThrowException(new \RuntimeException('too many', 429));
        $this->refreshService->expects($this->never())->method('markRefreshed');

        $this->job()->runNow();
    }
}
