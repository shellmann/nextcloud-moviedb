<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260302 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Movies table - stores watched movies
        if (!$schema->hasTable('moviedb_movies')) {
            $table = $schema->createTable('moviedb_movies');

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
            $table->addColumn('release_date', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('release_year', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('runtime', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('cast_data', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('director', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('platform_id', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('language_watched', Types::STRING, [
                'notnull' => false,
                'length' => 10,
            ]);
            $table->addColumn('date_watched', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('rating', Types::SMALLINT, [
                'notnull' => false,
            ]);
            $table->addColumn('review', Types::TEXT, [
                'notnull' => false,
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
            $table->addIndex(['user_id'], 'moviedb_movies_user_idx');
            $table->addIndex(['tmdb_id'], 'moviedb_movies_tmdb_idx');
            $table->addIndex(['date_watched'], 'moviedb_movies_date_idx');
            $table->addIndex(['release_year'], 'moviedb_movies_year_idx');
        }

        // Watchlist table - stores movies to watch
        if (!$schema->hasTable('moviedb_watchlist')) {
            $table = $schema->createTable('moviedb_watchlist');

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
            $table->addColumn('poster_path', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('overview', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('genre_ids', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('release_date', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('added_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('priority', Types::SMALLINT, [
                'notnull' => false,
                'default' => 0,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'moviedb_watchlist_user_idx');
            $table->addIndex(['tmdb_id'], 'moviedb_watchlist_tmdb_idx');
        }

        // Platforms table - stores streaming platforms
        if (!$schema->hasTable('moviedb_platforms')) {
            $table = $schema->createTable('moviedb_platforms');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('is_default', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'moviedb_platforms_user_idx');
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Check if default platforms already exist
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'count'))
            ->from('moviedb_platforms')
            ->where($qb->expr()->isNull('user_id'));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (((int)($row['count'] ?? 0)) > 0) {
            $output->info('Default platforms already exist, skipping...');
            return;
        }

        // Create default platforms
        $defaults = [
            ['name' => 'Netflix', 'icon' => 'netflix'],
            ['name' => 'Amazon Prime Video', 'icon' => 'prime'],
            ['name' => 'Disney+', 'icon' => 'disney'],
            ['name' => 'HBO Max', 'icon' => 'hbo'],
            ['name' => 'Apple TV+', 'icon' => 'appletv'],
            ['name' => 'Waipu TV', 'icon' => 'waipu'],
            ['name' => 'Sky', 'icon' => 'sky'],
            ['name' => 'YouTube', 'icon' => 'youtube'],
            ['name' => 'TV', 'icon' => 'tv'],
            ['name' => 'Cinema', 'icon' => 'cinema'],
            ['name' => 'DVD/Blu-ray', 'icon' => 'disc'],
            ['name' => 'Other', 'icon' => 'other'],
        ];

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($defaults as $default) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('moviedb_platforms')
                ->values([
                    'user_id' => $qb->createNamedParameter(null),
                    'name' => $qb->createNamedParameter($default['name']),
                    'icon' => $qb->createNamedParameter($default['icon']),
                    'is_default' => $qb->createNamedParameter(true, Types::BOOLEAN),
                    'created_at' => $qb->createNamedParameter($now),
                ]);
            $qb->executeStatement();
        }

        $output->info('Created ' . count($defaults) . ' default platforms');
    }
}
