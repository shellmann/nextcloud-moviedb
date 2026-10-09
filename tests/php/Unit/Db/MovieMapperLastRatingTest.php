<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCA\MovieDB\Db\MovieMapper;
use OCA\MovieDB\Tests\Unit\TestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * lastRating on the movie list is the rating of the latest watch (same as the
 * detail page), not the best rating across all watches. Until 1.7.0 it was
 * MAX(rating), so a movie rated 10 and later 9 showed 10 on its card but 9/10
 * on its detail page. The real SQL was checked on SQLite, MariaDB and
 * PostgreSQL; this guards the query shape.
 */
class MovieMapperLastRatingTest extends TestCase {
    /** @var LastRatingQBStub[] one per getQueryBuilder() call */
    private array $builders = [];
    private MovieMapper $mapper;

    protected function setUp(): void {
        parent::setUp();

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            return $this->builders[] = new LastRatingQBStub();
        });

        $this->mapper = new MovieMapper($db);
    }

    private function ratingSubquery(): LastRatingQBStub {
        foreach ($this->builders as $qb) {
            if (in_array('wr.rating', $qb->selects, true)) {
                return $qb;
            }
        }
        $this->fail('findAll() builds no subquery that selects the rating of a single watch');
    }

    public function testLastRatingComesFromTheLatestWatch(): void {
        $this->mapper->findAll(1);

        $sub = $this->ratingSubquery();
        $this->assertSame([['wr.watched_at', 'DESC'], ['wr.id', 'DESC']], $sub->orderBy);
        $this->assertSame(1, $sub->maxResults);
        $this->assertContains('wr.movie_id = m.id', $sub->wheres);
    }

    public function testRatingIsNotAggregatedWithMax(): void {
        $this->mapper->findAll(1);

        foreach ($this->builders as $qb) {
            $this->assertNotContains('w.rating', $qb->maxArgs, 'MAX(rating) returns the best rating, not the latest one');
        }
    }

    public function testSortingByRatingUsesTheLatestRating(): void {
        $this->mapper->findAll(1, ['sort' => 'rating', 'dir' => 'DESC']);

        $this->assertSame([['last_rating', 'DESC']], $this->builders[0]->orderBy);
    }
}

/**
 * Minimal query builder that records the calls findAll() makes.
 */
class LastRatingQBStub implements IQueryBuilder {
    public array $selects = [];
    public array $wheres = [];
    public array $orderBy = [];
    public array $maxArgs = [];
    public ?int $maxResults = null;

    public function select(...$selects): static { array_push($this->selects, ...(is_array($selects[0] ?? null) ? $selects[0] : $selects)); return $this; }
    public function addSelect(...$selects): static { return $this; }
    public function selectAlias(mixed $select, string $alias): static { return $this; }
    public function selectDistinct(string $select): static { return $this; }
    public function from(string $table, ?string $alias = null): static { return $this; }
    public function where(string $condition): static { $this->wheres[] = $condition; return $this; }
    public function andWhere(string $condition): static { $this->wheres[] = $condition; return $this; }
    public function orWhere(string $condition): static { return $this; }
    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static { return $this; }
    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static { return $this; }
    public function groupBy(...$groupBys): static { return $this; }
    public function orderBy(string $sort, ?string $order = null): static { $this->orderBy = [[$sort, $order]]; return $this; }
    public function addOrderBy(string $sort, ?string $order = null): static { $this->orderBy[] = [$sort, $order]; return $this; }
    public function setMaxResults(?int $maxResults): static { $this->maxResults ??= $maxResults; return $this; }
    public function setFirstResult(int $firstResult): static { return $this; }
    public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string { return $placeHolder ?? ':p'; }
    public function createFunction(string $call): string { return $call; }
    public function getSQL(): string { return 'SELECT 1'; }

    public function func(): mixed {
        $stub = $this;
        return new class($stub) {
            public function __construct(private LastRatingQBStub $stub) {}
            public function max(string $column): string { $this->stub->maxArgs[] = $column; return 'MAX(' . $column . ')'; }
            public function count(...$_): string { return 'COUNT(*)'; }
        };
    }

    public function expr(): mixed {
        return new class {
            public function __call(string $_name, array $_args): string { return '1=1'; }
        };
    }

    public function executeQuery(): mixed {
        return new class {
            public function fetch(): mixed { return false; }
            public function closeCursor(): bool { return true; }
        };
    }
}
