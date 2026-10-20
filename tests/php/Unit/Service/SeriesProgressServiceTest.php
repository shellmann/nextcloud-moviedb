<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Db\Episode;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Series;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Service\SeriesProgressService;
use OCA\MovieDB\Tests\Unit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class SeriesProgressServiceTest extends TestCase {

    private SeriesMapper&MockObject $seriesMapper;
    private EpisodeMapper&MockObject $episodeMapper;
    private SeriesProgressService $service;

    private const LIB = 3;

    protected function setUp(): void {
        parent::setUp();
        $this->seriesMapper = $this->createMock(SeriesMapper::class);
        $this->episodeMapper = $this->createMock(EpisodeMapper::class);
        $this->service = new SeriesProgressService($this->seriesMapper, $this->episodeMapper);
    }

    // ---- watchStatus ----

    public function testStoppedWinsOverEverything(): void {
        $this->assertSame('stopped', SeriesProgressService::watchStatus(true, 10, 10));
        $this->assertSame('stopped', SeriesProgressService::watchStatus(true, 0, 0));
    }

    public function testFinishedWhenEveryAiredEpisodeIsWatched(): void {
        $this->assertSame('finished', SeriesProgressService::watchStatus(false, 10, 10));
    }

    public function testNewSeasonMakesAFinishedShowInProgressAgain(): void {
        // 10 of 10 watched, then two new episodes air.
        $this->assertSame('in_progress', SeriesProgressService::watchStatus(false, 10, 12));
    }

    public function testUnstartedAndNotYetAiredShowsAreInProgress(): void {
        $this->assertSame('in_progress', SeriesProgressService::watchStatus(false, 0, 8));
        $this->assertSame('in_progress', SeriesProgressService::watchStatus(false, 0, 0));
    }

    // ---- attachProgress ----

    public function testAttachProgressUsesOneQueryAndAddsFields(): void {
        // Only the series on the page are counted, not the whole library.
        $this->episodeMapper->expects($this->once())->method('progressByLibrary')
            ->with(self::LIB, $this->isType('string'), [1, 2])
            ->willReturn([1 => ['watched' => 3, 'aired' => 4, 'lastWatchedAt' => '2026-10-01 10:00:00']]);

        $rows = $this->service->attachProgress([$this->series(1), $this->series(2)], self::LIB);

        $this->assertSame([3, 4, 75, 'in_progress'], [
            $rows[0]['watchedEpisodeCount'], $rows[0]['airedEpisodeCount'], $rows[0]['progress'], $rows[0]['watchStatus'],
        ]);
        // No episodes have aired for series 2.
        $this->assertSame([0, 0, 0, 'in_progress'], [
            $rows[1]['watchedEpisodeCount'], $rows[1]['airedEpisodeCount'], $rows[1]['progress'], $rows[1]['watchStatus'],
        ]);
        $this->assertSame('Show 1', $rows[0]['title']);
    }

    public function testAttachProgressSkipsTheQueryForAnEmptyList(): void {
        $this->episodeMapper->expects($this->never())->method('progressByLibrary');
        $this->assertSame([], $this->service->attachProgress([], self::LIB));
    }

    // ---- continueWatching ----

    public function testContinueWatchingOrderAndExclusions(): void {
        $this->seriesMapper->method('findAllByLibrary')->willReturn([
            $this->series(1, createdAt: '2026-01-01 00:00:00'),             // started, older activity
            $this->series(2, createdAt: '2026-01-01 00:00:00'),             // started, newest activity
            $this->series(3, createdAt: '2026-03-01 00:00:00'),             // unstarted, newer
            $this->series(4, createdAt: '2026-02-01 00:00:00'),             // unstarted, older
            $this->series(5),                                               // finished
            $this->series(6, stopped: true),                                // stopped
            $this->series(7),                                               // nothing aired yet
        ]);
        $this->episodeMapper->method('progressByLibrary')->willReturn([
            1 => ['watched' => 1, 'aired' => 5, 'lastWatchedAt' => '2026-10-01 10:00:00'],
            2 => ['watched' => 4, 'aired' => 5, 'lastWatchedAt' => '2026-10-10 10:00:00'],
            3 => ['watched' => 0, 'aired' => 5, 'lastWatchedAt' => null],
            4 => ['watched' => 0, 'aired' => 8, 'lastWatchedAt' => null],
            5 => ['watched' => 5, 'aired' => 5, 'lastWatchedAt' => '2026-10-12 10:00:00'],
            6 => ['watched' => 1, 'aired' => 5, 'lastWatchedAt' => '2026-10-13 10:00:00'],
        ]);
        $this->episodeMapper->method('findNextUnwatched')
            ->willReturnCallback(fn (int $seriesId) => $this->episode($seriesId));

        $rows = $this->service->continueWatching(self::LIB, 10);

        $this->assertSame([2, 1, 3, 4], array_column($rows, 'id'));
        $this->assertSame(200, $rows[0]['nextEpisode']['id']);
        $this->assertSame('2026-10-10 10:00:00', $rows[0]['lastActivityAt']);
        $this->assertSame(80, $rows[0]['progress']);
    }

    public function testContinueWatchingAppliesAndClampsTheLimit(): void {
        $series = array_map(fn (int $i) => $this->series($i), range(1, 30));
        $this->seriesMapper->method('findAllByLibrary')->willReturn($series);
        $this->episodeMapper->method('progressByLibrary')->willReturn(
            array_fill_keys(range(1, 30), ['watched' => 0, 'aired' => 3, 'lastWatchedAt' => null])
        );
        $this->episodeMapper->method('findNextUnwatched')->willReturn(null);

        $this->assertCount(2, $this->service->continueWatching(self::LIB, 2));
        $this->assertCount(SeriesProgressService::CONTINUE_LIMIT_MAX, $this->service->continueWatching(self::LIB, 500));
        // A missing or non-positive limit means the default, not "one row".
        $this->assertCount(SeriesProgressService::CONTINUE_LIMIT_DEFAULT, $this->service->continueWatching(self::LIB, 0));
        $this->assertCount(SeriesProgressService::CONTINUE_LIMIT_DEFAULT, $this->service->continueWatching(self::LIB, -3));
    }

    private function series(int $id, bool $stopped = false, string $createdAt = '2026-01-01 00:00:00'): Series {
        $s = new Series();
        $s->setId($id);
        $s->setTitle('Show ' . $id);
        $s->setLibraryId(self::LIB);
        $s->setStoppedWatching($stopped);
        $s->setCreatedAt($createdAt);
        return $s;
    }

    private function episode(int $seriesId): Episode {
        $e = new Episode();
        $e->setId($seriesId * 100);
        $e->setSeriesId($seriesId);
        $e->setSeasonNumber(1);
        $e->setEpisodeNumber(2);
        $e->setName('Next');
        return $e;
    }

    // ---- comingUp ----

    private function in(int $days): string {
        return (new \DateTime())->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function upcoming(int $seriesId, int $season, int $number, string $airDate): Episode {
        $e = new Episode();
        $e->setId($seriesId * 1000 + $season * 100 + $number);
        $e->setSeriesId($seriesId);
        $e->setSeasonNumber($season);
        $e->setEpisodeNumber($number);
        $e->setName('Ep ' . $number);
        $e->setAirDate($airDate);
        return $e;
    }

    public function testComingUpAsksForTheNextThirtyDaysByDefault(): void {
        $this->episodeMapper->expects($this->once())->method('upcomingByLibrary')
            ->with(self::LIB, $this->in(0), $this->in(30))->willReturn([]);

        $this->assertSame([], $this->service->comingUp(self::LIB));
    }

    public function testComingUpClampsTheWindowAndTheLimit(): void {
        $this->episodeMapper->expects($this->once())->method('upcomingByLibrary')
            ->with(self::LIB, $this->in(0), $this->in(90))->willReturn([]);

        $this->service->comingUp(self::LIB, 500, 500);
    }

    public function testComingUpListsCaughtUpShowsSoonestFirst(): void {
        $this->episodeMapper->method('upcomingByLibrary')->willReturn([
            $this->upcoming(1, 2, 1, $this->in(3)),
            $this->upcoming(2, 1, 1, $this->in(10)),
        ]);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$this->series(2), $this->series(1)]);
        $this->episodeMapper->method('progressByLibrary')->willReturn([
            1 => ['watched' => 8, 'aired' => 8, 'lastWatchedAt' => '2026-10-16 10:00:00'],
        ]);

        $rows = $this->service->comingUp(self::LIB);

        $this->assertSame([1, 2], array_column($rows, 'id'));
        $this->assertSame(2, $rows[0]['upcomingEpisode']['seasonNumber']);
        $this->assertSame(1, $rows[0]['sameDayCount']);
        $this->assertSame('finished', $rows[0]['watchStatus']);
        // An unstarted show with a premiere is included too.
        $this->assertSame('in_progress', $rows[1]['watchStatus']);
    }

    public function testComingUpLeavesOutShowsStillInProgressAndStoppedShows(): void {
        $this->episodeMapper->method('upcomingByLibrary')->willReturn([
            $this->upcoming(1, 2, 1, $this->in(3)),
            $this->upcoming(2, 2, 1, $this->in(4)),
            $this->upcoming(3, 2, 1, $this->in(5)),
        ]);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$this->series(1), $this->series(2, true), $this->series(3)]);
        $this->episodeMapper->method('progressByLibrary')->willReturn([
            1 => ['watched' => 3, 'aired' => 8, 'lastWatchedAt' => null],  // episodes left: Continue watching
            2 => ['watched' => 8, 'aired' => 8, 'lastWatchedAt' => null],  // stopped
            3 => ['watched' => 8, 'aired' => 8, 'lastWatchedAt' => null],
        ]);

        $this->assertSame([3], array_column($this->service->comingUp(self::LIB), 'id'));
    }

    public function testComingUpUsesTheFirstEpisodeAndCountsTheSameDay(): void {
        $day = $this->in(7);
        $this->episodeMapper->method('upcomingByLibrary')->willReturn([
            $this->upcoming(1, 3, 1, $day),
            $this->upcoming(1, 3, 2, $day),
            $this->upcoming(1, 3, 3, $day),
            $this->upcoming(1, 3, 4, $this->in(14)),
        ]);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$this->series(1)]);
        $this->episodeMapper->method('progressByLibrary')->willReturn([1 => ['watched' => 8, 'aired' => 8, 'lastWatchedAt' => null]]);

        $rows = $this->service->comingUp(self::LIB);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['upcomingEpisode']['episodeNumber']);
        $this->assertSame(3, $rows[0]['sameDayCount']);
    }

    public function testComingUpSortsTiesByTitleAndHonoursTheLimit(): void {
        $day = $this->in(5);
        $this->episodeMapper->method('upcomingByLibrary')->willReturn([
            $this->upcoming(1, 2, 1, $day), $this->upcoming(2, 2, 1, $day), $this->upcoming(3, 2, 1, $day),
        ]);
        $this->seriesMapper->method('findAllByLibrary')->willReturn([$this->series(3), $this->series(1), $this->series(2)]);
        $this->episodeMapper->method('progressByLibrary')->willReturn([]);

        $this->assertSame([1, 2], array_column($this->service->comingUp(self::LIB, 30, 2), 'id'));
    }
}
