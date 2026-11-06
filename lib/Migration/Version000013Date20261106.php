<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.11 — show details from TMDB, release notifications.
 *
 * Only nullable columns are added, so this is safe for SQLite batch mode
 * (see Version000005). Each column is guarded, so the step can be re-run.
 *
 * - moviedb_series: series_type, networks, tmdb_rating (vote_average x 10),
 *   tmdb_vote_count. NULL means "not fetched yet": the daily refresh job
 *   backfills them.
 * - moviedb_episodes.notified_at, moviedb_watchlist.release_notified_at:
 *   when the release notification went out (NULL = not yet). STRING(32) like
 *   moviedb_series.tmdb_refreshed_at, not DATETIME, as the stamp is only ever
 *   written and compared as the "Y-m-d H:i:s" text the app produces, so it needs no
 *   database date handling (the added-before-release rule runs in PHP for the same reason).
 */
class Version000013Date20261106 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        $add = function (string $tableName, string $column, string $type, array $options) use ($schema, &$changed): void {
            if (!$schema->hasTable($tableName)) {
                return;
            }
            $table = $schema->getTable($tableName);
            if ($table->hasColumn($column)) {
                return;
            }
            $table->addColumn($column, $type, $options + ['notnull' => false]);
            $changed = true;
        };

        $add('moviedb_series', 'series_type', Types::STRING, ['length' => 32]);
        // TEXT, not JSON: a JSON column added to an existing SQLite table is reported by
        // db:schema:check as differing in type. The entity still maps it with addType('networks', 'json').
        $add('moviedb_series', 'networks', Types::TEXT, []);
        $add('moviedb_series', 'tmdb_rating', Types::SMALLINT, []);
        $add('moviedb_series', 'tmdb_vote_count', Types::INTEGER, []);
        $add('moviedb_episodes', 'notified_at', Types::STRING, ['length' => 32]);
        $add('moviedb_watchlist', 'release_notified_at', Types::STRING, ['length' => 32]);

        // The calendar and the hourly notification job look episodes up by air date and watchlist
        // entries by release date across all libraries.
        $index = function (string $tableName, string $indexName, array $columns) use ($schema, &$changed): void {
            if (!$schema->hasTable($tableName)) {
                return;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasIndex($indexName)) {
                $table->addIndex($columns, $indexName);
                $changed = true;
            }
        };
        $index('moviedb_episodes', 'moviedb_ep_air_date_idx', ['air_date']);
        $index('moviedb_watchlist', 'moviedb_wl_release_idx', ['release_date']);

        return $changed ? $schema : null;
    }
}
