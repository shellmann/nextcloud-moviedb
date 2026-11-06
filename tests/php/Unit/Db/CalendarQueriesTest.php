<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\EpisodeMapper;
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
}
