<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Db\SeriesMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;

/**
 * The language flag on movie and TV show cards comes from last_language in the
 * list query. 1.2.0 moved the language to the watches table and the list query
 * stopped returning it, so the flag vanished from every card without a failing
 * test. This guards the query shape.
 */
class LastLanguageQueriesTest extends TestCase {
    private SqlRecording $rec;
    private IDBConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->rec = new SqlRecording();
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));
        $this->db->method('escapeLikeParameter')->willReturnArgument(0);
    }

    public function testMovieListReturnsTheLanguageOfTheLatestWatch(): void {
        $rec = $this->rec;
        // QBMapper::findEntities is a stub that runs nothing, so capture the list query there.
        $mapper = new class ($this->db, $rec) extends MovieMapper {
            public function __construct(IDBConnection $db, private SqlRecording $rec) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                $this->rec->executed[] = $qb->getSQL();
                return [];
            }
        };

        $mapper->findAll(7);
        $sql = end($this->rec->executed);

        $this->assertMatchesRegularExpression(
            '/\(SELECT wl\.language_watched FROM \S*moviedb_movie_watches\S* wl WHERE .*wl\.movie_id = m\.id.*ORDER BY wl\.watched_at DESC, wl\.id DESC LIMIT 1\) AS last_language/',
            $sql
        );
        $this->assertStringContainsString('wl.library_id = :lw_library_id', $sql);
    }

    public function testSeriesListReturnsTheLanguageOfTheShowsOwnWatchRow(): void {
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

        $mapper->findAll(7);
        $sql = end($this->rec->executed);

        $this->assertMatchesRegularExpression(
            '/\(SELECT wl\.language_watched FROM \S*moviedb_movie_watches\S* wl WHERE .*wl\.series_id = m\.id.*\) AS last_language/',
            $sql
        );
        $this->assertStringContainsString('wl.library_id = :lw_series_library_id', $sql);
        $this->assertMatchesRegularExpression('/wl\.episode_id IS NULL/', $sql);
    }
}
