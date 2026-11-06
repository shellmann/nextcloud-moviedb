<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\WatchlistItem;
use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;

/** Query shape of the calendar queries: library scope, inclusive window, no stopped shows. */
class CalendarQueriesTest extends TestCase {
    private SqlRecording $rec;
    private IDBConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->rec = new SqlRecording();
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));
    }

    private function paramFor(string $sql, string $column, string $op): mixed {
        $this->assertMatchesRegularExpression('/' . preg_quote($column . ' ' . $op, '/') . ' (:p\d+)/', $sql);
        preg_match('/' . preg_quote($column . ' ' . $op, '/') . ' (:p\d+)/', $sql, $m);
        return $this->rec->params[$m[1]];
    }

    public function testEpisodesAiringBetweenIsScopedToLibraryWindowAndActiveShows(): void {
        $this->rec->resultSets = [[]];
        (new EpisodeMapper($this->db))->airingBetween(7, '2026-10-01', '2027-05-01');

        $sql = $this->rec->executed[0];
        $this->assertStringContainsString('INNER JOIN moviedb_series s ON e.series_id = s.id', $sql);
        $this->assertSame(7, $this->paramFor($sql, 's.library_id', '='));
        $this->assertSame('2026-10-01', $this->paramFor($sql, 'e.air_date', '>='));
        $this->assertSame('2027-05-01', $this->paramFor($sql, 'e.air_date', '<='));
        $this->assertSame(0, $this->paramFor($sql, 'e.season_number', '>'));
        $this->assertStringContainsString('e.air_date IS NOT NULL', $sql);
        $this->assertStringContainsString('s.stopped_watching IS NULL', $sql);
        $this->assertFalse($this->paramFor($sql, 's.stopped_watching', '='));
        $this->assertStringNotContainsString('SELECT *', $sql);
        $this->assertStringContainsString('ORDER BY e.air_date ASC', $sql);
    }

    public function testWatchlistReleasingBetweenIsScopedToLibraryAndWindow(): void {
        $rec = $this->rec;
        $mapper = new class ($this->db, $rec) extends WatchlistMapper {
            public function __construct(IDBConnection $db, private SqlRecording $rec) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                $this->rec->executed[] = $qb->getSQL();
                return [];
            }
        };
        $mapper->releasingBetween(3, '2026-10-01', '2027-05-01');

        $sql = $this->rec->executed[0];
        $this->assertSame(3, $this->paramFor($sql, 'library_id', '='));
        $this->assertSame('2026-10-01', $this->paramFor($sql, 'release_date', '>='));
        $this->assertSame('2027-05-01', $this->paramFor($sql, 'release_date', '<='));
        $this->assertStringContainsString('release_date IS NOT NULL', $sql);
    }

    public function testEpisodesToNotifyAreRecentUnnotifiedRegularEpisodesOfActiveShowsAddedBeforeAiring(): void {
        $this->rec->resultSets = [[]];
        (new EpisodeMapper($this->db))->findToNotify('2026-11-04', '2026-11-06');

        $sql = $this->rec->executed[0];
        $this->assertStringContainsString('e.notified_at IS NULL', $sql);
        $this->assertSame('2026-11-04', $this->paramFor($sql, 'e.air_date', '>='));
        $this->assertSame('2026-11-06', $this->paramFor($sql, 'e.air_date', '<='));
        $this->assertSame(0, $this->paramFor($sql, 'e.season_number', '>'));
        // the "added before it aired" rule runs in PHP: PostgreSQL cannot compare a timestamp with a string
        $this->assertStringNotContainsString('s.created_at < e.air_date', $sql);
        $this->assertStringContainsString('s.stopped_watching IS NULL', $sql);
    }

    public function testWatchlistToNotifyAreRecentUnnotifiedItemsAddedBeforeTheirRelease(): void {
        $rec = $this->rec;
        $mapper = new class ($this->db, $rec) extends WatchlistMapper {
            public function __construct(IDBConnection $db, private SqlRecording $rec) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                $this->rec->executed[] = $qb->getSQL();
                return [];
            }
        };
        $mapper->findToNotify('2026-11-04', '2026-11-06');

        $sql = $this->rec->executed[0];
        $this->assertStringContainsString('release_notified_at IS NULL', $sql);
        $this->assertSame('2026-11-04', $this->paramFor($sql, 'release_date', '>='));
        $this->assertSame('2026-11-06', $this->paramFor($sql, 'release_date', '<='));
        // the "added before release" rule runs in PHP, not SQL (PostgreSQL cannot compare timestamp and string)
        $this->assertStringNotContainsString('added_at < release_date', $sql);
    }

    public function testMarkingNotifiedUpdatesOnlyTheGivenIdsInChunks(): void {
        (new EpisodeMapper($this->db))->markNotified(range(1, 1200), '2026-11-06 10:00:00');

        $this->assertCount(3, $this->rec->executed);
        $this->assertStringContainsString('UPDATE moviedb_episodes SET notified_at =', $this->rec->executed[0]);
        $this->assertStringContainsString('id IN', $this->rec->executed[0]);
    }
    public function testEpisodesToNotifyKeepOnlyShowsAddedBeforeTheEpisodeAired(): void {
        $row = fn (int $id, string $created, string $airDate) => ['id' => $id, 'air_date' => $airDate, 'series_created_at' => $created];
        $this->rec->resultSets = [[
            $row(1, '2026-11-01 10:00:00', '2026-11-06'),
            $row(2, '2026-11-06 08:30:00', '2026-11-06'), // added the day it aired: no backlog
            $row(3, '2026-11-07 08:30:00', '2026-11-06'),
        ]];

        $rows = (new EpisodeMapper($this->db))->findToNotify('2026-11-04', '2026-11-06');

        $this->assertSame([1], array_column($rows, 'id'));
    }

    public function testWatchlistToNotifyKeepOnlyItemsAddedBeforeTheirRelease(): void {
        $item = function (int $id, string $added, string $release): WatchlistItem {
            $i = new WatchlistItem();
            $i->setId($id);
            $i->setAddedAt($added);
            $i->setReleaseDate($release);
            return $i;
        };
        $items = [
            $item(1, '2026-11-01 10:00:00', '2026-11-06'),
            $item(2, '2026-11-06 09:00:00', '2026-11-06'),
        ];
        $mapper = new class ($this->db, $items) extends WatchlistMapper {
            public function __construct(IDBConnection $db, private array $items) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                return $this->items;
            }
        };

        $this->assertSame([1], array_map(fn ($i) => $i->getId(), $mapper->findToNotify('2026-11-04', '2026-11-06')));
    }
}
