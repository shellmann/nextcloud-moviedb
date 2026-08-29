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
 * Closes a TOCTOU race in PlatformMapper::createDefaults. SQL NULL semantics
 * mean UNIQUE(user_id, name) does NOT cover rows where user_id IS NULL —
 * (NULL, 'Netflix') is always distinct from another (NULL, 'Netflix'), so the
 * constraint is bypassed. Fix: store '' in user_id for default rows instead
 * of NULL, so the constraint actually applies.
 *
 * Step order (critical — all data fixup must precede the index):
 *   preSchemaChange:
 *     1. Remove duplicate default rows (user_id IS NULL, same name) — keeps
 *        the lowest id. Must run before NULL→'' conversion because the UPDATE
 *        would create (''/'Netflix') duplicates that violate the about-to-be-
 *        added index before the dedup can clean them up.
 *     2. Migrate user_id=NULL → '' for all remaining default rows.
 *   changeSchema:
 *     3. Add UNIQUE(user_id, name). Table is already clean by this point.
 */
class Version000004Date20260829 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Step 1: remove duplicate default rows (user_id IS NULL, same name),
        // keeping the lowest id. Must run before the NULL→'' migration so the
        // UPDATE does not produce (''/'Netflix') duplicates that would violate
        // the unique index added in changeSchema.
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
            ->selectAlias($qb->func()->min('id'), 'min_id')
            ->from('moviedb_platforms')
            ->where($qb->expr()->isNull('user_id'))
            ->groupBy('name')
            ->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1)));

        $result = $qb->executeQuery();
        $deleted = 0;
        while ($row = $result->fetch()) {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->delete('moviedb_platforms')
                ->where($qb2->expr()->isNull('user_id'))
                ->andWhere($qb2->expr()->eq('name', $qb2->createNamedParameter($row['name'])))
                ->andWhere($qb2->expr()->gt('id', $qb2->createNamedParameter((int)$row['min_id'])));
            $deleted += $qb2->executeStatement();
        }
        $result->closeCursor();

        if ($deleted > 0) {
            $output->info("Removed {$deleted} duplicate default platform row(s).");
        }

        // Step 2: migrate user_id=NULL → '' so the about-to-be-added
        // UNIQUE(user_id, name) index actually covers default rows.
        $qb3 = $this->db->getQueryBuilder();
        $qb3->update('moviedb_platforms')
            ->set('user_id', $qb3->createNamedParameter(''))
            ->where($qb3->expr()->isNull('user_id'));
        $migrated = $qb3->executeStatement();
        if ($migrated > 0) {
            $output->info("Migrated {$migrated} default platform row(s) from user_id=NULL to user_id=''.");
        }
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
}
