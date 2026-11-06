<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Calendar;

use OCA\MovieDB\Calendar\UpcomingEventSource;
use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\Library;
use OCA\MovieDB\Db\LibraryMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;

class UpcomingEventSourceTest extends TestCase {
    private LibraryMapper&MockObject $libraries;
    private EpisodeMapper&MockObject $episodes;
    private WatchlistMapper&MockObject $watchlist;
    private UpcomingEventSource $source;
    private IL10N $l;

    protected function setUp(): void {
        parent::setUp();
        $this->libraries = $this->createMock(LibraryMapper::class);
        $this->episodes = $this->createMock(EpisodeMapper::class);
        $this->watchlist = $this->createMock(WatchlistMapper::class);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/moviedb/');
        $this->source = new UpcomingEventSource($this->libraries, $this->episodes, $this->watchlist, $url);
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnCallback(fn (string $t, $p = []) => vsprintf($t, $p));
    }

    private function library(int $id, string $name): Library {
        $lib = new Library();
        $lib->setId($id);
        $lib->setName($name);
        return $lib;
    }

    private function episodeRow(array $extra = []): array {
        return $extra + [
            'id' => 21, 'series_id' => 1, 'season_number' => 3, 'episode_number' => 2, 'name' => 'Hello',
            'air_date' => '2026-11-16', 'created_at' => '2026-10-01 10:00:00', 'series_title' => 'Severance',
            'series_tmdb_id' => 95396, 'series_refreshed_at' => '2026-11-05 03:00:00', 'series_created_at' => '2026-09-01 09:00:00',
        ];
    }

    private function item(int $id, ?int $tmdb, string $type, string $date): WatchlistItem {
        $i = new WatchlistItem();
        $i->setId($id);
        $i->setTmdbId($tmdb);
        $i->setMediaType($type);
        $i->setTitle('Dune 3');
        $i->setReleaseDate($date);
        $i->setAddedAt('2026-10-10 10:00:00');
        return $i;
    }

    public function testBuildsEpisodeAndWatchlistEventsSortedByDate(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal')]);
        $this->episodes->method('airingBetween')->willReturn([$this->episodeRow()]);
        $this->watchlist->method('releasingBetween')->willReturn([$this->item(5, 438631, 'movie', '2026-11-10')]);

        $events = $this->source->eventsFor('alice', $this->l, '2026-11-06');

        $this->assertCount(2, $events);
        $this->assertSame('moviedb-wl-movie-438631', $events[0]['uid']);
        $this->assertSame('Dune 3 (release)', $events[0]['summary']);
        $this->assertSame('https://nc.example/apps/moviedb/#/watchlist?library=1&item=5', $events[0]['url']);
        $this->assertSame('moviedb-ep-95396-s3e2', $events[1]['uid']);
        $this->assertSame('Severance S03E02 · Hello', $events[1]['summary']);
        $this->assertSame('2026-11-16', $events[1]['date']);
        $this->assertSame('Personal', $events[1]['description']);
        $this->assertSame('https://nc.example/apps/moviedb/#/tv/1?library=1', $events[1]['url']);
    }

    public function testLastModifiedIsStableAndNeverNow(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal')]);
        $this->episodes->method('airingBetween')->willReturn([$this->episodeRow(), $this->episodeRow(['id' => 22, 'episode_number' => 3, 'series_refreshed_at' => null])]);
        $this->watchlist->method('releasingBetween')->willReturn([]);

        $events = $this->source->eventsFor('alice', $this->l, '2026-11-06');

        $this->assertSame('2026-11-05 03:00:00', $events[0]['lastModified']);
        $this->assertSame('2026-09-01 09:00:00', $events[1]['lastModified']);
    }

    public function testTheSameTitleInTwoLibrariesIsOneEvent(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal'), $this->library(2, 'Family')]);
        $this->episodes->method('airingBetween')->willReturn([$this->episodeRow()]);
        $this->watchlist->method('releasingBetween')->willReturn([$this->item(5, 438631, 'movie', '2026-11-10')]);

        $events = $this->source->eventsFor('alice', $this->l, '2026-11-06');

        $this->assertCount(2, $events);
        $this->assertSame('Personal', $events[1]['description']);
    }

    public function testWithoutTmdbIdsLocalIdsKeepTheUidsUnique(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal')]);
        $this->episodes->method('airingBetween')->willReturn([$this->episodeRow(['series_tmdb_id' => null])]);
        $this->watchlist->method('releasingBetween')->willReturn([$this->item(9, null, 'series', '2026-12-01')]);

        $uids = array_column($this->source->eventsFor('alice', $this->l, '2026-11-06'), 'uid');

        $this->assertSame(['moviedb-ep-local-21', 'moviedb-wl-local-9'], $uids);
    }

    public function testAsksForTheWindowAroundToday(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(4, 'Personal')]);
        $this->episodes->expects($this->once())->method('airingBetween')->with(4, '2026-10-07', '2027-05-05')->willReturn([]);
        $this->watchlist->expects($this->once())->method('releasingBetween')->with(4, '2026-10-07', '2027-05-05')->willReturn([]);

        $this->assertSame([], $this->source->eventsFor('alice', $this->l, '2026-11-06'));
    }

    public function testEpisodeWithoutNameStillHasASummary(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal')]);
        $this->episodes->method('airingBetween')->willReturn([$this->episodeRow(['name' => ' '])]);
        $this->watchlist->method('releasingBetween')->willReturn([]);

        $this->assertSame('Severance S03E02', $this->source->eventsFor('alice', $this->l, '2026-11-06')[0]['summary']);
    }

    public function testAWindowNarrowsTheQueriesButNeverWidensThem(): void {
        $this->libraries->method('findAccessible')->willReturn([$this->library(1, 'Personal')]);
        $seen = [];
        $this->episodes->method('airingBetween')->willReturnCallback(function (int $lib, string $from, string $until) use (&$seen) {
            $seen[] = [$from, $until];
            return [];
        });
        $this->watchlist->method('releasingBetween')->willReturn([]);

        $this->source->eventsFor('alice', $this->l, '2026-11-06', '2026-11-10', '2026-11-20');
        $this->source->eventsFor('alice', $this->l, '2026-11-06', '2000-01-01', '2100-01-01');

        $this->assertSame([['2026-11-10', '2026-11-20'], ['2026-10-07', '2027-05-05']], $seen);
    }
}
