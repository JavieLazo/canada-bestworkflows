<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Coworkers as the social screens show them beside each other: a name, a
 * position and a portrait, readable by anyone who holds Community or Chat.
 *
 * Narrower than OperationalDirectory on purpose, which only an
 * organisation-wide role may list. Pay, statutory IDs, contacts, status and
 * site never enter a Colleague, so letting more people read this does not let
 * them read more.
 */
interface ColleagueDirectory
{
    /**
     * The records behind the given employee ids, inactive ones included so an
     * old post still shows its author.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<string, Colleague> keyed by employee id
     */
    public function find(Access $actor, array $employeeIds): Collection;

    /**
     * Of the given employees, the active ones whose work anniversary falls
     * within the next $days days, today included, soonest first. First-year
     * hires are not included: a work anniversary starts at one year. Only
     * the employees given, because an anniversary is shown only for people
     * who asked for it, and who asked is the Social module's business.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<int, ColleagueAnniversary>
     */
    public function anniversaries(Access $actor, array $employeeIds, CarbonImmutable $today, int $days): Collection;

    /**
     * The same window, for birthdays. Only the accounts given, because a
     * birthday is shown only for people who asked for it, and who asked is
     * the Social module's business, not Workforce's.
     *
     * @param  list<string>  $employeeIds
     * @return Collection<int, ColleagueBirthday>
     */
    public function birthdays(Access $actor, array $employeeIds, CarbonImmutable $today, int $days): Collection;
}
