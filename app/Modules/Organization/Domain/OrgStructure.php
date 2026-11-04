<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain;

/**
 * The two fixed levels — departments, and the units inside each one — as HR
 * ordered them. One place decides that order, which department a row belongs
 * to and which rows a department filter selects, so the Departments & Units
 * page, the Position page and every department filter read the structure the
 * same way.
 *
 * Built from plain rows (`id`, `kind`, `parent_id`, `name`, `sort_order`);
 * no persistence, clock or framework I/O. Siblings come in `sort_order`, then
 * name (case-insensitive), then id, so two rows with the same number still
 * have one order. A unit whose parent is missing or is not a department is
 * left out of every walk: the commands never write one, and a corrupt row
 * must not drag a stranger into a department's list.
 */
final class OrgStructure
{
    public const string DEPARTMENT = 'DEPARTMENT';

    public const string UNIT = 'UNIT';

    /** The filter value that matches every row. */
    public const string ALL = 'ALL';

    /** The filter value that matches only what is not placed anywhere. */
    public const string NONE = 'NONE';

    /** @var array<string, array{id: string, kind: string, parent_id: string|null, name: string, sort_order: int}> */
    private array $nodes = [];

    /** @param iterable<array{id: string, kind: string, parent_id?: string|null, name: string, sort_order?: int|string|null}> $rows */
    public function __construct(iterable $rows)
    {
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $this->nodes[$id] = [
                'id' => $id,
                'kind' => (string) $row['kind'],
                'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== '' ? (string) $row['parent_id'] : null,
                'name' => (string) $row['name'],
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }
    }

    public function exists(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function isDepartment(string $id): bool
    {
        return ($this->nodes[$id]['kind'] ?? null) === self::DEPARTMENT && $this->nodes[$id]['parent_id'] === null;
    }

    /** A unit whose parent is a department; any other UNIT row is corrupt and treated as absent. */
    public function isUnit(string $id): bool
    {
        $node = $this->nodes[$id] ?? null;

        return $node !== null && $node['kind'] === self::UNIT && $node['parent_id'] !== null && $this->isDepartment($node['parent_id']);
    }

    public function name(string $id): ?string
    {
        return $this->nodes[$id]['name'] ?? null;
    }

    /** The department a row belongs to: itself for a department, its parent for a unit. */
    public function departmentOf(string $id): ?string
    {
        if ($this->isDepartment($id)) {
            return $id;
        }

        return $this->isUnit($id) ? $this->nodes[$id]['parent_id'] : null;
    }

    /** @return list<string> department ids in HR order */
    public function departments(): array
    {
        return $this->sorted(array_filter($this->nodes, fn (array $node): bool => $this->isDepartment($node['id'])));
    }

    /** @return list<string> the department's unit ids in HR order */
    public function unitsOf(string $departmentId): array
    {
        if (! $this->isDepartment($departmentId)) {
            return [];
        }

        return $this->sorted(array_filter($this->nodes, fn (array $node): bool => $this->isUnit($node['id']) && $node['parent_id'] === $departmentId));
    }

    /**
     * Every row in HR order: each department, followed at once by its units.
     *
     * @return list<string>
     */
    public function ordered(): array
    {
        $ids = [];
        foreach ($this->departments() as $department) {
            $ids[] = $department;
            foreach ($this->unitsOf($department) as $unit) {
                $ids[] = $unit;
            }
        }

        return $ids;
    }

    /**
     * The rows that share this one's place: the departments for a department,
     * the department's units for a unit — the list Move up / Move down walks.
     *
     * @return list<string>
     */
    public function siblingsOf(string $id): array
    {
        if ($this->isDepartment($id)) {
            return $this->departments();
        }

        return $this->isUnit($id) ? $this->unitsOf((string) $this->nodes[$id]['parent_id']) : [];
    }

    /**
     * The row ids a department filter selects, for an `IN (…)` over a
     * placement column: a department and its units, one unit, or none for an
     * id that is not in the structure. `ALL` and `NONE` are not lists of ids
     * and return null.
     *
     * @return list<string>|null
     */
    public function selection(string $filter): ?array
    {
        if ($filter === self::ALL || $filter === self::NONE) {
            return null;
        }
        if ($this->isDepartment($filter)) {
            return [$filter, ...$this->unitsOf($filter)];
        }

        return $this->isUnit($filter) ? [$filter] : [];
    }

    /**
     * `$ordered` with `$id` swapped with its neighbour, or null when there is
     * nothing to swap: the id is not in the list, or it is already at that end.
     *
     * @param  list<string>  $ordered
     * @return list<string>|null
     */
    public static function swap(array $ordered, string $id, string $direction): ?array
    {
        $at = array_search($id, $ordered, true);
        if ($at === false) {
            return null;
        }
        $to = $direction === 'up' ? $at - 1 : ($direction === 'down' ? $at + 1 : -1);
        if ($to < 0 || $to >= count($ordered)) {
            return null;
        }
        [$ordered[$at], $ordered[$to]] = [$ordered[$to], $ordered[$at]];

        return array_values($ordered);
    }

    /**
     * Rows of one place — a department's units, or one place's positions —
     * in HR order: `sort_order`, then name, then id.
     *
     * @param  iterable<array{id: string, name: string, sort_order?: int|string|null}>  $rows
     * @return list<string>
     */
    public static function order(iterable $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'sort_order' => (int) ($row['sort_order'] ?? 0)];
        }
        usort($list, static fn (array $a, array $b): int => [$a['sort_order'], mb_strtolower($a['name']), $a['id']] <=> [$b['sort_order'], mb_strtolower($b['name']), $b['id']]);

        return array_column($list, 'id');
    }

    /**
     * @param  array<string, array{id: string, kind: string, parent_id: string|null, name: string, sort_order: int}>  $nodes
     * @return list<string>
     */
    private function sorted(array $nodes): array
    {
        return self::order($nodes);
    }
}
