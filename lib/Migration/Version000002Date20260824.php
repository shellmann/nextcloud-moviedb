<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20260824 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add media_type to movies table (default 'movie', seeds the ground for TV shows in v1.3.0)
        if ($schema->hasTable('moviedb_movies')) {
            $table = $schema->getTable('moviedb_movies');
            if (!$table->hasColumn('media_type')) {
                $table->addColumn('media_type', Types::STRING, [
                    'notnull' => true,
                    'length' => 16,
                    'default' => 'movie',
                ]);
            }
        }

        // Create watch history table — one row per viewing of a title
        if (!$schema->hasTable('moviedb_movie_watches')) {
            $table = $schema->createTable('moviedb_movie_watches');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('movie_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('watched_at', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('rating', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $table->addColumn('review', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('platform_id', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('language_watched', Types::STRING, [
                'notnull' => false,
                'length' => 10,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['movie_id'], 'moviedb_watches_movie_idx');
            $table->addIndex(['user_id'], 'moviedb_watches_user_idx');
            $table->addIndex(['watched_at'], 'moviedb_watches_date_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Backfill: create one watch row per movie that has any watch data.
        // Mirrors the platform-seed idempotency pattern from Version000001.
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'count'))
            ->from('moviedb_movie_watches');
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (((int)($row['count'] ?? 0)) > 0) {
            $output->info('Watch rows already exist, skipping backfill...');
            return;
        }

        // Select movies that have at least one watch field populated
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'date_watched', 'rating', 'review', 'platform_id', 'language_watched')
            ->from('moviedb_movies')
            ->where(
                $qb->expr()->orX(
                    $qb->expr()->isNotNull('date_watched'),
                    $qb->expr()->isNotNull('rating'),
                    $qb->expr()->isNotNull('review'),
                    $qb->expr()->isNotNull('platform_id'),
                    $qb->expr()->isNotNull('language_watched')
                )
            );

        $movies = $qb->executeQuery();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $inserted = 0;
        $sourceCount = 0;

        while ($movie = $movies->fetch()) {
            $sourceCount++;
            $ins = $this->db->getQueryBuilder();
            $ins->insert('moviedb_movie_watches')
                ->values([
                    'movie_id'         => $ins->createNamedParameter((int)$movie['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'user_id'          => $ins->createNamedParameter($movie['user_id']),
                    'watched_at'       => $ins->createNamedParameter($movie['date_watched']),
                    'rating'           => $ins->createNamedParameter(
                        $movie['rating'] !== null ? (int)$movie['rating'] : null,
                        $movie['rating'] !== null ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL
                    ),
                    'review'           => $ins->createNamedParameter($movie['review']),
                    'platform_id'      => $ins->createNamedParameter(
                        $movie['platform_id'] !== null ? (int)$movie['platform_id'] : null,
                        $movie['platform_id'] !== null ? \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT : \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL
                    ),
                    'language_watched' => $ins->createNamedParameter($movie['language_watched']),
                    'created_at'       => $ins->createNamedParameter($now),
                    'updated_at'       => $ins->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL),
                ]);
            $ins->executeStatement();
            $inserted++;
        }
        $movies->closeCursor();

        $output->info("Backfilled {$inserted} watch rows from {$sourceCount} movies.");

        // ── Verify-before-drop guard (backstop, independent of the model checkpoint) ──
        // Recount the source (movies still carrying watch data) and the watch rows
        // we just inserted. If they diverge, the backfill is incomplete: throw to
        // ABORT the whole migration. Nextcloud runs migrations in maintenance mode
        // and marks the app-update failed on an exception, so nothing downstream
        // runs — the source columns are still present and no data is lost. The
        // column DROP lives in the NEXT migration (Version000003) which re-runs
        // this same verification before it removes anything, so the destructive
        // step only proceeds on a database whose backfill provably matched.
        $check = $this->db->getQueryBuilder();
        $check->select($check->func()->count('*', 'count'))
            ->from('moviedb_movies')
            ->where(
                $check->expr()->orX(
                    $check->expr()->isNotNull('date_watched'),
                    $check->expr()->isNotNull('rating'),
                    $check->expr()->isNotNull('review'),
                    $check->expr()->isNotNull('platform_id'),
                    $check->expr()->isNotNull('language_watched')
                )
            );
        $r = $check->executeQuery();
        $expected = (int)($r->fetch()['count'] ?? 0);
        $r->closeCursor();

        if ($expected !== $inserted) {
            $output->warning("Backfill mismatch: {$expected} movies with watch data but {$inserted} watch rows inserted. Aborting before any columns are dropped.");
            throw new \RuntimeException(
                "MovieDB migration aborted: watch backfill incomplete ({$expected} expected, {$inserted} inserted). "
                . 'Source columns left intact for recovery.'
            );
        }

        $output->info("Backfill verified: {$inserted} watch rows match {$expected} source movies.");
    }
}
