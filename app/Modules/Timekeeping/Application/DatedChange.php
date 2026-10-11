<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Timekeeping\Contracts\OpenPayrollDrafts;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Roster\EffectiveDatePolicy;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs ONE schedule change for one or more employees under every safeguard,
 * so no entry point can skip one:
 *
 *   - the date is today or later, never the past;
 *   - with no date asked for, it is today while today's cutoff allows and
 *     the next date once it does not (D4); a change that asks for today by
 *     name after the cutoff is refused with the earliest date it can use;
 *   - `ScheduleGuard` holds the dates before it, and yesterday's night
 *     shift, exactly as they were, and refuses a new overlap (D5);
 *   - what it affects is returned as a `ScheduleImpact`, and a change whose
 *     impact needs confirming is saved only with that impact's token.
 *
 * Everything happens in one transaction: payroll's membership lock first
 * (PostedPayrollDates requires it before any read), then the caller's own
 * locks, then each employee row in id order, then that employee's punches
 * and schedule rows. `$write` receives the employee and the date and returns
 * whether it wrote anything; a refusal anywhere rolls every employee back.
 *
 * `preview()` runs the same body and always rolls back.
 */
final class DatedChange
{
    /** Accepts whatever the impact is. Reachable from no screen: a client never sends a confirmation, only a held token. */
    public const ACCEPT = '*';

    public function __construct(private readonly ScheduleGuard $guard, private readonly ShiftRoster $roster) {}

    /**
     * Run a change for a FILE IMPORT, whose row cannot answer a review. A
     * change that could not start on the date the row asked for is REFUSED
     * with the reason — the row names its date instead, so nothing is moved
     * to another day unseen. What a change affects on the date it does start
     * — rest-day flips inside a temporary schedule, open payroll drafts
     * (which the payroll workspace flags) — is accepted, the import's own
     * preview being the confirmation.
     *
     * @template T
     *
     * @param  Closure(?string): T  $attempt  runs the command with the confirmation to send
     * @return T
     *
     * @throws ScheduleChangeRefused
     */
    public static function forImport(Closure $attempt): mixed
    {
        try {
            return $attempt(null);
        } catch (ScheduleImpactPending $pending) {
            $moved = array_intersect_key($pending->impact->effective, $pending->impact->deferred);
            if ($moved !== []) {
                $reasons = implode('; ', array_unique($pending->impact->deferred));
                $next = HrisFormat::date(min($moved));
                throw new ScheduleChangeRefused('This change can no longer start today: '.$reasons.'. Put '.$next.' or a later date in Effective from to start it then.');
            }

            return $attempt($pending->impact->token());
        }
    }

    /**
     * @param  list<string>  $employeeIds
     * @param  Closure(string, string): bool  $write  (employee id, effective date) => wrote something
     * @param  Closure(): void|null  $prepare  locks the caller takes before any employee (a pattern row)
     *
     * @throws ScheduleChangeRefused
     * @throws ScheduleImpactPending
     */
    public function run(Access $actor, array $employeeIds, ?string $requested, ?string $to, Closure $write, ?string $confirmed = null, ?Closure $prepare = null): ScheduleImpact
    {
        return $this->execute($actor, $employeeIds, $requested, $to, $write, $confirmed, $prepare, commit: true);
    }

    /**
     * What run() would do, with nothing saved.
     *
     * @param  list<string>  $employeeIds
     * @param  Closure(string, string): bool  $write
     * @param  Closure(): void|null  $prepare
     *
     * @throws ScheduleChangeRefused
     */
    public function preview(Access $actor, array $employeeIds, ?string $requested, ?string $to, Closure $write, ?Closure $prepare = null): ScheduleImpact
    {
        return $this->execute($actor, $employeeIds, $requested, $to, $write, self::ACCEPT, $prepare, commit: false);
    }

