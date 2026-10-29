<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.10 — keep release dates on the watchlist up to date from TMDB.
 *
 * Adds moviedb_watchlist.release_date_changed_at, the time a daily TMDB check
 * last set or moved the release date of an item. It drives the "New date"
 * badge on the watchlist; NULL means no news. Only a nullable column is
 * added, so it is safe for SQLite batch mode (see Version000005).
 *
 * Also repairs release dates stored as "" (TMDB's value for unreleased
 * titles). SQLite accepted them, MySQL/MariaDB and PostgreSQL never did, so
 * only SQLite installs can hold such rows. They would stay out of the daily
 * check and break `occ db:convert-type`.
 */
class Version000012Date20261029 extends SimpleMigrationStep {

    private const DATE_TABLES = ['moviedb_watchlist', 'moviedb_movies'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // A DATE column cannot be compared with "" on PostgreSQL, and the other databases never stored it.
        if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
            return;
        }

        $schema = $schemaClosure();
        foreach (self::DATE_TABLES as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->update($tableName)
                ->set('release_date', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->where($qb->expr()->eq('release_date', $qb->createNamedParameter('')));
            $repaired = $qb->executeStatement();
            if ($repaired > 0) {
                $output->info("Repaired $repaired empty release dates in $tableName.");
            }
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('moviedb_watchlist')) {
            return null;
        }

        $table = $schema->getTable('moviedb_watchlist');
        if ($table->hasColumn('release_date_changed_at')) {
            return null;
        }

        $table->addColumn('release_date_changed_at', Types::DATETIME, [
            'notnull' => false,
        ]);

        return $schema;
    }
}
