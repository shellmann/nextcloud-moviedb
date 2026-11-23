<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.12.1 — moviedb_import_locks: short-lived application locks (#147).
 *
 * Two requests importing the same TV show (double-click, two tabs) must not both
 * pass the "already tracked?" check. Nextcloud's ILockingProvider is a no-op when
 * `filelocking.enabled` is off, so the app keeps its own lock rows: the primary key
 * on lock_key makes the insert atomic on every database, and expires_at lets a lock
 * left behind by a crashed request run out. A new table only, so the step is safe
 * for SQLite batch mode and can be re-run.
 */
class Version000014Date20261123 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if ($schema->hasTable('moviedb_import_locks')) {
            return null;
        }

        $table = $schema->createTable('moviedb_import_locks');
        $table->addColumn('lock_key', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 32]);
        // Unix time after which the lock no longer counts.
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
        $table->setPrimaryKey(['lock_key']);

        return $schema;
    }
}
