<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * The two paging constants of `src/hooks/usePagination.ts:6-8`, shared by the
 * {@see WithTableFilters} trait and the `<x-kit.pagination>` blade component.
 *
 * A class rather than constants on the trait itself because PHP forbids
 * reading a trait constant except through a class that composes the trait —
 * and the blade component composes nothing.
 */
final class TableDefaults
{
    /** Every paginated table in the app starts here. */
    public const int DEFAULT_PAGE_SIZE = 20;

    /** @var list<int> */
    public const array PAGE_SIZE_OPTIONS = [20, 50, 100];
}
