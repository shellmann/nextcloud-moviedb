<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.4.0 — shared-library scaffolding.
 *
 * Creates two new tables (moviedb_libraries, moviedb_library_members) and adds
 * a nullable library_id column to the four data tables (moviedb_movies,
 * moviedb_series, moviedb_watchlist, moviedb_movie_watches).
 *
 * postSchemaChange backfills existing data: for every distinct user_id found
 * across the data tables, one personal library is created and all rows for
 * that user are assigned to it.
 *
 * SQLite fresh-install safety: this migration only ADDS tables and nullable
 * columns. SQLite rebuilds a table when a column is dropped (or a NOT NULL
 * constraint is tightened), but not on nullable column additions — so the
 * SQLite batch-migration scenario is safe here.
 */
class Version000005Date20260831 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── moviedb_libraries ─────────────────────────────────────────────────
        if (!$schema->hasTable('moviedb_libraries')) {
            $table = $schema->createTable('moviedb_libraries');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('owner', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('is_personal', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner'], 'moviedb_lib_owner_idx');
        }

        // ── moviedb_library_members ───────────────────────────────────────────
        if (!$schema->hasTable('moviedb_library_members')) {
            $table = $schema->createTable('moviedb_library_members');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('library_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('permission_edit', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['library_id', 'user_id'], 'moviedb_libmem_lib_uid_idx');
            $table->addIndex(['user_id'], 'moviedb_libmem_uid_idx');
        }

        // ── Add library_id to data tables ─────────────────────────────────────
        // All additions are nullable columns — safe for SQLite fresh-install
        // batch mode (no column drops, no constraint tightening).

        if ($schema->hasTable('moviedb_movies')) {
            $table = $schema->getTable('moviedb_movies');
            if (!$table->hasColumn('library_id')) {
                $table->addColumn('library_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('moviedb_movies_lib_idx')) {
                $table->addIndex(['library_id'], 'moviedb_movies_lib_idx');
            }
        }

        if ($schema->hasTable('moviedb_series')) {
            $table = $schema->getTable('moviedb_series');
            if (!$table->hasColumn('library_id')) {
                $table->addColumn('library_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('moviedb_series_lib_idx')) {
                $table->addIndex(['library_id'], 'moviedb_series_lib_idx');
            }
        }

        if ($schema->hasTable('moviedb_watchlist')) {
            $table = $schema->getTable('moviedb_watchlist');
            if (!$table->hasColumn('library_id')) {
                $table->addColumn('library_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('moviedb_watchlist_lib_idx')) {
                $table->addIndex(['library_id'], 'moviedb_watchlist_lib_idx');
            }
        }

        if ($schema->hasTable('moviedb_movie_watches')) {
            $table = $schema->getTable('moviedb_movie_watches');
            if (!$table->hasColumn('library_id')) {
                $table->addColumn('library_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasIndex('moviedb_watches_lib_idx')) {
                $table->addIndex(['library_id'], 'moviedb_watches_lib_idx');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Starting library backfill: collecting distinct user IDs from data tables...');

        // Collect all distinct user IDs across every data-owning table.
        // moviedb_movie_watches carries its own user_id (set independently by
        // MovieWatchMapper), so a watch row can belong to a user with no row in
        // movies/series/watchlist — e.g. after the parent catalog row was
        // deleted. Union it in too, or such rows would never get a library_id
        // and the verify guard below would abort the whole upgrade.
        $userIds = [];

        foreach (['moviedb_movies', 'moviedb_series', 'moviedb_watchlist', 'moviedb_movie_watches'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('user_id')
                ->from($table);
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $uid = $row['user_id'];
                if ($uid !== null && $uid !== '') {
                    $userIds[$uid] = true;
                }
            }
            $result->closeCursor();
        }

        $userIds = array_keys($userIds);
        $output->info('Found ' . count($userIds) . ' distinct user(s) to backfill.');

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $libraryCount = 0;

        // ── Per-user: create personal library, then assign rows ──────────────
        foreach ($userIds as $uid) {
            // Insert personal library for this user.
            $qb = $this->db->getQueryBuilder();
            $qb->insert('moviedb_libraries')
                ->values([
                    'owner'       => $qb->createNamedParameter($uid),
                    'name'        => $qb->createNamedParameter('Personal'),
                    'is_personal' => $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
                    'created_at'  => $qb->createNamedParameter($now),
                    'updated_at'  => $qb->createNamedParameter(null),
                ]);
            $qb->executeStatement();
            $libraryId = $this->db->lastInsertId('*PREFIX*moviedb_libraries');
            $libraryCount++;

            // Assign moviedb_movies rows for this user.
            $qb2 = $this->db->getQueryBuilder();
            $qb2->update('moviedb_movies')
                ->set('library_id', $qb2->createNamedParameter((int)$libraryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($uid)))
                ->andWhere($qb2->expr()->isNull('library_id'));
            $qb2->executeStatement();

            // Assign moviedb_series rows for this user.
            $qb3 = $this->db->getQueryBuilder();
            $qb3->update('moviedb_series')
                ->set('library_id', $qb3->createNamedParameter((int)$libraryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb3->expr()->eq('user_id', $qb3->createNamedParameter($uid)))
                ->andWhere($qb3->expr()->isNull('library_id'));
            $qb3->executeStatement();

            // Assign moviedb_watchlist rows for this user.
            $qb4 = $this->db->getQueryBuilder();
            $qb4->update('moviedb_watchlist')
                ->set('library_id', $qb4->createNamedParameter((int)$libraryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb4->expr()->eq('user_id', $qb4->createNamedParameter($uid)))
                ->andWhere($qb4->expr()->isNull('library_id'));
            $qb4->executeStatement();

            // Assign moviedb_movie_watches rows for this user.
            $qb5 = $this->db->getQueryBuilder();
            $qb5->update('moviedb_movie_watches')
                ->set('library_id', $qb5->createNamedParameter((int)$libraryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                ->where($qb5->expr()->eq('user_id', $qb5->createNamedParameter($uid)))
                ->andWhere($qb5->expr()->isNull('library_id'));
            $qb5->executeStatement();
        }

        $output->info("Backfilled {$libraryCount} personal librar" . ($libraryCount === 1 ? 'y' : 'ies') . '.');

        // ── Verify guard ──────────────────────────────────────────────────────
        // Count any rows that still have library_id IS NULL in the four tables.
        // On a fresh install all four tables are empty so the count is 0 and the
        // guard passes cleanly.
        $nullTotal = 0;
        $nullDetails = [];

        foreach (['moviedb_movies', 'moviedb_series', 'moviedb_watchlist', 'moviedb_movie_watches'] as $table) {
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
            $msg = "Library backfill incomplete — {$nullTotal} row(s) still have library_id IS NULL: "
                . implode(', ', $nullDetails)
                . '. Migration aborted; data is intact.';
            $output->warning($msg);
            throw new \RuntimeException($msg);
        }

        $output->info('Verify guard passed: all rows have a library_id assigned.');
    }
}
