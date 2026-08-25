<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Unit tests for MovieMapper::countAll with every filter type.
 *
 * Regression guard: countAll previously used no table alias while applyFilters
 * referenced m.genre_ids / m.release_year / m.id / m.title / m.is_favorite,
 * causing a SQL error (and a 500) the moment any filter was applied. The fix
 * adds alias 'm' to the FROM clause and prefixes the user_id WHERE clause.
 *
 * These tests verify countAll accepts every filter without throwing, using a
 * named QB stub that implements IQueryBuilder so applyFilters' typehint passes.
 */
class MovieMapperCountAllTest extends TestCase {
    private MovieMapper $mapper;
    private FluentQBStub $qbStub;

    protected function setUp(): void {
        parent::setUp();

        $this->qbStub = new FluentQBStub();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->qbStub);
        $db->method('escapeLikeParameter')->willReturnArgument(0);

        $this->mapper = new MovieMapper($db);
    }

    public function testCountAllNoFiltersCallsExecuteQuery(): void {
        $count = $this->mapper->countAll('user1');
        $this->assertEquals(7, $count);
    }

    public function testCountAllUsesTableAliasM(): void {
        // The FROM clause must include the 'm' alias so applyFilters' m.* refs resolve
        $this->mapper->countAll('user1');
        $fromArgs = $this->qbStub->fromCalls[0] ?? [];
        $this->assertEquals('m', $fromArgs[1] ?? null,
            'countAll must alias the table as "m" so applyFilters can reference m.genre_ids etc.');
    }

    public function testCountAllWithGenreFilterDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', ['genre' => 28]);
        $this->assertEquals(7, $count);
    }

    public function testCountAllWithYearFilterDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', ['year' => 2024]);
        $this->assertEquals(7, $count);
    }

    public function testCountAllWithSearchFilterDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', ['search' => 'matrix']);
        $this->assertEquals(7, $count);
    }

    public function testCountAllWithFavoriteFilterDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', ['favorite' => true]);
        $this->assertEquals(7, $count);
    }

    public function testCountAllWithPlatformFilterDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', ['platform' => 3]);
        $this->assertEquals(7, $count);
    }

    public function testCountAllWithAllFiltersDoesNotThrow(): void {
        $count = $this->mapper->countAll('user1', [
            'genre'    => 28,
            'year'     => 2024,
            'search'   => 'matrix',
            'favorite' => true,
            'platform' => 2,
        ]);
        $this->assertEquals(7, $count);
    }
}

/**
 * Fluent QueryBuilder stub that satisfies the IQueryBuilder typehint.
 * Returns $this for every builder method and records from() calls for
 * assertions.
 */
class FluentQBStub implements IQueryBuilder {
    public array $fromCalls = [];

    public function select(...$_): static { return $this; }
    public function addSelect(...$_): static { return $this; }

    public function from(string $table, ?string $alias = null): static {
        $this->fromCalls[] = [$table, $alias];
        return $this;
    }

    public function where(string $_): static { return $this; }
    public function andWhere(string $_): static { return $this; }
    public function orWhere(string $_): static { return $this; }
    public function orderBy(string $sort, ?string $order = null): static { return $this; }
    public function setMaxResults(?int $_): static { return $this; }
    public function setFirstResult(int $_): static { return $this; }
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
            public function sum(...$_): string { return 'SUM(x)'; }
            public function max(...$_): string { return 'MAX(x)'; }
        };
    }

    public function expr(): mixed {
        return new class {
            public function eq(...$_): string { return '1=1'; }
            public function like(...$_): string { return '1=1'; }
            public function iLike(...$_): string { return '1=1'; }
            public function orX(...$_): string { return '1=1'; }
            public function andX(...$_): string { return '1=1'; }
            public function in(...$_): string { return '1=1'; }
            public function isNotNull(...$_): string { return '1=1'; }
            public function castColumn(...$_): string { return 'CAST(col AS TEXT)'; }
        };
    }
}
