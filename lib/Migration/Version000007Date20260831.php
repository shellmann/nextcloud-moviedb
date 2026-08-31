<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.4.0 — heal duplicate personal libraries.
 *
 * A check-then-insert race in getPersonalLibraryId() (fixed in the same
 * release) could create more than one is_personal=1 library per owner when
 * several first-load requests arrived concurrently. This migration merges any
 * such duplicates: for each owner with multiple personal libraries it keeps the
 * lowest id, repoints all child rows (movies, series, watchlist, watches) and
 * member rows from the extra libraries onto the survivor, then deletes the
 * extras.
 *
 * Idempotent: on an installation with no duplicates it makes no changes. Runs
 * entirely in postSchemaChange (data-only; no schema change).
 *
 * A portable unique index on (owner, is_personal) is intentionally NOT added:
 * owners legitimately have many non-personal libraries, all of which would
 * share (owner, false); a filtered/partial unique index is not portable across
 * SQLite/MySQL/Postgres. New duplicates are instead prevented in application
 * code (getPersonalLibraryId self-heals and reconciles post-insert).
 */
class Version000007Date20260831 extends SimpleMigrationStep {

    /** Data tables carrying a library_id that must be repointed. */
    private const DATA_TABLES = [
        'moviedb_movies',
        'moviedb_series',
        'moviedb_watchlist',
        'moviedb_movie_watches',
    ];

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes — data-only migration.
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Scanning for duplicate personal libraries...');

        // Find every owner that has more than one is_personal=1 library.
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner')
            ->from('moviedb_libraries')
            ->where($qb->expr()->eq('is_personal', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
            ->groupBy('owner')
            ->having($qb->expr()->gt($qb->func()->count('id'), $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $owners = [];
        while ($row = $result->fetch()) {
            $owners[] = $row['owner'];
        }
        $result->closeCursor();

        if (empty($owners)) {
            $output->info('No duplicate personal libraries found.');
            return;
        }

        $output->info('Found ' . count($owners) . ' owner(s) with duplicate personal libraries; merging...');
        $mergedExtras = 0;

        foreach ($owners as $owner) {
            // All personal library ids for this owner, lowest first.
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('moviedb_libraries')
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
                ->andWhere($qb->expr()->eq('is_personal', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
                ->orderBy('id', 'ASC');
            $res = $qb->executeQuery();
            $ids = [];
            while ($row = $res->fetch()) {
                $ids[] = (int)$row['id'];
            }
            $res->closeCursor();

            if (count($ids) < 2) {
                continue;
            }

            $survivor = array_shift($ids);
            $extras   = $ids; // ids to fold into $survivor

            // Repoint child rows in every data table.
            foreach (self::DATA_TABLES as $table) {
                $up = $this->db->getQueryBuilder();
                $up->update($table)
                    ->set('library_id', $up->createNamedParameter($survivor, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($up->expr()->in('library_id', $up->createNamedParameter($extras, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
                $up->executeStatement();
            }

            // Repoint member rows, then drop any that now duplicate a
            // (survivor, user_id) pair already present.
            $up = $this->db->getQueryBuilder();
            $up->update('moviedb_library_members')
                ->set('library_id', $up->createNamedParameter($survivor, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($up->expr()->in('library_id', $up->createNamedParameter($extras, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $up->executeStatement();
            $this->dedupeMembers($survivor);

            // Delete the now-empty extra library rows.
            $del = $this->db->getQueryBuilder();
            $del->delete('moviedb_libraries')
                ->where($del->expr()->in('id', $del->createNamedParameter($extras, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
            $del->executeStatement();

            $mergedExtras += count($extras);
        }

        $output->info("Merged and removed {$mergedExtras} duplicate personal librar" . ($mergedExtras === 1 ? 'y' : 'ies') . '.');
    }

    /**
     * Remove duplicate member rows for a library, keeping the lowest-id row per
     * user_id. Personal libraries normally have no members, but a merged extra
     * could have carried one.
     */
    private function dedupeMembers(int $libraryId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id')
            ->from('moviedb_library_members')
            ->where($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');
        $res = $qb->executeQuery();

        $seen    = [];
        $toDelete = [];
        while ($row = $res->fetch()) {
            $uid = $row['user_id'];
            if (isset($seen[$uid])) {
                $toDelete[] = (int)$row['id'];
            } else {
                $seen[$uid] = true;
            }
        }
        $res->closeCursor();

        if (empty($toDelete)) {
            return;
        }

        $del = $this->db->getQueryBuilder();
        $del->delete('moviedb_library_members')
            ->where($del->expr()->in('id', $del->createNamedParameter($toDelete, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $del->executeStatement();
    }
}
