<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;
use Illuminate\Support\Collection;

/** Authorized operational reads return detached identity projections, never a query builder. */
interface OperationalDirectory
{
    /** Filter `place`: every employee, whatever their position. */
    public const string PLACE_ALL = 'ALL';

    /** Filter `place`: employees linked to a position placed in no department. */
    public const string PLACE_NOT_PLACED = 'NONE';

    /**
     * `place` narrows the scoped rows to one department (its own positions
     * and its units'), one unit, or PLACE_NOT_PLACED; an unknown id matches
     * nobody. A record with no linked position is in no place.
     *
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string}  $filters
     * @return Collection<int, DirectoryEmployee>
     */
    public function list(Access $actor, ?array $ids = null, string $search = '', array $filters = []): Collection;

    public function find(Access $actor, string $id): ?DirectoryEmployee;
}
