<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.4.0 follow-up — tighten library_id to NOT NULL on the four data tables.
 *
 * Version000005 added library_id as a *nullable* column and backfilled every
 * existing row (one personal library per user), guarding at the end that no
 * row was left with library_id IS NULL. This migration promotes the column to
 * NOT NULL now that the data is known-clean.
 *
 * Ordering / safety:
 *   preSchemaChange — defensive re-check. If, for any reason, a row still has
 *     library_id IS NULL (e.g. rows created by an app version running between
 *     V5 and this upgrade that did not yet set library_id), abort BEFORE the
 *     schema change rather than let the NOT NULL alter fail mid-flight. Data is
 *     left intact for investigation.
 *   changeSchema — flip notnull on library_id for each table (guarded so a
 *     re-run is a no-op).
 *
 * SQLite fresh-install safety: on a fresh install Nextcloud batches every
 * migration's changeSchema with no postSchemaChange between them, so V5's
 * backfill has NOT run when this migration's changeSchema executes — but all
 * four tables are EMPTY on a fresh install, so a NOT NULL column with no rows
 * is trivially satisfiable. SQLite rebuilds the table to tighten the
 * constraint; that rebuild copies all existing columns and DROPS none, so it
 * is safe (same reasoning as the movie_id relax in Version000003).
 */
class Version000006Date20260831 extends SimpleMigrationStep {

    /** Tables whose library_id column is promoted to NOT NULL. */
    private const TABLES = [
        'moviedb_movies',
        'moviedb_series',
        'moviedb_watchlist',
        'moviedb_movie_watches',
    ];

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Defensive re-check: fail before the NOT NULL alter if any row still
        // lacks a library_id. (On a fresh install every table is empty, so this
        // loop finds nothing and passes cleanly.) A table that does not yet
        // exist is skipped — the schema wrapper is authoritative on structure.
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $nullTotal = 0;
        $nullDetails = [];

        foreach (self::TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }
            if (!$schema->getTable($table)->hasColumn('library_id')) {
                // Version000005 did not run — nothing to tighten.
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from($table)
                ->where($qb->expr()->isNull('library_id'));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            $cnt = (int)($row['cnt'] ?? 0);
            if ($cnt > 0) {
                $nullDetails[] = "{$table}: {$cnt} row(s)";
                $nullTotal += $cnt;
            }
        }

        if ($nullTotal > 0) {
            $msg = "Cannot enforce NOT NULL on library_id — {$nullTotal} row(s) "
                . 'still have library_id IS NULL: '
                . implode(', ', $nullDetails)
                . '. Migration aborted; data is intact. '
                . 'Re-run the app upgrade so Version000005 can backfill these rows first.';
            $output->warning($msg);
            throw new \RuntimeException($msg);
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (self::TABLES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('library_id')) {
                continue;
            }
            $column = $table->getColumn('library_id');
            if (!$column->getNotnull()) {
                $column->setNotnull(true);
            }
        }

        return $schema;
    }
}
