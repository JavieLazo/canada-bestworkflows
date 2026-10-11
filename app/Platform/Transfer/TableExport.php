<?php

declare(strict_types=1);

namespace App\Platform\Transfer;

use App\Support\Access;

/**
 * The port a module implements to download one of its tables as CSV.
 *
 * `$query` is the table's own state as its Export link carries it (search,
 * filters, sort, tab) plus the route's parameters (a project, a run). The
 * implementation whitelists what it reads, authorizes the actor EAGERLY —
 * abort before returning, never inside the rows — and reads the rows
 * through the same owner query the screen renders, so the file holds
 * exactly the rows the actor can see, in the order they see them, across
 * every page.
 */
interface TableExport
{
    /** @param array<string, string> $query */
    public function export(Access $actor, array $query): CsvExport;
}
