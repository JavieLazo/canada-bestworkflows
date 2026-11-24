<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Attendance\CalendarDays;
use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Payroll\PayrollPeriod;
use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\Organization\Contracts\OrganizationReferences;
use App\Modules\Timekeeping\Application\AssignmentWriter;
use App\Modules\Timekeeping\Application\ScheduleChangeNotice;
use App\Modules\Timekeeping\Application\ScheduleGuard;
use App\Modules\Timekeeping\Contracts\OpenPayrollDrafts;
use App\Modules\Timekeeping\Contracts\PostedPayrollDates;
use App\Modules\Timekeeping\Domain\Roster\DateRuns;
use App\Modules\Timekeeping\Domain\Roster\LocationKind;
use App\Modules\Timekeeping\Infrastructure\RosterVersions;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Past dates that had NO SHIFT, given a shift or a Rest day after the fact.
 * A date with nothing rostered is measured against the engine's 8:00–5:00
 * weekday and holds the payroll that covers it until HR decides what it
 * was; this is how HR decides. It is the ONE roster write that reaches
 * back, and only onto those dates:
 *
 *   - ONLY DATES BEFORE TODAY THAT READ "No shift" — no weekday hours of
 *     their own, no assigned default shift, no temporary schedule — inside
 *     the employment (date hired to date resigned). A date that has a
 *     schedule keeps it, whatever it was; today and later change through
 *     `SaveRosterDays`, under today's cutoff. An inactive site is kept only
 *     where every date already has it, as `SaveRosterDays` keeps one.
 *   - Each run of consecutive dates becomes one temporary schedule that
 *     works the dates picked (each was picked by name), with the place
 *     chosen; a place-only schedule already on one of them gives it up.
 *   - Guarded by `ScheduleGuard::beforeFill()` / `verifyFill()`: a date on a
 *     posted payslip is refused, and the dates around the filled ones — the
 *     three before, those between, the three after — keep their records and
 *     punches exactly.
 *   - THE PUNCHES ARE NOT TOUCHED. A filled working date with none reads
 *     absent; if the employee worked it, the punches are entered through the
 *     eDTR correction, which a date with no shift already allowed.
 *
 * One transaction in the roster's lock order: payroll's membership lock,
 * the pattern, the employee row, the schedule rows, the punches. Gate:
 * all_data + ROSTER. Audit `ROSTER_UNSCHEDULED_FILLED`, best effort after
 * commit, and the employee's accounts are told.
 */
final class FillUnscheduledDates
{
    public const MAX_DATES = SaveRosterDays::MAX_DATES;

    public function __construct(private readonly EmployeeDirectory $employees, private readonly ScheduleGuard $guard) {}

