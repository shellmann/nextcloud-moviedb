<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Service;

use OCA\MovieDB\Service\ImportLockService;
use OCA\MovieDB\Tests\Unit\Db\SqlRecording;
use OCA\MovieDB\Tests\Unit\Db\SqlRecordingQB;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * The database-backed import lock (#147 review): works without Nextcloud's file
 * locking, takes a lock atomically through the primary key, lets expired locks go.
 */
class ImportLockServiceTest extends TestCase {
    private SqlRecording $rec;
    private IDBConnection $db;
    private ITimeFactory $time;

    protected function setUp(): void {
        parent::setUp();
        $this->rec = new SqlRecording();
        $this->db = $this->createStub(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));
        $this->time = $this->createStub(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(1_000);
    }

    public function testTakesAFreeLockWithAnExpiryAndClearsExpiredLocksFirst(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->db->method('getQueryBuilder')->willReturnCallback(fn () => new SqlRecordingQB($this->rec));
        $inserted = null;
        $this->db->expects($this->once())->method('insertIgnoreConflict')
            ->willReturnCallback(function (string $table, array $values) use (&$inserted) {
                $inserted = [$table, $values];
                return 1;
            });

        $token = (new ImportLockService($this->db, $this->time))->tryAcquire('series/3/1399', 300);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame('moviedb_import_locks', $inserted[0]);
        $this->assertSame(['lock_key' => 'series/3/1399', 'token' => $token, 'expires_at' => 1_300], $inserted[1]);
        $this->assertSame(['DELETE FROM moviedb_import_locks WHERE expires_at < :p1'], $this->rec->executed);
        $this->assertSame(1_000, $this->rec->params[':p1']);
    }

    public function testReturnsNullWhileSomeoneElseHoldsTheLock(): void {
        $this->db->method('insertIgnoreConflict')->willReturn(0);

        $this->assertNull((new ImportLockService($this->db, $this->time))->tryAcquire('series/3/1399', 300));
    }

    public function testReleasesOnlyItsOwnLock(): void {
        (new ImportLockService($this->db, $this->time))->release('series/3/1399', 'abc');

        $this->assertSame(['DELETE FROM moviedb_import_locks WHERE lock_key = :p1 AND token = :p2'], $this->rec->executed);
        $this->assertSame(['series/3/1399', 'abc'], [$this->rec->params[':p1'], $this->rec->params[':p2']]);
    }
}
