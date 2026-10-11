<?php

declare(strict_types=1);

namespace OCA\MovieDB\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * v1.8.0 — quiz results.
 *
 * Creates moviedb_quiz_results: one row per finished quiz round, per user and
 * library. Purely additive (new table), so it is safe for SQLite batch mode.
 */
class Version000008Date20261011 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('moviedb_quiz_results')) {
            return null;
        }

        $table = $schema->createTable('moviedb_quiz_results');

        $table->addColumn('id', Types::INTEGER, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('library_id', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('played_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('question_count', Types::SMALLINT, [
            'notnull' => true,
        ]);
        $table->addColumn('score', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('max_score', Types::INTEGER, [
            'notnull' => true,
        ]);
        $table->addColumn('percent', Types::SMALLINT, [
            'notnull' => true,
        ]);
        $table->addColumn('breakdown', Types::JSON, [
            'notnull' => false,
        ]);

        $table->setPrimaryKey(['id'], 'moviedb_quizres_pk');
        $table->addIndex(['user_id', 'library_id'], 'moviedb_quizres_uid_lib_idx');
        $table->addIndex(['library_id'], 'moviedb_quizres_lib_idx');

        return $schema;
    }
}
