<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Database-backed application locks (table moviedb_import_locks, #147).
 *
 * Independent of Nextcloud's file locking, which is a no-op when
 * `filelocking.enabled` is off. A lock is a row: the primary key on lock_key makes
 * acquiring atomic on every database (insertIgnoreConflict inserts or does nothing),
 * the token makes sure a request only releases its own lock, and expires_at lets a
 * lock left behind by a crashed request run out.
 *
 * Call it outside a transaction: the lock row must be visible to other requests at once.
 */
class ImportLockService {
    private const TABLE = 'moviedb_import_locks';

    public function __construct(
        private IDBConnection $db,
        private ITimeFactory $time,
    ) {
    }

    /**
     * Takes the lock if it is free (or expired).
     *
     * @param int $ttl Seconds until the lock runs out if it is never released
     * @return string|null The token to release it with, or null while someone else holds it
     */
    public function tryAcquire(string $key, int $ttl): ?string {
        $now = $this->time->getTime();

        // Expired locks (a request that crashed) must not block anyone.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement();

        $token = bin2hex(random_bytes(16));
        $inserted = $this->db->insertIgnoreConflict(self::TABLE, [
            'lock_key' => $key,
            'token' => $token,
            'expires_at' => $now + $ttl,
        ]);
        return $inserted > 0 ? $token : null;
    }

    /**
     * Releases a lock taken with tryAcquire(); a lock that expired and was taken over
     * by another request (different token) is left alone.
     */
    public function release(string $key, string $token): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('lock_key', $qb->createNamedParameter($key)))
            ->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)))
            ->executeStatement();
    }
}
