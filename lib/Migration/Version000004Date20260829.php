<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.3.0 follow-up — add UNIQUE(user_id, name) to moviedb_platforms.
 *
 * Closes a TOCTOU race in PlatformMapper::createDefaults: two concurrent
 * first-load requests can both snapshot an empty table and both run the
 * full insert loop. SQL NULL semantics mean UNIQUE(user_id, name) does NOT
 * cover rows where user_id IS NULL — (NULL, 'Netflix') is always distinct
 * from another (NULL, 'Netflix'). To make the constraint effective for
 * default rows we store '' (empty string) in user_id instead of NULL.
 *
 * postSchemaChange:
 *   1. Migrates existing default rows from user_id=NULL to user_id=''.
 *   2. Deduplicates any duplicate default rows that snuck in before this
 *      migration via the pre-fix race: keeps the lowest id, deletes the rest.
 *   Both steps run before the index is added (changeSchema runs first).
 */
class Version000004Date20260829 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('moviedb_platforms');

        if (!$table->hasIndex('moviedb_platforms_uid_name')) {
            $table->addUniqueIndex(['user_id', 'name'], 'moviedb_platforms_uid_name');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();

        // Step 1: migrate user_id=NULL → '' for all default rows so the
        // UNIQUE(user_id, name) index covers them.
        $qb->update('moviedb_platforms')
            ->set('user_id', $qb->createNamedParameter(''))
            ->where($qb->expr()->isNull('user_id'));
        $migrated = $qb->executeStatement();
        if ($migrated > 0) {
            $output->info("Migrated {$migrated} default platform row(s) from user_id=NULL to user_id=''.");
        }

        // Step 2: deduplicate any duplicate default rows (same name, user_id='')
        // that were inserted before this migration ran. Keep the lowest id.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('name')
            ->selectAlias($qb2->func()->min('id'), 'min_id')
            ->from('moviedb_platforms')
            ->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter('')))
            ->groupBy('name')
            ->having($qb2->expr()->gt($qb2->func()->count('*'), $qb2->createNamedParameter(1)));

        $result = $qb2->executeQuery();
        $deleted = 0;
        while ($row = $result->fetch()) {
            $qb3 = $this->db->getQueryBuilder();
            $qb3->delete('moviedb_platforms')
                ->where($qb3->expr()->eq('user_id', $qb3->createNamedParameter('')))
                ->andWhere($qb3->expr()->eq('name', $qb3->createNamedParameter($row['name'])))
                ->andWhere($qb3->expr()->gt('id', $qb3->createNamedParameter((int)$row['min_id'])));
            $deleted += $qb3->executeStatement();
        }
        $result->closeCursor();

        if ($deleted > 0) {
            $output->info("Removed {$deleted} duplicate default platform row(s).");
        }
    }
}
