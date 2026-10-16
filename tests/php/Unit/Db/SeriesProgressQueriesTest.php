<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\EpisodeMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;

/**
 * Query shape of the v1.9 progress queries. They must apply exactly the rule
 * of SeriesService::findWithProgress (aired = air_date <= today, season 0
 * excluded) and stay scoped to the library; a dropped condition here would
 * silently skew every progress bar and the status filter.
 */
class SeriesProgressQueriesTest extends TestCase {
    private SqlRecording $rec;
    private IDBConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->rec = new SqlRecording();
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);
    }

    private function paramFor(string $sql, string $column, string $op): mixed {
        $this->assertMatchesRegularExpression('/' . preg_quote($column . ' ' . $op, '/') . ' (:p\d+)/', $sql);
        preg_match('/' . preg_quote($column . ' ' . $op, '/') . ' (:p\d+)/', $sql, $m);
        return $this->rec->params[$m[1]];
    }

    // ---- EpisodeMapper::progressByLibrary ----

    public function testProgressCountsAiredRegularEpisodesOfTheLibrary(): void {
        $this->rec->resultSets = [[], []];
        (new EpisodeMapper($this->db))->progressByLibrary(7, '2026-10-16');

        [$aired, $watched] = $this->rec->executed;
        foreach ([$aired, $watched] as $sql) {
            $this->assertStringContainsString('INNER JOIN moviedb_series s ON e.series_id = s.id', $sql);
            $this->assertSame(7, $this->paramFor($sql, 's.library_id', '='));
            $this->assertStringContainsString('e.air_date IS NOT NULL', $sql);
            $this->assertSame('2026-10-16', $this->paramFor($sql, 'e.air_date', '<='));
            $this->assertSame(0, $this->paramFor($sql, 'e.season_number', '>'));
            $this->assertStringContainsString('GROUP BY e.series_id', $sql);
            $this->assertStringNotContainsString('e.series_id IN', $sql);
        }
        $this->assertStringNotContainsString('e.watched', $aired);
        $this->assertTrue($this->paramFor($watched, 'e.watched', '='));
        $this->assertStringContainsString('MAX(e.updated_at) AS last_watched_at', $watched);
    }

    public function testProgressMergesBothResultSets(): void {
        $this->rec->resultSets = [
            [['series_id' => '1', 'count' => '10'], ['series_id' => '2', 'count' => '4']],
            [['series_id' => '1', 'count' => '3', 'last_watched_at' => '2026-10-01 10:00:00']],
        ];

        $progress = (new EpisodeMapper($this->db))->progressByLibrary(7, '2026-10-16');

        $this->assertSame([
            1 => ['watched' => 3, 'aired' => 10, 'lastWatchedAt' => '2026-10-01 10:00:00'],
            2 => ['watched' => 0, 'aired' => 4, 'lastWatchedAt' => null],
        ], $progress);
    }

    public function testProgressCanBeLimitedToSomeSeries(): void {
        $this->rec->resultSets = [[], []];
        (new EpisodeMapper($this->db))->progressByLibrary(7, '2026-10-16', [3, '5']);

        $this->assertSame(1, preg_match('/e\.series_id IN \((:p\d+)\)/', $this->rec->executed[0], $m));
        $this->assertSame([3, 5], $this->rec->params[$m[1]]);
    }

    public function testProgressForNoSeriesRunsNoQuery(): void {
        $this->assertSame([], (new EpisodeMapper($this->db))->progressByLibrary(7, '2026-10-16', []));
        $this->assertSame([], $this->rec->executed);
    }

    // ---- EpisodeMapper::findNextUnwatched / setWatchedForEpisodes ----

    public function testNextUnwatchedIsTheFirstAiredRegularEpisodeInOrder(): void {
        $this->assertNull((new EpisodeMapper($this->db))->findNextUnwatched(4, '2026-10-16'));
    }

    public function testBulkMarkSetsUpdatedAt(): void {
        (new EpisodeMapper($this->db))->setWatchedForEpisodes([1, 2], true);

        $sql = $this->rec->executed[0];
        $this->assertStringStartsWith('UPDATE moviedb_episodes SET watched = ', $sql);
        $this->assertSame(1, preg_match('/updated_at = (:p\d+)/', $sql, $m));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $this->rec->params[$m[1]]);
    }

    // ---- SeriesMapper status filter ----

    private function countWith(string $status): string {
        $this->rec->resultSets = [[['count' => '0']]];
        (new SeriesMapper($this->db))->countAll(7, ['watchStatus' => $status, 'today' => '2026-10-16']);
        return $this->lastSql();
    }

    public function testStoppedFilterNeedsNoEpisodeCounts(): void {
        $sql = $this->countWith('stopped');

        $this->assertTrue($this->paramFor($sql, 'm.stopped_watching', '='));
        $this->assertStringNotContainsString('moviedb_episodes', $sql);
    }

    public function testFinishedFilterComparesWatchedWithAiredAndSkipsStopped(): void {
        $sql = $this->countWith('finished');

        $this->assertStringContainsString('(m.stopped_watching = ', $sql);
        $this->assertStringContainsString('OR m.stopped_watching IS NULL)', $sql);
        $this->assertStringContainsString('ea.episode_count IS NOT NULL', $sql);
        $this->assertStringContainsString('COALESCE(ew.episode_count, 0) >= ea.episode_count', $sql);
        $this->assertSame('2026-10-16', $this->rec->params[':ws_today']);
        $this->assertTrue($this->rec->params[':ws_watched']);
    }

    public function testInProgressFilterIncludesShowsWithoutAiredEpisodes(): void {
        $sql = $this->countWith('in_progress');

        $this->assertStringContainsString('(ea.episode_count IS NULL OR COALESCE(ew.episode_count, 0) < ea.episode_count)', $sql);
    }

    public function testEpisodeCountSubqueriesUseTheSameAiredRule(): void {
        $sql = $this->countWith('finished');

        // Scoped to the library, then the same aired rule as everywhere else.
        $this->assertSame(2, substr_count($sql, 'FROM moviedb_episodes e INNER JOIN moviedb_series es ON e.series_id = es.id WHERE es.library_id = :ws_library_id AND e.air_date IS NOT NULL AND e.air_date <= :ws_today AND e.season_number > 0'));
        $this->assertSame(7, $this->rec->params[':ws_library_id']);
        $this->assertSame(1, substr_count($sql, 'e.watched = :ws_watched'));
        $this->assertStringContainsString('LEFT JOIN (SELECT e.series_id, COUNT(*) AS episode_count', $sql);
    }

    public function testUnknownStatusMatchesNothing(): void {
        $this->assertStringContainsString('1 = 0', $this->countWith('bogus'));
    }

    public function testListAndCountApplyTheSameStatusFilter(): void {
        // QBMapper::findEntities is a stub that runs nothing, so capture the list query there.
        $rec = $this->rec;
        $mapper = new class ($this->db, $rec) extends SeriesMapper {
            public function __construct(IDBConnection $db, private SqlRecording $rec) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                $this->rec->executed[] = $qb->getSQL();
                return [];
            }
        };

        $mapper->findAll(7, ['watchStatus' => 'finished', 'today' => '2026-10-16'], 24, 0);
        $list = $this->rec->executed[0];
        $count = $this->countWith('finished');

        $this->assertNotSame($list, $count);
        foreach ([$list, $count] as $sql) {
            $this->assertStringContainsString('COALESCE(ew.episode_count, 0) >= ea.episode_count', $sql);
        }
    }

    private function lastSql(): string {
        return end($this->rec->executed) ?: '';
    }
}