    /**
     * @param  list<string>  $employeeIds
     * @param  Closure(string, string): bool  $write
     * @param  Closure(): void|null  $prepare
     */
    private function execute(Access $actor, array $employeeIds, ?string $requested, ?string $to, Closure $write, ?string $confirmed, ?Closure $prepare, bool $commit): ScheduleImpact
    {
        $today = ManilaClock::todayKey();
        $error = $requested === null ? null : EffectiveDatePolicy::dateError($requested, $today);
        $error ??= $to === null ? null : EffectiveDatePolicy::dateError($to, $today);
        if ($error !== null) {
            throw new ScheduleChangeRefused($error);
        }
        $employeeIds = array_values(array_unique($employeeIds));
        sort($employeeIds);
        $single = count($employeeIds) === 1;
        $impact = null;

        try {
            DB::transaction(function () use ($actor, $employeeIds, $requested, $to, $write, $confirmed, $prepare, $commit, $today, $single, &$impact): void {
                app(PostedPayrollDates::class)->lock();
                if ($prepare !== null) {
                    $prepare();
                }
                $effective = $deferred = $restDays = $drafts = [];
                foreach ($employeeIds as $employeeId) {
                    if (app(EmployeeDirectory::class)->forRoster($actor, $employeeId, lock: true) === null) {
                        throw new RuntimeException('That employee no longer exists.');
                    }
                    $from = $requested ?? $today;
                    try {
                        $changes = $this->attempt($employeeId, $from, $to, $write);
                    } catch (TodayNotEligible $late) {
                        $next = CalendarDays::next($today);
                        if ($requested !== null && ($single || ($to !== null && $to < $next))) {
                            throw new ScheduleChangeRefused('This change can no longer take effect today: '.$late->reason.'. The earliest it can start is '.HrisFormat::date($next).'.');
                        }
                        $from = $next;
                        $deferred[$employeeId] = $late->reason;
                        $changes = $this->attempt($employeeId, $from, $to, $write);
                    }
                    if ($changes === null) {
                        unset($deferred[$employeeId]);

                        continue;
                    }
                    $effective[$employeeId] = $from;
                    array_push($restDays, ...$changes);
                    foreach (app(OpenPayrollDrafts::class)->touching($employeeId, $from, $to) as $draft) {
                        $drafts[] = ['employee_id' => $employeeId] + $draft;
                    }
                }
                $impact = new ScheduleImpact($effective, $deferred, $restDays, $drafts);
                if (! $commit) {
                    throw new PreviewOnly;
                }
                if ($impact->changed() && $impact->needsConfirmation() && $confirmed !== self::ACCEPT && $confirmed !== $impact->token()) {
                    throw new ScheduleImpactPending($impact);
                }
            }, 3);
        } catch (PreviewOnly) {
            // Rolled back on purpose: the impact is all a preview keeps.
        }
        assert($impact instanceof ScheduleImpact);

        return $impact;
    }

    /**
     * One employee at one date, in a savepoint: a cutoff that has passed
     * undoes only this attempt, so the next date can be tried.
     *
     * @param  Closure(string, string): bool  $write
     * @return list<array{employee_id: string, date: string, works: bool}>|null null when nothing was written
     */
    private function attempt(string $employeeId, string $from, ?string $to, Closure $write): ?array
    {
        return DB::transaction(function () use ($employeeId, $from, $to, $write): ?array {
            $before = $this->guard->before($employeeId, $from, $to);
            // Only a change to the weekly schedule can flip a date inside a
            // temporary one as a side effect; a temporary schedule's own
            // dates are the change the person asked for.
            $temporary = $to === null ? $this->temporaryDates($employeeId, $from) : [];
            $worked = $this->worksOn($employeeId, $temporary);
            if (! $write($employeeId, $from)) {
                return null;
            }
            $this->guard->verify($before);

            $changes = [];
            foreach ($this->worksOn($employeeId, $temporary) as $date => $works) {
                if ($works !== $worked[$date]) {
                    $changes[] = ['employee_id' => $employeeId, 'date' => $date, 'works' => $works];
                }
            }

            return $changes;
        });
    }

    /**
     * The dates from `$from` on that a temporary schedule with hours covers
     * while keeping rest days — the only dates whose working day a change
     * to the weekly schedule beneath them can flip (D6).
     *
     * @return list<string>
     */
    private function temporaryDates(string $employeeId, string $from): array
    {
        $dates = [];
        foreach (app(RosterVersions::class)->assignments([$employeeId], $from, null) as $row) {
            if ($row->shift_data === null || $row->is_rest_day || $row->include_rest_days) {
                continue;
            }
            $first = max($from, $row->date->format('Y-m-d'));
            $last = min(RosterVersions::appliesThrough($row), PayrollPeriod::addCalendarDays($from, 366));
            foreach ($first <= $last ? CalendarDays::each($first, $last) : [] as $date) {
                $dates[$date] = true;
            }
        }
        ksort($dates);

        return array_keys($dates);
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, bool>
     */
    private function worksOn(string $employeeId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $schedule = $this->roster->scheduleForEmployee($employeeId, $dates[0], $dates[count($dates) - 1]) ?? DomainShift::default();
        $works = [];
        foreach ($dates as $date) {
            $works[$date] = ShiftResolver::worksOnDate($date, $schedule);
        }

        return $works;
    }
}
