<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.9.2 — keep TV shows up to date from TMDB.
 *
 * Adds moviedb_series.tmdb_refreshed_at, the time the seasons and episodes of
 * a show were last compared with TMDB. NULL means never (shows added or
 * imported before 1.9.2), so the background job picks those up first. Only a
 * nullable column is added, so it is safe for SQLite batch mode (see
 * Version000005).
 */
class Version000010Date20261020 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('moviedb_series')) {
            return null;
        }

        $table = $schema->getTable('moviedb_series');
        if ($table->hasColumn('tmdb_refreshed_at')) {
            return null;
        }

        $table->addColumn('tmdb_refreshed_at', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);

        return $schema;
    }
}
