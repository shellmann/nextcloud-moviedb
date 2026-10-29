<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Relaxes three boolean columns to nullable on older installs.
 *
 * Version000003 (episodes.watched) and Version000005 (libraries.is_personal,
 * library_members.permission_edit) first created these columns as NOT NULL.
 * PR #45 changed both migrations to `notnull: false` so fresh installs work on
 * Nextcloud 32, but installs that ran the old steps kept NOT NULL. Nextcloud
 * 35's schema check (`occ db:schema:check`, also printed after `occ upgrade`)
 * reports them as "differs in: nullable". The entities always write a value,
 * so nothing changes for the data.
 *
 * On fresh installs the columns are already nullable and this step changes
 * nothing. Relaxing NOT NULL makes SQLite rebuild the table, which copies all
 * columns and drops none, so it is safe for SQLite batch mode (see
 * Version000003).
 */
class Version000011Date20261027 extends SimpleMigrationStep {

    private const COLUMNS = [
        'moviedb_episodes' => 'watched',
        'moviedb_libraries' => 'is_personal',
        'moviedb_library_members' => 'permission_edit',
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (self::COLUMNS as $tableName => $columnName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn($columnName)) {
                continue;
            }
            $column = $table->getColumn($columnName);
            if ($column->getNotnull()) {
                $column->setNotnull(false);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
