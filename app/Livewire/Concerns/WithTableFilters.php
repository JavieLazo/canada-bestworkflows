<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

/**
 * The server-side port of `src/hooks/usePagination.ts`, plus the free-text
 * search box every list screen carries.
 *
 * Pairs with `<x-kit.pagination :paginator="$rows" noun="employees" />`, which
 * binds the page-size select to `$perPage` and the arrows to Livewire's
 * `previousPage()` / `nextPage()`. The `perPage` property name is part of that
 * blade component's contract — do not rename it.
 *
 * Usage:
 *
 *     use App\Livewire\Concerns\WithTableFilters;
 *
 *     public function render()
 *     {
 *         $rows = $this->paginateClamped(
 *             EmployeeProfile::query()->...filters...
 *         );
 *         // ...
 *     }
 *
 * THE CLAMP, ported from usePagination.ts:25-31: "The page is clamped rather
 * than reset in an effect: when a filter shrinks the list the current page can
 * fall off the end, and clamping fixes that in the same render instead of
 * flashing an empty table first." Which is why `search` has no updated hook
 * that resets the page — the source never resets on filter changes either, it
 * clamps. Changing the page SIZE does reset to page 1 (usePagination.ts:55-58),
 * and `updatedPerPage` keeps that.
 */
trait WithTableFilters
{
    use WithPagination;

    /**
     * Rows per page. Client-writable by design (the page-size select binds to
     * it); `updatedPerPage` collapses anything outside
     * {@see TableDefaults::PAGE_SIZE_OPTIONS} back to the default, so it
     * cannot be driven to 0 or 100000.
     */
    public int $perPage = TableDefaults::DEFAULT_PAGE_SIZE;

    /** The screen's free-text search. Plain filter state, deliberately unlocked. */
    public string $search = '';

    /** @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $page
     * @return LengthAwarePaginator<TKey, TValue>
     */
    protected function acceptPage(LengthAwarePaginator $page, string $name = 'page'): LengthAwarePaginator
    {
        if ((int) $this->getPage($name) !== $page->currentPage()) {
            $this->setPage($page->currentPage(), $name);
        }

        return $page;
    }

    public function updatedPerPage(mixed $value): void
    {
        $size = (int) $value;

        $this->perPage = in_array($size, TableDefaults::PAGE_SIZE_OPTIONS, true)
            ? $size
            : TableDefaults::DEFAULT_PAGE_SIZE;

        // Port of setPageSize (usePagination.ts:55-58): a new size starts
        // over from page 1.
        $this->resetPage();
    }

    /**
     * Paginate with the source's clamp semantics: a current page past the end
     * of a freshly-shrunk list lands on the LAST page, not on an empty table
     * and not back at page 1.
     *
     * Accepts a query (Eloquent, base builder or relation) or an
     * already-materialised Collection/array — some screens assemble their rows
     * through the domain layer in PHP, exactly as the source paginated
     * in-memory arrays.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, mixed>|Collection<array-key, mixed>|array<array-key, mixed>  $source
     * @return LengthAwarePaginator<array-key, mixed>
     */
    protected function paginateClamped(
        EloquentBuilder|QueryBuilder|Relation|Collection|array $source,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $perPage = max(1, $this->perPage);

        if (is_array($source)) {
            $source = new Collection($source);
        }

        if ($source instanceof Collection) {
            $total = $source->count();
            $last = max(1, (int) ceil($total / $perPage));
            $page = min(max((int) $this->getPage($pageName), 1), $last);

            if ($page !== (int) $this->getPage($pageName)) {
                $this->setPage($page, $pageName);
            }

            return new LengthAwarePaginator(
                $source->slice(($page - 1) * $perPage, $perPage)->values(),
                $total,
                $perPage,
                $page,
                ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
            );
        }

        $paginator = $source->paginate($perPage, ['*'], $pageName);

        if ($paginator->currentPage() > $paginator->lastPage()) {
            $this->setPage($paginator->lastPage(), $pageName);
            $paginator = $source->paginate($perPage, ['*'], $pageName, $paginator->lastPage());
        }

        return $paginator;
    }
}
