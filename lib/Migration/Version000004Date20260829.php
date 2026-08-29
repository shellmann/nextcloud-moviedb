<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.3.0 follow-up — add UNIQUE(user_id, name) to moviedb_platforms.
 *
 * Closes a TOCTOU race in PlatformMapper::createDefaults: two concurrent
 * first-load requests could both snapshot an empty table and both insert the
 * full default set. With the unique constraint the DB rejects the losing racer
 * and createDefaults() catches the exception, making the seed truly idempotent.
 *
 * The index name follows the Nextcloud 30-char limit convention.
 * Existing duplicate rows (from the race before this migration) are deduplicated
 * in postSchemaChange: keep the lowest id, delete the rest.
 */
class Version000004Date20260829 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('moviedb_platforms');

        if (!$table->hasIndex('moviedb_platforms_uid_name')) {
            $table->addUniqueIndex(['user_id', 'name'], 'moviedb_platforms_uid_name');
        }

        return $schema;
    }
}
