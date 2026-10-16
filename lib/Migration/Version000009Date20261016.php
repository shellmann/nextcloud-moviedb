<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.9.0 — "stop watching" status for TV shows.
 *
 * Adds moviedb_series.stopped_watching. The flag lives on the series (per
 * library), like the episode watched flags it complements. Only a nullable
 * column is added, so it is safe for SQLite batch mode (see Version000005).
 */
class Version000009Date20261016 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('moviedb_series')) {
            return null;
        }

        $table = $schema->getTable('moviedb_series');
        if ($table->hasColumn('stopped_watching')) {
            return null;
        }

        $table->addColumn('stopped_watching', Types::BOOLEAN, [
            'notnull' => false,
            'default' => false,
        ]);

        return $schema;
    }
}
