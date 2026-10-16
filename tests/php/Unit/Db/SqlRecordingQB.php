<?php

declare(strict_types=1);

namespace OCA\MovieDB\Tests\Unit\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * QueryBuilder stub that renders a readable SQL string, so mapper tests can
 * assert which conditions, joins and parameters a query really has. Not a
 * real dialect — it only has to be precise enough to catch regressions in the
 * query shape (a dropped condition, a wrong column, a missing join).
 */
class SqlRecordingQB implements IQueryBuilder {
    private string $type = 'SELECT';
    private array $selects = [];
    private string $from = '';
    private array $joins = [];
    private array $wheres = [];
    private array $groupBys = [];
    private array $orderBys = [];
    private array $sets = [];
    private ?int $limit = null;

    public function __construct(private SqlRecording $rec) {
    }

    public function select(...$selects): static {
        $this->selects = array_merge($this->selects, $this->flatten($selects));
        return $this;
    }

    public function addSelect(...$selects): static {
        return $this->select(...$selects);
    }

    public function selectAlias(mixed $select, string $alias): static {
        $this->selects[] = $select . ' AS ' . $alias;
        return $this;
    }

    public function selectDistinct(string $select): static {
        $this->selects[] = 'DISTINCT ' . $select;
        return $this;
    }

    public function from(string $table, ?string $alias = null): static {
        $this->from = $table . ($alias ? ' ' . $alias : '');
        return $this;
    }

    public function update(string $table): static {
        $this->type = 'UPDATE';
        $this->from = $table;
        return $this;
    }

    public function set(string $column, string $value): static {
        $this->sets[] = $column . ' = ' . $value;
        return $this;
    }

    public function where(string $condition): static {
        $this->wheres = [$condition];
        return $this;
    }

    public function andWhere(string $condition): static {
        $this->wheres[] = $condition;
        return $this;
    }

    public function orWhere(string $condition): static {
        $this->wheres[] = $condition;
        return $this;
    }

    public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static {
        $this->joins[] = "LEFT JOIN $join $alias ON $condition";
        return $this;
    }

    public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static {
        $this->joins[] = "INNER JOIN $join $alias ON $condition";
        return $this;
    }

    public function groupBy(...$groupBys): static {
        $this->groupBys = array_merge($this->groupBys, $this->flatten($groupBys));
        return $this;
    }

    public function orderBy(string $sort, ?string $order = null): static {
        $this->orderBys = [trim("$sort $order")];
        return $this;
    }

    public function addOrderBy(string $sort, ?string $order = null): static {
        $this->orderBys[] = trim("$sort $order");
        return $this;
    }

    public function setMaxResults(?int $maxResults): static {
        $this->limit = $maxResults;
        return $this;
    }

    public function setFirstResult(int $firstResult): static {
        return $this;
    }

    public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string {
        $placeHolder ??= $this->rec->nextPlaceholder();
        $this->rec->params[$placeHolder] = $value;
        return $placeHolder;
    }

    public function createFunction(string $call): string {
        return $call;
    }

    public function getSQL(): string {
        if ($this->type === 'UPDATE') {
            $sql = 'UPDATE ' . $this->from . ' SET ' . implode(', ', $this->sets);
        } else {
            $sql = 'SELECT ' . implode(', ', $this->selects) . ' FROM ' . $this->from;
            if ($this->joins) {
                $sql .= ' ' . implode(' ', $this->joins);
            }
        }
        if ($this->wheres) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        if ($this->groupBys) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBys);
        }
        if ($this->orderBys) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        return $sql;
    }

    public function executeQuery(): mixed {
        $this->rec->executed[] = $this->getSQL();
        $rows = array_shift($this->rec->resultSets) ?? [];
        return new class ($rows) {
            public function __construct(private array $rows) {
            }

            public function fetch(): mixed {
                return array_shift($this->rows) ?? false;
            }

            public function closeCursor(): bool {
                return true;
            }
        };
    }

    public function executeStatement(): int {
        $this->rec->executed[] = $this->getSQL();
        return 1;
    }

    public function func(): mixed {
        return new class {
            public function count(string $x = '*', ?string $alias = null): string {
                return "COUNT($x)" . ($alias ? " AS $alias" : '');
            }

            public function max(string $x): string {
                return "MAX($x)";
            }

            public function sum(string $x): string {
                return "SUM($x)";
            }
        };
    }

    public function expr(): mixed {
        return new class {
            public function eq($a, $b): string { return "$a = $b"; }
            public function lte($a, $b): string { return "$a <= $b"; }
            public function gt($a, $b): string { return "$a > $b"; }
            public function in($a, $b): string { return "$a IN ($b)"; }
            public function isNull($a): string { return "$a IS NULL"; }
            public function isNotNull($a): string { return "$a IS NOT NULL"; }
            public function like($a, $b): string { return "$a LIKE $b"; }
            public function iLike($a, $b): string { return "$a ILIKE $b"; }
            public function castColumn($a, $type): string { return $a; }
            public function orX(...$parts): string { return '(' . implode(' OR ', $parts) . ')'; }
            public function andX(...$parts): string { return '(' . implode(' AND ', $parts) . ')'; }
        };
    }

    private function flatten(array $items): array {
        $out = [];
        foreach ($items as $item) {
            foreach ((array)$item as $i) {
                $out[] = $i;
            }
        }
        return $out;
    }
}