    /**
     * @param  list<mixed>  $dates  'Y-m-d' strings, in any order
     * @param  string|array<array-key, mixed>  $shiftChoice  REST, a pattern id, or custom hours (`CustomHours`)
     */
    public function handle(Access $actor, string $employeeId, array $dates, string|array $shiftChoice, string $locationChoice): string
    {
        if (! $actor->mayReadAllData(ModuleCode::ROSTER)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->forRoster($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $picked = self::dates($dates);
        $shiftChoice = AssignmentWriter::choice($shiftChoice);
        if (is_string($shiftChoice) && in_array($shiftChoice, ['DEFAULT', 'KEEP'], true)) {
            throw new RuntimeException('Pick a shift or Rest day for these dates.');
        }
        $place = AssignmentWriter::place(trim($locationChoice));
        $name = EmployeeName::display($employee);
        $first = $picked[0];
        $last = $picked[count($picked) - 1];
        $drafts = [];

        DB::transaction(function () use ($actor, $employeeId, $picked, $shiftChoice, $place, $name, $first, $last, &$drafts): void {
            app(PostedPayrollDates::class)->lock();
            $pattern = AssignmentWriter::lockPattern($shiftChoice);
            if ($this->employees->forRoster($actor, $employeeId, lock: true) === null) {
                throw new RuntimeException('That employee no longer exists.');
            }
            $versions = app(RosterVersions::class);
            $versions->standingAsOf([$employeeId], $last, lock: true);
            $versions->weekdaysAsOf([$employeeId], $last, lock: true, withDefaults: true);
            $snapshot = $this->guard->beforeFill($employeeId, $picked);

            // Only dates the person was employed on: the others hold nothing
            // and had no schedule to decide.
            $employment = app(OperationalDirectory::class)->find($actor, $employeeId);
            $hired = $employment?->date_hired?->format('Y-m-d');
            $resigned = $employment?->date_resigned?->format('Y-m-d');
            $outside = array_values(array_filter($picked, static fn (string $date): bool => ($hired !== null && $date < $hired) || ($resigned !== null && $date > $resigned)));
            if ($outside !== []) {
                throw new RuntimeException("{$name} was not employed on ".DateRuns::describe($outside).'. Only dates inside the employment can be filled.');
            }
            // Read again under the locks: a date given a schedule since it was
            // picked keeps it.
            $calendar = app(ShiftRoster::class)->calendarFor([$employeeId], $first, $last)[$employeeId] ?? [];
            $scheduled = array_values(array_filter($picked, static fn (string $date): bool => ($calendar[$date]['shift'] ?? null) !== null));
            if ($scheduled !== []) {
                throw new RuntimeException(DateRuns::describe($scheduled).(count($scheduled) === 1 ? ' has' : ' have').' a schedule now. Only past dates with no shift can be filled; pick the dates again.');
            }
            // An inactive site stays only where every date already has it.
            if ($place['site_id'] !== null) {
                $already = ! in_array(false, array_map(static fn (string $date): bool => ($calendar[$date]['place']['site'] ?? null) === $place['site_id'], $picked), true);
                if (! app(OrganizationReferences::class)->siteAssignable($place['site_id'], $already ? $place['site_id'] : null)) {
                    throw new RuntimeException(AssignmentWriter::INACTIVE_SITE);
                }
            }

            $hours = null;
            foreach (DateRuns::runs($picked) as [$from, $until]) {
                foreach (AssignmentWriter::overlapping($employeeId, $from, $until) as $row) {
                    AssignmentWriter::release($actor, $row, $from, $until);
                }
                $hours = AssignmentWriter::hours($employeeId, $from, $shiftChoice, $pattern, null);
                $includeRestDays = AssignmentWriter::includeRestDays($employeeId, $name, $from, $until, $hours, workOnRestDays: true);
                AssignmentWriter::create($actor, $employeeId, $from, $until, $hours, $place, $includeRestDays);
            }
            $this->guard->verifyFill($snapshot);
            AssignmentWriter::assertPayable($employeeId, $first);
            $drafts = app(OpenPayrollDrafts::class)->touching($employeeId, $first, $last);

            assert($hours !== null);
            $description = self::sentence($actor, $name, $picked, $hours, $place);
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::ROSTER_UNSCHEDULED_FILLED, 'roster_day_overrides', $employeeId, $description));
        }, 3);

        ScheduleChangeNotice::tellDates($actor, $employeeId, $picked);

        $count = count($picked);
        $message = 'Saved: '.$count.' past '.($count === 1 ? 'date' : 'dates')." for {$name}. The DTR and payroll now measure ".($count === 1 ? 'it' : 'them').' by this schedule; enter any punches the employee made in eDTR.';
        if ($drafts !== []) {
            $codes = array_values(array_unique(array_map(static fn (array $draft): string => $draft['code'], $drafts)));
            $message .= ' Payroll '.(count($codes) === 1 ? 'draft ' : 'drafts ').implode(', ', $codes).' need a new preview.';
        }

        return $message;
    }

    /**
     * The picked dates, sorted and each once: real dates, before today, at
     * most MAX_DATES of them, within one year.
     *
     * @param  list<mixed>  $dates
     * @return non-empty-list<string>
     */
    private static function dates(array $dates): array
    {
        $today = LocalClock::todayKey();
        $picked = [];
        foreach ($dates as $date) {
            if (! is_string($date) || ! PayrollPeriod::isPayrollDate($date)) {
                throw new RuntimeException('Choose a valid calendar date.');
            }
            if ($date >= $today) {
                throw new RuntimeException('Only dates before today are filled here. Change today and later dates on their own.');
            }
            $picked[$date] = true;
        }
        $picked = array_keys($picked);
        sort($picked);
        if ($picked === []) {
            throw new RuntimeException('Pick at least one date.');
        }
        if (count($picked) > self::MAX_DATES) {
            throw new RuntimeException('Change at most '.self::MAX_DATES.' dates at once.');
        }
        if (count(CalendarDays::each($picked[0], $picked[count($picked) - 1])) > SaveAssignment::MAX_DAYS) {
            throw new RuntimeException('The dates must fall within one year.');
        }

        return $picked;
    }

    /**
     * "Ana Reyes · 2026-11-03, 2026-11-05 to 2026-11-07 · AM · weekly
     * location · had no shift".
     *
     * @param  list<string>  $dates
     * @param  array{shift_name: string|null, is_rest_day: bool}  $hours
     * @param  array{location_kind: string|null, site_id: string|null}  $place
     */
    private static function sentence(Access $actor, string $name, array $dates, array $hours, array $place): string
    {
        $spans = implode(', ', array_map(static fn (array $run): string => $run[0] === $run[1] ? $run[0] : "{$run[0]} to {$run[1]}", DateRuns::runs($dates)));
        $siteName = $place['site_id'] === null ? null : app(OrganizationDirectory::class)->sites($actor)->firstWhere('id', $place['site_id'])?->name;
        $where = $place['location_kind'] === null ? 'weekly location' : LocationKind::label($place['location_kind'], $siteName);

        return "{$name} · {$spans} · ".($hours['shift_name'] ?? 'Rest day')." · {$where} · had no shift";
    }
}
