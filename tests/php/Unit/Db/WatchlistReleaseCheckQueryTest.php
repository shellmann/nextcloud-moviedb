<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\IDBConnection;

/**
 * Query shape of WatchlistMapper::findDueForReleaseCheck. Released titles
 * must never be asked again, and titles without a TMDB id cannot be asked.
 */
class WatchlistReleaseCheckQueryTest extends TestCase {
    private SqlRecording $rec;

    protected function setUp(): void {
        parent::setUp();
        $this->rec = new SqlRecording();
    }

    private function sql(): string {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));

        $rec = $this->rec;
        $mapper = new class ($db, $rec) extends WatchlistMapper {
            public function __construct(IDBConnection $db, private SqlRecording $rec) {
                parent::__construct($db);
            }

            protected function findEntities($qb): array {
                $this->rec->executed[] = $qb->getSQL();
                return [];
            }
        };
        $mapper->findDueForReleaseCheck(500, '2026-10-29');

        return end($this->rec->executed) ?: '';
    }

    public function testPicksItemsWithoutDateOrWithADateFromTodayOn(): void {
        $sql = $this->sql();

        $this->assertStringContainsString('tmdb_id IS NOT NULL', $sql);
        $this->assertStringContainsString('release_date IS NULL', $sql);
        $this->assertStringContainsString('release_date >= :', $sql);
        $this->assertContains('2026-10-29', $this->rec->params);
    }

    public function testNeverPicksReleasedTitles(): void {
        $this->assertStringNotContainsString('release_date <', $this->sql());
    }

    public function testIsLimitedAndOrderedById(): void {
        $sql = $this->sql();

        $this->assertStringContainsString('ORDER BY id ASC', $sql);
        $this->assertStringContainsString('LIMIT 500', $sql);
    }
}
