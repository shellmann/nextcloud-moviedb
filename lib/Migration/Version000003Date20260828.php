<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.3.0 — episode-level TV-show support.
 *
 * Adds two tables (moviedb_series, moviedb_episodes) and extends the existing
 * moviedb_movie_watches so a watch row can point at a movie OR a series episode:
 * adds nullable episode_id + denormalized series_id, and relaxes movie_id to
 * nullable. The media_type column on moviedb_movies (added in v1.2.0) stays the
 * discriminator for the movie side. moviedb_watchlist also gains a media_type
 * column so the watchlist can hold TV shows alongside movies.
 *
 * SQLite fresh-install safety (see Version000002 comment): on a fresh install
 * Nextcloud batches every migration's changeSchema with no postSchemaChange
 * between them, and SQLite rebuilds a table on a *column drop* generating copy
 * SQL that references the dropped column → aborts the install. This migration
 * only ADDS tables/columns and RELAXES a NOT NULL constraint — the rebuild for
 * the relax copies all existing columns and drops none, so it is safe. We do
 * NOT drop the legacy movie-watch columns here.
 */
class Version000003Date20260828 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Series table — mirrors moviedb_movies conventions.
        if (!$schema->hasTable('moviedb_series')) {
            $table = $schema->createTable('moviedb_series');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('tmdb_id', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 512,
            ]);
            $table->addColumn('original_title', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);
            $table->addColumn('poster_path', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('backdrop_path', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('overview', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('genre_ids', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('first_air_date', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('first_air_year', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('number_of_seasons', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('number_of_episodes', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('cast_data', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('director', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('is_favorite', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'moviedb_series_user_idx');
            $table->addIndex(['tmdb_id'], 'moviedb_series_tmdb_idx');
        }

        // Episodes table — ownership derives from the parent series (no user_id).
        if (!$schema->hasTable('moviedb_episodes')) {
            $table = $schema->createTable('moviedb_episodes');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('series_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('tmdb_id', Types::INTEGER, [
                'notnull' => false,
            ]);
            // Default 0 is required because the Episode entity defaults these
            // properties to 0, and Nextcloud's magic setter only marks a field
            // dirty when the value changes — so an episode with season_number 0
            // (specials) is omitted from the QBMapper INSERT column list. The
            // DB default fills it in rather than failing the NOT NULL constraint.
            $table->addColumn('season_number', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('episode_number', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 512,
                'default' => '',
            ]);
            $table->addColumn('overview', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('air_date', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            $table->addColumn('runtime', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('still_path', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['series_id'], 'moviedb_ep_series_idx');
            $table->addIndex(['series_id', 'season_number'], 'moviedb_ep_season_idx');
            $table->addIndex(['tmdb_id'], 'moviedb_ep_tmdb_idx');
        }

        // Watchlist gains a media_type discriminator so it can hold TV shows
        // alongside movies (mirrors moviedb_movies.media_type from v1.2.0).
        // Add-only + defaulted NOT NULL: existing rows become 'movie', and the
        // SQLite fresh-install batch rebuild copies all columns (drops none).
        if ($schema->hasTable('moviedb_watchlist')) {
            $table = $schema->getTable('moviedb_watchlist');
            if (!$table->hasColumn('media_type')) {
                $table->addColumn('media_type', Types::STRING, [
                    'notnull' => true,
                    'length' => 16,
                    'default' => 'movie',
                ]);
            }
        }

        // Extend the watch table for episode watches.
        if ($schema->hasTable('moviedb_movie_watches')) {
            $table = $schema->getTable('moviedb_movie_watches');

            if (!$table->hasColumn('episode_id')) {
                $table->addColumn('episode_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }
            if (!$table->hasColumn('series_id')) {
                $table->addColumn('series_id', Types::INTEGER, [
                    'notnull' => false,
                ]);
            }

            // Relax movie_id to nullable — a watch row is now movie XOR episode.
            if ($table->hasColumn('movie_id')) {
                $movieIdCol = $table->getColumn('movie_id');
                if ($movieIdCol->getNotnull()) {
                    $movieIdCol->setNotnull(false);
                }
            }

            if (!$table->hasIndex('moviedb_watches_episode_idx')) {
                $table->addIndex(['episode_id'], 'moviedb_watches_episode_idx');
            }
            if (!$table->hasIndex('moviedb_watches_series_idx')) {
                $table->addIndex(['series_id'], 'moviedb_watches_series_idx');
            }
        }

        return $schema;
    }
}
