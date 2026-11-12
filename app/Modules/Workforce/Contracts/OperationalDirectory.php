<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;
use Illuminate\Pagination\LengthAwarePaginator;
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
     * `keep` names ids the `active` filter never hides — a checklist that
     * offers active staff still lists the former employee already ticked, so
     * they can be unticked. It only exempts them from `active`: the row
     * scope, `ids`, the search and every other filter still apply.
     *
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     * @return Collection<int, DirectoryEmployee>
     */
    public function list(Access $actor, ?array $ids = null, string $search = '', array $filters = []): Collection;

    /**
     * The first `$perPage` employees {@see list()} returns for the same
     * arguments, in the same order, with the paginator's total counting EVERY
     * match. For a control that draws one page of a long directory and lets
     * the search find the rest: the database returns only the rows drawn.
     * `$perPage` is clamped to 1–100.
     *
     * @param  list<string>|null  $ids
     * @param  array{position?: string, active?: string, employmentStatus?: string, sort?: string, place?: string, keep?: list<string>}  $filters
     * @return LengthAwarePaginator<int, DirectoryEmployee>
     */
    public function firstPage(Access $actor, int $perPage, ?array $ids = null, string $search = '', array $filters = []): LengthAwarePaginator;

    /**
     * One page of an employee TABLE over the same scope and columns as
     * {@see list()}, with these table filters: `status` (INACTIVE, or a
     * status name matched by statusKey, active or not), `position` (the
     * stored title, exactly) and `search` (one phrase anywhere in the code,
     * the name as EmployeeName spells it, or the title), plus the optional
     * `place` ({@see list()}'s, applied after the scope) and `active` (ACTIVE
     * keeps active records only; ALL or absent keeps them all — with
     * `left_from`, a 'Y-m-d', ACTIVE also keeps an inactive record whose
     * date resigned is on or after it, so a week shows who left during it),
     * narrowed to `$ids` when given. Ordered by
     * `$sort`: `id` (CodeSort's twin), `name` (last, then first name), or
     * `rank` (`$rank`: employee id => position, lower first; an id with none
     * after every ranked one) — each then by the directory's own name order,
     * all in `$direction`, so desc is the exact reverse of asc. An
     * overflowing page is clamped to the last one.
     *
     * @param  array{search: string, status: string, position: string, place?: string, active?: string, left_from?: string}  $filters
     * @param  list<string>|null  $ids
     * @param  array<string, int>  $rank
     * @return LengthAwarePaginator<int, DirectoryEmployee>
     */
    public function tablePage(Access $actor, array $filters, ?array $ids, string $sort, string $direction, int $page, int $perPage, array $rank = []): LengthAwarePaginator;

    /**
     * The non-empty job titles and employment statuses held on the records
     * the actor may see (narrowed to `$ids` when given), each value as stored
     * and told apart exactly — for a table's or chooser's filter options,
     * read without loading a record.
     *
     * @param  list<string>|null  $ids
     * @return array{positions: list<string>, statuses: list<string>}
     */
    public function heldValues(Access $actor, ?array $ids = null): array;

    public function find(Access $actor, string $id): ?DirectoryEmployee;

    /**
     * Where each visible employee's position sits, as one label —
     * "Nursing · Ward 2", the department alone, or '' for a record placed
     * nowhere — keyed by employee id, for the ids given. The placement
     * Employee Records shows, for nobody outside the actor's scope.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function placeLabels(Access $actor, array $ids): array;
}
