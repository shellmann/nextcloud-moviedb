<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Contract step of the rewatch model change: drop the now-redundant watch
 * columns from moviedb_movies. Split from Version000002 (expand + backfill) so
 * the destructive DDL runs as its own upgrade step, AFTER the backfill migration
 * has committed and been verified.
 *
 * Safety model:
 * - preSchemaChange re-verifies that every movie still carrying watch data has a
 *   matching row in moviedb_movie_watches. On any shortfall it throws, aborting
 *   the update BEFORE changeSchema drops a single column — the source data stays
 *   intact and recoverable. This repeats Version000002's guard so the drop is
 *   safe even if migrations are somehow re-ordered or re-run.
 * - Each drop is guarded by hasColumn, so re-running is a no-op.
 *
 * DDL transaction note: PostgreSQL executes DDL inside the migration
 * transaction; MySQL/MariaDB auto-commit each DDL statement. The verification
 * runs in preSchemaChange (before any DROP) precisely so we never rely on a DDL
 * rollback that MySQL does not provide.
 */
class Version000003Date20260824 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Nothing to verify if the source columns are already gone (re-run / fresh install).
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('moviedb_movies') || !$schema->hasTable('moviedb_movie_watches')) {
            return;
        }
        $movies = $schema->getTable('moviedb_movies');
        if (!$movies->hasColumn('date_watched')) {
            // Columns already dropped by a previous run — nothing left to verify.
            return;
        }

        // Count movies that still carry any watch data.
        $src = $this->db->getQueryBuilder();
        $src->select($src->func()->count('*', 'count'))
            ->from('moviedb_movies')
            ->where(
                $src->expr()->orX(
                    $src->expr()->isNotNull('date_watched'),
                    $src->expr()->isNotNull('rating'),
                    $src->expr()->isNotNull('review'),
                    $src->expr()->isNotNull('platform_id'),
                    $src->expr()->isNotNull('language_watched')
                )
            );
        $r = $src->executeQuery();
        $expected = (int)($r->fetch()['count'] ?? 0);
        $r->closeCursor();

        // Count distinct movies that have at least one watch row. COUNT(DISTINCT ...)
        // via the func() helper is rejected on some platforms, so wrap a distinct
        // subquery to stay portable across SQLite/MySQL/Postgres.
        $sub = $this->db->getQueryBuilder();
        $sub->selectDistinct('mw.movie_id')
            ->from('moviedb_movie_watches', 'mw')
            ->innerJoin('mw', 'moviedb_movies', 'm', $sub->expr()->eq('mw.movie_id', 'm.id'));
        $wq = $this->db->getQueryBuilder();
        $wq->select($wq->func()->count('*', 'count'))
            ->from($wq->createFunction('(' . $sub->getSQL() . ')'), 'distinct_movies');
        $rw = $wq->executeQuery();
        $covered = (int)($rw->fetch()['count'] ?? 0);
        $rw->closeCursor();

        if ($covered < $expected) {
            $output->warning("Backfill verification failed: {$expected} movies carry watch data but only {$covered} have watch rows. Refusing to drop columns.");
            throw new \RuntimeException(
                "MovieDB migration aborted: {$expected} movies with watch data but only {$covered} have watch rows. "
                . 'Source columns left intact for recovery — investigate the Version000002 backfill before retrying.'
            );
        }

        $output->info("Backfill re-verified: {$covered}/{$expected} movies with watch data are covered. Safe to drop columns.");
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('moviedb_movies')) {
            return null;
        }
        $table = $schema->getTable('moviedb_movies');

        foreach (['date_watched', 'rating', 'review', 'platform_id', 'language_watched'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }

        return $schema;
    }
}
