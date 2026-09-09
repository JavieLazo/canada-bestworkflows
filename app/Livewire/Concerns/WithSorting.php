<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The server-side port of `src/hooks/useSort.ts`.
 *
 * One sortable column at a time, exactly like the source: clicking the same
 * column flips the direction, clicking a new column starts ascending
 * (useSort.ts:21-28).
 *
 * Pairs with `<x-kit.sort-header>`, which calls `$wire.sortBy('key')` and
 * reads `$wire.sortKey` / `$wire.sortDir` for the caret and `aria-sort`.
 * Those two property names are part of the blade component's contract —
 * do not rename them.
 *
 * Usage:
 *
 *     use App\Livewire\Concerns\WithSorting;
 *
 *     class EmployeesIndex extends Component
 *     {
 *         use WithSorting;
 *
 *         public function mount(): void
 *         {
 *             $this->sortKey = 'name';          // the initial sort
 *         }
 *
 *         protected function sortableKeys(): array
 *         {
 *             return ['name', 'code', 'date_hired'];
 *         }
 *
 *         public function render()
 *         {
 *             $rows = $this->applySort(EmployeeProfile::query(), [
 *                 'name' => 'last_name',
 *                 'code' => 'employee_code',
 *                 'date_hired' => 'date_hired',
 *             ])->get();
 *             // ...
 *         }
 *     }
 *
 * SECURITY: `sortBy` is a client-callable action, so the key it receives is
 * client-supplied. It is checked against `sortableKeys()` — the allow-list
 * every component must declare — and anything else is dropped on the floor.
 * `applySort` additionally only ever orders by the columns named in ITS map,
 * so a sort key can never reach `orderBy` as raw input.
 */
trait WithSorting
{
    /**
     * The active sort key. NOT #[Locked]: it is meant to be driven from the
     * client through `sortBy()`, which validates it, and it carries no id and
     * no authorization weight. Set the initial key in `mount()` — trait
     * properties cannot be re-declared with a different default.
     */
    public string $sortKey = '';

    /** 'asc' or 'desc'. Anything else is normalised to 'asc' when applied. */
    public string $sortDir = 'asc';

    /**
     * Port of `useSort.toggle` (useSort.ts:21-28): same column flips the
     * direction; a new column starts ascending.
     */
    public function sortBy(string $key): void
    {
        if (! in_array($key, $this->sortableKeys(), true)) {
            return; // unknown or tampered key: fail closed, keep the current sort
        }

        if ($key === $this->sortKey) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortKey = $key;
        $this->sortDir = 'asc';
    }

    /**
     * The allow-list of keys `sortBy()` accepts — every component using this
     * trait declares its own, mirroring the `K extends string` union each
     * screen passed to `useSort<K>()` in the source.
     *
     * @return list<string>
     */
    abstract protected function sortableKeys(): array;

    /**
     * Order a query by the active sort key.
     *
     * `$columns` maps sort keys to either a column name (ordered with NULLs
     * last in BOTH directions — the SQL half of `compareText`'s "always sorts
     * blanks last, whichever direction", useSort.ts:33-40) or a
     * `Closure(Builder $query, string $dir)` for anything fancier (joins,
     * empty-string-last on text columns, computed columns).
     *
     * A key with no mapping — including the '' a component starts with before
     * `mount()` sets one — leaves the query untouched.
     *
     * @template TQuery of EloquentBuilder|QueryBuilder|Relation
     *
     * @param  TQuery  $query
     * @param  array<string, literal-string|Closure>  $columns
     * @return TQuery
     */
    protected function applySort(EloquentBuilder|QueryBuilder|Relation $query, array $columns): EloquentBuilder|QueryBuilder|Relation
    {
        $target = $columns[$this->sortKey] ?? null;

        if ($target === null) {
            return $query;
        }

        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';

        if ($target instanceof Closure) {
            $target($query, $dir);

            return $query;
        }

        // $target is a column name written by the component author, never
        // client input — the literal-string param type enforces exactly that,
        // which is also what makes interpolating it here safe.
        // Ordered on the SAME builder instance, so TQuery flows through
        // unchanged.
        $query->orderByRaw("case when {$target} is null then 1 else 0 end");
        $query->orderBy($target, $dir);

        return $query;
    }
}
