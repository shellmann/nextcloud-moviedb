<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

/**
 * State shared by all builders of one fake connection: bound parameters,
 * executed statements, and the rows each executeQuery() returns, in order.
 */
class SqlRecording {
    /** @var array<string, mixed> placeholder => value */
    public array $params = [];
    /** @var string[] rendered SQL of every executed query or statement */
    public array $executed = [];
    /** @var array<int, array<int, array<string, mixed>>> one row list per executeQuery() */
    public array $resultSets = [];
    private int $counter = 0;

    public function nextPlaceholder(): string {
        return ':p' . (++$this->counter);
    }
}
