<?php

declare(strict_types=1);

namespace App\Platform\ReadModels;

use App\Support\CodeSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/** Shared clamp and allow-listed ordering mechanics; owners supply the scoped query. */
final class QueryPage
{
    /** @template TModel of Model
     * @template TView of Record
     *
     * @param  Builder<TModel>  $query
     * @param  class-string<TView>  $view
     * @return LengthAwarePaginator<int, TView>
     */
    public static function get(Builder $query, int $page, int $perPage, string $view, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $rows = $query->paginate($perPage, ['*'], $pageName, max(1, $page));
        if ($rows->currentPage() > $rows->lastPage()) {
            $rows = $query->paginate($perPage, ['*'], $pageName, $rows->lastPage());
        }

        return $rows->through(static fn (Model $row): Record => Snapshot::of($row, $view));
    }

    /**
     * The same clamp over rows an owner query assembled itself (rows derived
     * from the record or kept from legacy columns beside its stored ones).
     * A page past the last one lands on the last, as `get()` does.
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @return LengthAwarePaginator<int, T>
     */
    public static function items(Collection $items, int $page, int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));
        $total = $items->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $last);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['pageName' => $pageName, 'path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * `$numericKeys` names the keys whose column holds a minted id with NO
     * prefix. Those are bare numbers, so ordering them as text reads
     * 1, 10, 2 — they go through {@see SqlText::orderNumeric} instead, which
     * is the SQL twin of {@see CodeSort::compare}.
     *
     * A project or task code is NOT one of them: those carry `PRJ-001`/`TSK-014`
     * and their padding, so they sort correctly as text and must be left out of
     * this list.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, literal-string>  $columns
     * @param  list<string>  $numericKeys
     * @return Builder<T>
     */
    public static function sort(Builder $query, string $key, string $direction, array $columns, array $numericKeys = []): Builder
    {
        $column = $columns[$key] ?? null;
        if ($column === null) {
            return $query;
        }

        if (in_array($key, $numericKeys, true)) {
            SqlText::orderNumeric($query, $column, $direction);

            return $query;
        }

        $query->orderByRaw("case when {$column} is null then 1 else 0 end")
            ->orderBy($column, $direction === 'desc' ? 'desc' : 'asc');

        return $query;
    }
}
