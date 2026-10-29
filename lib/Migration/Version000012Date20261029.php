<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.10 — keep release dates on the watchlist up to date from TMDB.
 *
 * Adds moviedb_watchlist.release_date_changed_at, the time a daily TMDB check
 * last set or moved the release date of an item. It drives the "New date"
 * badge on the watchlist; NULL means no news. Only a nullable column is
 * added, so it is safe for SQLite batch mode (see Version000005).
 */
class Version000012Date20261029 extends SimpleMigrationStep {

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
