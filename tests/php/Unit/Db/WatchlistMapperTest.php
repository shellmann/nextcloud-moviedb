<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\WatchlistMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Unit tests for WatchlistMapper pagination and filter application.
 *
 * Regression guard: findAll previously ignored limit/offset entirely
 * (returning the full unbounded set), and countAll ignored filters, so a
 * paginated total wouldn't match a filtered result set.
 */
class WatchlistMapperTest extends TestCase {
    private WatchlistMapper $mapper;
    private RecordingWatchlistQBStub $qbStub;

    protected function setUp(): void {
        parent::setUp();

        $this->qbStub = new RecordingWatchlistQBStub();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->qbStub);
        $db->method('escapeLikeParameter')->willReturnArgument(0);

        $this->mapper = new WatchlistMapper($db);
    }

    public function testFindAllAppliesLimitAndOffset(): void {
        $this->mapper->findAll(1, [], 10, 20);

        $this->assertSame([10], $this->qbStub->maxResultsCalls);
        $this->assertSame([20], $this->qbStub->firstResultCalls);
    }

    public function testFindAllDefaultsToLimitFiftyOffsetZero(): void {
        $this->mapper->findAll(1);

        $this->assertSame([50], $this->qbStub->maxResultsCalls);
        $this->assertSame([0], $this->qbStub->firstResultCalls);
    }

    public function testCountAllAppliesSearchFilter(): void {
        $this->mapper->countAll(1, ['search' => 'dune']);

        $this->assertContains('title', $this->qbStub->iLikeColumns);
    }

    public function testCountAllAppliesMediaTypeFilter(): void {
        $this->mapper->countAll(1, ['mediaType' => 'series']);

        $this->assertContains('media_type', $this->qbStub->eqColumns);
    }

    public function testCountAllWithNoFiltersDoesNotThrow(): void {
        $count = $this->mapper->countAll(1);
        $this->assertEquals(7, $count);
    }
}

/**
 * Fluent QueryBuilder stub that satisfies the IQueryBuilder typehint and
 * records the calls these tests assert on.
 */
class RecordingWatchlistQBStub implements IQueryBuilder {
    public array $maxResultsCalls = [];
    public array $firstResultCalls = [];
    public array $iLikeColumns = [];
    public array $eqColumns = [];

    public function select(...$_): static { return $this; }
    public function addSelect(...$_): static { return $this; }
    public function from(string $table, ?string $alias = null): static { return $this; }
    public function where(string $_): static { return $this; }
    public function andWhere(string $_): static { return $this; }
    public function orWhere(string $_): static { return $this; }
    public function orderBy(string $sort, ?string $order = null): static { return $this; }

    public function setMaxResults(?int $limit): static {
        $this->maxResultsCalls[] = $limit;
        return $this;
    }

    public function setFirstResult(int $offset): static {
        $this->firstResultCalls[] = $offset;
        return $this;
    }

    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static { return $this; }
    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static { return $this; }
    public function groupBy(...$_): static { return $this; }
    public function selectAlias(mixed $select, string $alias): static { return $this; }
    public function selectDistinct(string $_): static { return $this; }

    public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string {
        return (string)$value;
    }

    public function createFunction(string $call): string { return $call; }

    public function getSQL(): string { return 'SELECT 1'; }

    public function executeQuery(): mixed {
        return new class {
            public function fetch(): array { return ['count' => 7]; }
            public function closeCursor(): bool { return true; }
        };
    }

    public function func(): mixed {
        return new class {
            public function count(...$_): string { return 'COUNT(*)'; }
        };
    }

    public function expr(): mixed {
        return new class ($this) {
            private RecordingWatchlistQBStub $stub;

            public function __construct(RecordingWatchlistQBStub $stub) {
                $this->stub = $stub;
            }

            public function eq(string $column, ...$_): string {
                $this->stub->eqColumns[] = $column;
                return '1=1';
            }

            public function iLike(string $column, ...$_): string {
                $this->stub->iLikeColumns[] = $column;
                return '1=1';
            }
        };
    }
}
