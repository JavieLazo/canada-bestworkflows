<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Minutes;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Support\Money;
use DateTimeInterface;
use RuntimeException;

/**
 * A literal port of `computePayslip()` from copems/src/lib/payroll.ts:358-719.
 *
 * ── The rules this file exists to hold ───────────────────────────────────────
 *
 * 1. Money::round() is applied at EXACTLY the same points as the reference's
 *    `round()`, and nowhere else. Twenty-six textual call sites are reachable
 *    from one payslip: twenty-five inside computePayslip (payroll.ts lines 493,
 *    497, 498, 499, 500, 510, 514, 528, 556, 557, 560, 561, 580, 581, 589, 598,
 *    607, 616, 625, 634, 652, 672, 680, 700, 717) plus the one inside
 *    normalizedEmployeeDeductions (line 319), which lives on PayslipDraft here.
 *    Each is tagged [R01]…[R26] below. A twenty-seventh changes payslips.
 *    [R21], the one round of the withholding tax, sits inside
 *    StatutoryLines::withholding(), called here: the payroll application's
 *    re-derivation (and any later withholding on a supplementary payment)
 *    must round the tax at the same point, so it has one body. It is still
 *    exactly one site.
 *
 *    THE EXCEPTION, and the only one: DIVERGENCE 6 adds three sites the
 *    reference has no counterpart for — [R01a], [R02a] and [R02b] — which
 *    split approved paid leave onto its own payslip line. They are lettered,
 *    not numbered, precisely because they are NOT reference sites. They move
 *    no money: [R02b] carves the leave line out of [R02]'s one rounded total
 *    instead of rounding a second product, so gross_pay is unchanged. See the
 *    day-bucket comment in compute() and PayslipGoldenVectorTest's sixth
 *    divergence.
 *
 * 2. Plain PHP floats end to end. No arbitrary-precision library, no integer
 *    centavos, no 'decimal:N' cast. `$daily` and `$hourly` are kept UNROUNDED
 *    on purpose — integer centavos cannot hold 25000/24 = 1041.6666666666667,
 *    and forcing them moves real money (basic_pay 9,375.00 → 9,374.99, and 8h
 *    of overtime 1,302.08 → 1,302.10).
 *
 * 3. Pay is ADDITIVE. `basic_pay` is the sum of three DISJOINT day buckets
 *    (work_days, holiday_days, rest_days), never the monthly rate less
 *    absences. A worked holiday is priced once, in holiday_pay.
 *
 * 4. `net_pay` is deliberately unclamped and CAN be negative. There is no
 *    max(0, …) on it and there must never be one — PayrollReadiness /
 *    payrollDraftError is what refuses to finalize such a run.
 *
 * ── Two distinct rounding boundaries, deliberately not mixed ─────────────────
 *
 *   Money::round()    centavos.  Math.round(n * 100) / 100.  Every peso below.
 *   Minutes::round()  whole minutes.  Math.round(n).  The per-day overtime cap
 *                     at payroll.ts:538 only.
 *
 * PHP's own round() is banned in this namespace and an architecture test
 * enforces it: it rounds half-away-from-zero where both of these round half-up.
 */
final class PayslipCalculator
{
    /**
     * payroll.ts:165 — HOLIDAY_OT_PREMIUM.
     *
     * Overtime worked on a premium day is paid at the day's own rate and then
     * at 130% of it, which is how the DOLE matrix reads:
     *
     *   ordinary working day        hourly x 125%
     *   special non-working day     hourly x 130% x 130%
     *   regular holiday             hourly x 200% x 130%
     *
     * The first factor is the holiday's own `percent`, so raising Christmas to
     * 250% raises its overtime with it. The 125% for an ordinary day is
     * separate and stays configurable in Formulas → OT.
     *
     * A rest day is the other premium day and takes Formulas → Rest Day
     * instead: its own 130%, then the ordinary overtime rate. A holiday that
     * lands on a rest day is compounded rather than approximated — the
     * holiday's percent times the Rest Day percentage, for the day and for its
     * overtime alike, which is the matrix's compounded row.
     */
    public const HOLIDAY_OT_PREMIUM = 1.3;

    /**
     * payroll.ts:136 — PAYROLL_DEFAULTS.workingHours.
     *
     * Only a guard for a shift that describes no working day at all (a fixed
     * shift starting and ending at the same time, or a flexible one owing zero
     * hours). The hours in a day come from the roster — see
     * ShiftResolver::paidShiftMinutes() — never from here, and this is not a
     * setting anybody edits.
     */
    public const DEFAULT_WORKING_HOURS = 8.0;

    /**
     * TS: `computePayslip(args)`.
     *
     * @param  object  $employee  The 201 file. Read STRUCTURALLY, exactly as the
     *                            reference's `EmployeeProfile` type is: an Eloquent
     *                            model and a readonly DTO both satisfy it. Snake_case
     *                            column names, matching schema.prisma.
     * @param  list<AttendanceLog>  $logs
     * @param  list<LeaveRequest>  $leaves
     * @param  string  $periodStart  'Y-m-d', a Manila calendar day.
     * @param  string  $periodEnd  'Y-m-d', a Manila calendar day.
     * @param  float  $bonus  0 on the reference server path
     *                        (payroll-server-calculation.ts:109); the golden
     *                        corpus's fuzz tier varies it (500, 123.456,
     *                        1000.005, -200), which pins [R12] and [R25]. From
     *                        PayrollData: the taxable incentive lines plus any
     *                        excess of the tax-exempt ones over the PHP 90,000
     *                        ceiling, already Money::round-ed.
     * @param  float|null  $allowancesOverride  The manager-entered allowance for this run.
     *                                          Null is "not provided", which is 0.
     * @param  array<string, mixed>|null  $earningsOverride  Keys: night_differential,
     *                                                       leave_pay, other_earnings, overtime_acknowledged.
     *                                                       night_differential and leave_pay are both
     *                                                       computed when absent; a typed figure wins.
     *                                                       overtime_acknowledged is read ONLY when
     *                                                       $overtimeApprovals is null.
     * @param  array{formulas?: list<mixed>, holidays?: list<HolidaySetting>}|null  $settings
     * @param  array<string, int>|null  $overtimeApprovals  DELIBERATE DIVERGENCE 7: the Daily
     *                                                      Time Record's per-day decisions, shift-start date =>
     *                                                      approved minutes (0 = rejected, no key = undecided).
     *                                                      Null is the reference's run-level acknowledgement
     *                                                      regime, byte for byte (payroll.ts:538-563), and is
     *                                                      what every golden vector passes. With a map, a day
     *                                                      pays min(computed, approved, the Formulas cap) and
     *                                                      an undecided day pays nothing.
     * @param  string|null  $attendanceThrough  DELIBERATE DIVERGENCE 14, the advance payroll: the last
     *                                          'Y-m-d' whose attendance this payslip reads. Every later
     *                                          scheduled working day inside the period is PRESUMED — a
     *                                          full day at its own rate, with no lateness, undertime,
     *                                          overtime or night pay, whatever punches it carries — and
     *                                          counted in `presumed_days`. Null, the reference: every day
     *                                          is read from its attendance.
     * @param  float  $settlement  DIVERGENCE 14: what presumed days on an earlier payslip turned out
     *                             to be worth less what was presumed, already Money::round-ed, and
     *                             negative when they were worth less. It joins gross pay before the
     *                             tax; 0.0 (the reference) adds nothing.
     * @param  array{late: array<string, float>, absence: array<string, float>, late_days: float}|null  $leaveCharges  DELIBERATE DIVERGENCE 15, attendance charged to leave credits:
     *                                                                                                                 the covered FRACTION of each late day's lateness and undertime minutes
     *                                                                                                                 (date => 0..1), the covered part of each absent day (date => days, paid
     *                                                                                                                 as leave), and the late days charged in all (reported). Null, the
     *                                                                                                                 reference: every minute and every absence is priced to pay.
     * @param  array<string, string>|null  $overtimeCategories  DELIBERATE DIVERGENCE 16: the Daily Time Record's
     *                                                          overtime CATEGORY per approved day, shift-start date =>
     *                                                          PayRules::OVERTIME_SEAPORT; a day with no entry is
     *                                                          ordinary overtime. A seaport day is never capped and is
     *                                                          priced at the version's seaport rate when it has one.
     *                                                          Null or empty (the reference, every golden vector):
     *                                                          every day is the day type's overtime under the cap.
     * @param  PayRulesTimeline|null  $timeline  DELIBERATE DIVERGENCE 17: the version in force on EACH day,
     *                                           when one changes inside the period. Every figure a day is
     *                                           priced by — its rate, hours, rounding, matrix cells, night
     *                                           window, caps — comes from that day's version; the paid-day
     *                                           buckets, the leave carve-out and the monthly residual are
     *                                           kept per month AND per version. Null, or a single-version
     *                                           timeline: `$rules` for every day, the reference's operations
     *                                           exactly (the golden guard recomputes every vector so).
     * @param  array<string, float>|null  $contributionPosted  DELIBERATE DIVERGENCE 19: what this contribution month's
     *                                                         POSTED payslips already took of each share (keys `sss`,
     *                                                         `sss_er`, `philhealth`, `philhealth_er`, `pagibig`,
     *                                                         `pagibig_er`), read only by the HALF_THEN_REST and
     *                                                         WHOLE_FIRST schedules. Null, the reference: nothing posted,
     *                                                         and the reference's two schedules never read it.
     */
    public static function compute(
        object $employee,
        array $logs,
        ?Shift $shift,
        array $leaves,
        string $periodStart,
        string $periodEnd,
        float $bonus,
        ?float $allowancesOverride = null,
        ?array $earningsOverride = null,
        ?array $settings = null,
        ?array $overtimeApprovals = null,
        ?StatutorySchedule $statutory = null,
        ?PayRules $rules = null,
        ?string $attendanceThrough = null,
        float $settlement = 0.0,
        ?array $leaveCharges = null,
        ?array $overtimeCategories = null,
        ?PayRulesTimeline $timeline = null,
        ?array $contributionPosted = null,
    ): PayslipDraft {
        // DELIBERATE DIVERGENCE 13: the Pay Basis & Rates version in force,
        // or — for a caller that hands none in, the golden vectors included —
        // the reference's rules over the settings' Formulas rows, which
        // perform the reference's exact operations at every switch below.
        // DIVERGENCE 17: with a timeline, the version in force on the
        // period's first day is `$rules`, and `$rulesOn` answers each day's.
        $timeline ??= PayRulesTimeline::single($rules ?? PayRules::fromFormulas(is_array($settings['formulas'] ?? null) ? $settings['formulas'] : []));
        $rules = $timeline->on($periodStart);
        $rulesOn = static fn (string $date): PayRules => $timeline->on($date);
        // DIVERGENCE 18: the salary versions taking over inside the period
        // (`$employee->salary_rates`, ascending by date); `monthly_rate` /
        // `daily_rate` are the salary in force on the first day. With none
        // — the reference, every golden vector — one salary prices every day.
        $salarySegments = [];
        foreach (is_array($employee->salary_rates ?? null) ? $employee->salary_rates : [] as $segment) {
            if (is_array($segment) && is_string($segment['from'] ?? null)) {
                $salarySegments[] = ['from' => $segment['from'], 'monthly' => self::num($segment['monthly_rate'] ?? null), 'daily' => self::num($segment['daily_rate'] ?? null)];
            }
        }
        $salaryStart = static function (string $date) use ($salarySegments): string {
            $from = PayRulesTimeline::ALWAYS;
            foreach ($salarySegments as $segment) {
                if ($segment['from'] > $date) {
                    break;
                }
                $from = $segment['from'];
            }

            return $from;
        };
        // The date a day is priced AS: the first day of its month, or of its
        // rules version or salary version when one started later. One
        // version, one month: the month's first day, the reference's one
        // rate per month.
        $priceDateFor = static fn (string $date): string => max(substr($date, 0, 7).'-01', $timeline->segmentStart($date), $salaryStart($date));

        /** @var list<HolidaySetting> $holidays */
        $holidays = is_array($settings['holidays'] ?? null) ? $settings['holidays'] : [];

        // The DTR rows this payslip prices, and the presumed dates among them
        // (attendanceDays: the employment window, then divergence 14).
        [$days, $presumed] = self::attendanceDays($employee, $logs, $shift, $leaves, $periodStart, $periodEnd, $holidays, $attendanceThrough);

        /** @var DtrSummary $s */
        $s = DtrCalculator::summarize($days);

        $monthly = self::num($employee->monthly_rate ?? null);

        /*
         * DELIBERATE DIVERGENCE 10 — RATE PER DAY. The reference has one rate,
         * `monthly_rate`, and derives every day from it below. The employee
         * record also carries `daily_rate`, and when it is above zero it IS
         * the pay per day, whether or not a monthly rate is also entered: the
         * same figure in every month, never spread over the month's working
         * days. Blank or zero, and the day is derived from `monthly_rate`
         * exactly as the reference derives it — which is every golden vector,
         * since the reference has no such field.
         *
         * Everything priced off a day follows it: paid days and leave,
         * holidays, rest days, and the derived hourly rate behind overtime,
         * the night differential and the late and undertime charges (a stored
         * `hourly_rate` above zero still overrides the hourly rate).
         *
         * The contribution tables are a different question, answered below at
         * `$contributionBase`: they keep reading `monthly_rate` while it has a
         * value.
         */
        $storedDaily = self::num($employee->daily_rate ?? null);

        // DIVERGENCE 18: the salary in force on a date — the first day's
        // (`$monthly`, `$storedDaily`) until a version taking over inside the
        // period reaches it. With no segments both answer the same figures
        // for every date, so the reference's operations stand.
        $salaryOn = static function (string $date) use ($salarySegments, $monthly, $storedDaily): array {
            $current = ['monthly' => $monthly, 'daily' => $storedDaily];
            foreach ($salarySegments as $segment) {
                if ($segment['from'] > $date) {
                    break;
                }
                $current = ['monthly' => $segment['monthly'], 'daily' => $segment['daily']];
            }

            return $current;
        };

        /*
         * payroll.ts:415 calls workWeek(shift) inline for the divisor and again
         * at :444 for the day-bucket loop. workWeek is pure, so the two values
         * are identical; it is resolved once here.
         */
        $schedule = $shift ?? Shift::default();

        /*
         * The monthly rate spread over the days this employee is actually
         * scheduled to work that month, so a full cut-off always pays the
         * monthly rate pro rata however the calendar falls. A fixed divisor
         * cannot: months hold 20 to 23 weekdays, so perfect attendance would
         * pay a different amount each one.
         *
         * The daily rate therefore moves a little month to month, which is the
         * trade — and it is why `hourly_rate` exists as an override for anyone
         * whose rate must not move, and why an entered Rate Per Day
         * (`daily_rate`, divergence 10 above) is used as it stands.
         *
         * Weekly rest-day overrides can describe an empty effective week.
         * Roster commands refuse that combination; the calculation also
         * refuses it explicitly so malformed imported facts cannot divide
         * the monthly rate by zero or silently invent a working week.
         */
        /*
         * ONE RATE PER CALENDAR MONTH, NOT ONE PER PERIOD.
         *
         * A period may span months (PayrollPeriod::cutoffError accepts it),
         * and the divisor above is a property of a month, not of a payroll
         * run. Resolving it once from the start date would price an
         * October day at September's rate — a 22-working-day month against a
         * 23-working-day one is a 4.5% error on every day past the boundary,
         * silently.
         *
         * So each day is priced by its OWN month, and everything below that
         * counts days counts them per month. Memoised on 'YYYY-MM': a period
         * touches one or two months, and long ones touch few.
         *
         * FOR A PERIOD INSIDE ONE MONTH EVERY FORMULA BELOW REDUCES TO EXACTLY
         * THE REFERENCE'S — one month in the map, one term in each sum, the
         * same multiplication. That is what the 6,921 golden vectors, all of
         * them single-month, are checking: the per-month arithmetic moves no
         * centavo there.
         */
        /*
         * The working days are counted, and an empty week refused, even when
         * a Rate Per Day makes the division unnecessary: an empty week is
         * malformed whichever rate is entered, and the contribution base below
         * multiplies by the same count.
         */
        $scheduledDays = [];
        $scheduledFor = static function (string $date) use (&$scheduledDays, $schedule): int {
            $key = substr($date, 0, 7);

            if (! array_key_exists($key, $scheduledDays)) {
                $range = PayrollPeriod::calendarMonthRange($date);
                $scheduled = ShiftResolver::scheduledWorkDaysForShift($range['from'], $range['to'], $schedule);
                if ($scheduled === 0) {
                    throw new RuntimeException('The weekly shift roster needs at least one working day.');
                }
                $scheduledDays[$key] = $scheduled;
            }

            return $scheduledDays[$key];
        };

        // DIVERGENCE 10: an entered Rate Per Day is the day's rate as it
        // stands; otherwise the monthly rate over the month's working days,
        // the same division the reference makes.
        // DIVERGENCE 13: the daily-rate method. The month's working days is
        // the reference's division, character for character; a yearly
        // factor or a fixed divisor gives one rate for every month.
        // DIVERGENCE 16: the derived day is rounded to the version's
        // decimals before anything is priced from it (`roundRate` is the
        // identity when the version sets none); an entered Rate Per Day is
        // not derived and is never rounded.
        $dailyFor = static function (string $date) use ($scheduledFor, $salaryOn, $rulesOn): float {
            $scheduled = $scheduledFor($date);
            ['monthly' => $monthly, 'daily' => $storedDaily] = $salaryOn($date);
            if ($storedDaily > 0) {
                return $storedDaily;
            }
            $rules = $rulesOn($date);

            return $rules->roundRate(match ($rules->dailyRateMethod) {
                PayRules::METHOD_FACTOR => $monthly * 12 / $rules->dailyRateValue,
                PayRules::METHOD_DIVISOR => $monthly / $rules->dailyRateValue,
                default => $monthly / $scheduled,
            });
        };

        // The rate the payslip reports, and the one every figure that is not
        // tied to a particular day is priced from: the period's first month.
        $daily = $dailyFor($periodStart);

        /*
         * The hours in this employee's own working day, from the roster — a
         * six-hour shift makes each hour worth more than an eight-hour one on
         * the same daily rate. Every per-hour figure below divides by it:
         * overtime, the night differential, a rest day, and the late and
         * undertime charges.
         */
        // DELIBERATE DIVERGENCE 8: weekday choices price each date from its
        // own paid hours. An unchanged weekly schedule keeps the same float
        // operations and aggregation order as the reference path.
        // DIVERGENCE 16: a version may fix the hours instead, for every date.
        $dailyHoursFor = static function (string $date) use ($schedule, $rulesOn): float {
            $hours = $rulesOn($date)->hourlyHours;
            if ($hours !== null) {
                return $hours;
            }
            $minutes = ShiftResolver::paidShiftMinutes($schedule->forDate($date));

            return $minutes > 0 ? $minutes / 60 : self::DEFAULT_WORKING_HOURS;
        };

        // The hourly rate the day's rate derives — DIVERGENCE 16: rounded to
        // the version's decimals, the identity when it sets none. Every
        // per-hour figure that ignores the stored hourly override (the night
        // add-on, a rest day) prices from it.
        $derivedHourlyFor = static fn (string $date): float => $rulesOn($date)->roundRate($dailyFor($date) / $dailyHoursFor($date));
        // A premium hourly rate (the hourly times a factor) as the version
        // wants it multiplied by hours: rounded when it rounds, else the
        // reference's own `(hours) * hourly * factor` left-to-right.
        $roundsOn = static fn (string $date): bool => $rulesOn($date)->roundingDecimals !== null;

        // The stored hourly rate may be an intentional override, but it is
        // rounded to cents. Do not multiply that rounded value back into the
        // daily rate.
        $storedHourly = self::num($employee->hourly_rate ?? null);
        $hourlyFor = static fn (string $date): float => $storedHourly > 0
            ? $storedHourly
            : $derivedHourlyFor($date);

        // The override does not move month to month, which is the point of it.
        $hourly = $hourlyFor($periodStart);

        /*
         * The night differential is measured off the *scheduled* shift, not the
         * punches: a night-shift employee earns it for the night hours their
         * shift covers on any day they turned up. Dates with the same hours
         * are grouped so uniform schedules keep the reference multiplication.
         *
         * Capped by the paid day as well as by the configured maximum: the
         * unpaid break can sit inside the night window, and no differential is
         * owed on an hour that is not paid in the first place. A shift that is
         * night from end to end therefore earns exactly `nsdRate` of a daily
         * rate, whatever its length.
         */
        // DIVERGENCE 13: the night window is the version's (the reference's
        // 22:00–06:00 by default), and the add-on for a night hour is the
        // night rate on the base hourly rate unless the day type's matrix
        // cell says otherwise (`night − worked`, percents of the base hourly).
        $nightHoursFor = static fn (string $date): float => min(
            ShiftResolver::nightShiftMinutes($schedule->forDate($date), $rulesOn($date)->nightStart, $rulesOn($date)->nightEnd) / 60,
            $rulesOn($date)->rates->maxNsdHours,
            $dailyHoursFor($date),
        );
        $nsdPerNight = static fn (string $date, float $addOn): float => $roundsOn($date)
            ? $rulesOn($date)->roundRate($derivedHourlyFor($date) * $addOn) * $nightHoursFor($date)
            : $derivedHourlyFor($date) * $addOn * $nightHoursFor($date);
        $holidayTypes = [];
        $holidayCount = [];
        foreach ($days as $day) {
            $onDate = HolidaySetting::allOn($day->date, $holidays);
            $holidayCount[$day->date] = count($onDate);
            $holidayTypes[$day->date] = $onDate === [] ? null : ($onDate[0]->type === 'REGULAR' ? 'REGULAR' : 'SPECIAL');
        }
        // The day type the matrix prices a date as. A double holiday is one
        // only while the version prices it; otherwise it is the first
        // holiday, as the reference reads it.
        $dayTypeFor = static function (string $date, bool $onWorkDay) use ($rulesOn, $holidayTypes, $holidayCount): string {
            $base = $holidayTypes[$date];
            if ($base === null) {
                return $onWorkDay ? 'ORDINARY' : 'REST';
            }
            if ($holidayCount[$date] >= 2 && $rulesOn($date)->prices($onWorkDay ? 'DOUBLE' : 'DOUBLE_REST')) {
                $base = 'DOUBLE';
            }

            return $onWorkDay ? $base : $base.'_REST';
        };
        // What the matrix's worked cell shows for a day type when it is left
        // as the reference derives it, as a percent: the night add-on is
        // measured from it.
        $derivedWorkedPercent = static function (string $date, string $type, ?HolidaySetting $holiday) use ($rulesOn): float {
            $restDayRate = $rulesOn($date)->rates->restDayRate;

            return match ($type) {
                'ORDINARY' => 100.0,
                'REST' => $restDayRate * 100,
                'SPECIAL', 'REGULAR', 'DOUBLE' => self::num($holiday?->percent),
                default => self::num($holiday?->percent) * max(1.0, $restDayRate),
            };
        };
        $nightAddOnFor = static function (string $date, string $type, ?HolidaySetting $holiday) use ($rulesOn, $derivedWorkedPercent): float {
            $rules = $rulesOn($date);
            $cell = $rules->matrix[$type]['night'];
            if ($cell === null) {
                return $rules->rates->nsdRate;
            }
            $worked = $rules->matrix[$type]['worked'] ?? $derivedWorkedPercent($date, $type, $holiday);

            return max(0.0, ($cell - $worked) / 100);
        };

        /*
         * Every paid day falls into exactly one bucket, so no day is priced
         * twice. A worked holiday is paid from its own `percent` — the whole
         * day's worth, not a premium stacked on an ordinary day — and skips the
         * work-day bucket. A holiday nobody worked earns nothing.
         *
         * Approved paid leave is a paid day even though it was never punched,
         * and a half day counts as half. It is reached only AFTER the
         * `workedMinutes > 0` branch has `continue`d, so a day the employee
         * actually worked can never also land in the leave bucket.
         *
         * DELIBERATE DIVERGENCE 6 — the reference folds that leave day into
         * `workedDays` (payroll.ts:492) and reports it as work-day pay. EMS
         * carries it on the Leave line instead, because Leave Management
         * already knows whether an absence is with pay or without pay and the
         * payslip should say so. The pesos DO NOT MOVE: both counts are priced
         * together at [R02] and the leave line is CARVED OUT of that one
         * rounded total at [R02b], never rounded a second time.
         *
         * NEVER price these two buckets independently. Rounding each product
         * on its own moves `gross_pay` by a centavo whenever the two sub-centavo
         * remainders straddle a half — measured at 6,399 of 29,448 rate and
         * day-count combinations, in both directions.
         */
        // `$workedDays` counts PUNCHED days only; paid leave is its own
        // count. `$paidDaysByMonth` is the two of them together and is what
        // [R02] prices, so that total is bit-identical to the reference's.
        $workedDays = 0.0;
        $leaveDays = 0.0;
        $paidDaysByMonth = [];
        $leaveDaysByMonth = [];
        $nightGroups = [];
        $nightDates = [];
        $holidayDays = 0;
        $holidayEarnings = 0.0;
        $restDays = 0;
        $restDayEarnings = 0.0;
        $nightsWorked = 0;

        $dayByDate = [];
        foreach ($days as $day) {
            $dayByDate[$day->date] = $day;
        }
        // DIVERGENCE 13, the daily-paid holiday rule: an unworked holiday
        // earns its `unworked` percent only when the previous scheduled
        // workday in the period was worked or on paid leave — or lies before
        // the period, which the payslip cannot see.
        $holidayEligible = static function (string $date) use ($days, $schedule, $holidayTypes): bool {
            $previous = null;
            foreach ($days as $candidate) {
                if (strcmp($candidate->date, $date) >= 0) {
                    break;
                }
                if (ShiftResolver::worksOnDate($candidate->date, $schedule) && $holidayTypes[$candidate->date] === null) {
                    $previous = $candidate;
                }
            }
            if ($previous === null) {
                return true;
            }

            return $previous->workedMinutes > 0 || $previous->excusePaid === true || $previous->status === DayRecord::OFFICIAL_BUSINESS;
        };
        $nightAddOns = [];
        // DIVERGENCE 15: the absent days, or parts of them, credits paid for.
        $chargedAbsenceDays = 0.0;

        foreach ($days as $day) {
            $holiday = HolidaySetting::on($day->date, $holidays);
            // DIVERGENCE 17: the bucket a day's pay joins — its month, and
            // its version when one started inside the month.
            $dayMonth = $priceDateFor($day->date);
            $dayRules = $rulesOn($day->date);
            $dayRates = $dayRules->rates;
            $dayDaily = $dailyFor($day->date);
            $onWorkDay = ShiftResolver::worksOnDate($day->date, $schedule);
            $dayType = $dayTypeFor($day->date, $onWorkDay);

            if ($day->workedMinutes > 0) {
                // DIVERGENCE 14: a presumed day earns its day, never a night
                // nobody has worked yet; the night pay, if any, comes with
                // the settlement.
                if (! isset($presumed[$day->date])) {
                    $nightsWorked += 1;
                    $addOn = $nightAddOnFor($day->date, $dayType, $holiday);
                    $nightKey = $dayMonth.'|'.$dailyHoursFor($day->date).'|'.$nightHoursFor($day->date).'|'.$addOn;
                    $nightGroups[$nightKey] = ($nightGroups[$nightKey] ?? 0) + 1;
                    $nightDates[$nightKey] = $day->date;
                    $nightAddOns[$nightKey] = $addOn;
                }

                // A worked holiday outranks a rest day: it is priced from its
                // own percent, and pricing it twice is exactly what these
                // buckets prevent.
                if ($holiday !== null) {
                    $holidayDays += 1;
                    /*
                     * A holiday that lands on a rest day is BOTH, and is
                     * priced as both: the holiday's own percent, compounded by
                     * Formulas -> Rest Day. Neither factor is hard-coded —
                     * Records -> Holidays owns the percent, Records ->
                     * Formulas owns the multiplier, and both are saved
                     * settings, so the rate this pays is the rate somebody
                     * chose.
                     *
                     * At the shipped defaults a regular holiday worked on a
                     * rest day pays 200% x 1.3 = 260% of the daily rate, which
                     * is the DOLE figure. A special non-working day pays
                     * 130% x 1.3 = 169%, where DOLE publishes 150% — above the
                     * minimum, which is lawful, and the way to bring it down is
                     * the Rest Day percentage or the holiday's own percent
                     * rather than a rule in here.
                     *
                     * It is ONE bucket. The rest-day bucket is not also
                     * credited: that would pay the day twice over, at 330%
                     * rather than 260%.
                     *
                     * THE FLOOR OF 1.0 IS LOAD-BEARING. Formulas accepts a Rest
                     * Day percentage of 0 — PayrollRates::rate() takes any
                     * value >= 0 — and 477 of the golden vectors set exactly
                     * that. Multiplying by it unguarded does not mean "no
                     * rest-day uplift", it means the holiday premium
                     * disappears: a regular holiday worked on a Sunday would
                     * pay nothing at all. A rest-day rate at or below 1 is not
                     * a premium, so it compounds to no uplift rather than to
                     * annihilation.
                     */
                    /*
                     * The association is the reference's, deliberately:
                     * `(daily * percent) / 100`, NOT `daily * (percent / 100)`.
                     * The two are not the same double — rewriting it moves a
                     * centavo on holiday_pay for a 123,456.78 monthly rate —
                     * and the uplift is applied as a separate factor after it.
                     * Multiplying by exactly 1.0 is exact in IEEE 754, so a day
                     * that does not compound is bit-identical to the reference.
                     */
                    // DIVERGENCE 13: an explicit matrix cell prices the day as
                    // that percent of the daily rate (a rest-day type's cell
                    // already carries its uplift); a null cell is the
                    // reference's derivation above.
                    $workedCell = $dayRules->matrix[$dayType]['worked'];
                    if ($workedCell === null) {
                        $dayHolidayPay = ($dayDaily * self::num($holiday->percent)) / 100;

                        if (! $onWorkDay) {
                            $dayHolidayPay *= max(1.0, $dayRates->restDayRate);
                        }
                    } else {
                        $dayHolidayPay = ($dayDaily * $workedCell) / 100;
                    }

                    $holidayEarnings += $dayHolidayPay;
                } elseif (! $onWorkDay) {
                    $restDays += 1;
                    /*
                     * Priced by the hour, so half a rest day pays half. The cap
                     * is what keeps a long rest day from being paid twice:
                     * hours beyond it are already overtime, and overtime on a
                     * rest day carries the same premium through `$dayRate`.
                     */
                    $restHours = min($day->workedMinutes / 60, $dayRates->maxRestDayHours);
                    // DIVERGENCE 13: the REST matrix cell, else the Rest Day rate.
                    $restCell = $dayRules->matrix['REST']['worked'];
                    $restFactor = $restCell === null ? $dayRates->restDayRate : $restCell / 100;
                    $restDayEarnings += $roundsOn($day->date)
                        ? $dayRules->roundRate($derivedHourlyFor($day->date) * $restFactor) * $restHours
                        : $derivedHourlyFor($day->date) * $restFactor * $restHours;
                } else {
                    $workedDays += 1;
                    $paidDaysByMonth[$dayMonth] = ($paidDaysByMonth[$dayMonth] ?? 0.0) + 1;
                }

                continue;
            }

            if ($day->excusePaid === true) {
                $leaveDays += $day->excuseDays;
                $paidDaysByMonth[$dayMonth] = ($paidDaysByMonth[$dayMonth] ?? 0.0) + $day->excuseDays;
                $leaveDaysByMonth[$dayMonth] = ($leaveDaysByMonth[$dayMonth] ?? 0.0) + $day->excuseDays;
            } elseif ($leaveCharges !== null && isset($leaveCharges['absence'][$day->date]) && $day->isAbsenceDay($schedule)) {
                /*
                 * DELIBERATE DIVERGENCE 15: the part of an absent day that
                 * leave credits covered is PAID AS LEAVE — the same buckets an
                 * approved paid leave fills, so [R02] and the carve-out below
                 * price it exactly as a leave day; the rest of the day stays
                 * unpaid. The absence count beside it is still the attendance
                 * fact: the day was absent, and `leave_charged_days` says
                 * how much of it credits paid for.
                 */
                $coveredAbsence = min(1.0, max(0.0, self::num($leaveCharges['absence'][$day->date])));
                $leaveDays += $coveredAbsence;
                $chargedAbsenceDays += $coveredAbsence;
                $paidDaysByMonth[$dayMonth] = ($paidDaysByMonth[$dayMonth] ?? 0.0) + $coveredAbsence;
                $leaveDaysByMonth[$dayMonth] = ($leaveDaysByMonth[$dayMonth] ?? 0.0) + $coveredAbsence;
            } elseif ($holiday !== null && $onWorkDay) {
                /*
                 * DIVERGENCE 13: an UNWORKED holiday on a scheduled workday.
                 * The reference pays nothing for it. Under the MONTHLY basis
                 * the salary covers it, so it counts as a paid day; under the
                 * DAILY basis it earns the day type's `unworked` percent of
                 * the daily rate (0 by default — the reference), when the
                 * attendance rule, if on, is met.
                 */
                if ($dayRules->basis === PayRules::BASIS_MONTHLY) {
                    $paidDaysByMonth[$dayMonth] = ($paidDaysByMonth[$dayMonth] ?? 0.0) + 1;
                } else {
                    $unworked = $dayRules->matrix[$dayType]['unworked'];
                    if ($unworked > 0.0 && (! $dayRules->holidayNeedsAttendance || $holidayEligible($day->date))) {
                        $holidayEarnings += ($dayDaily * $unworked) / 100;
                    }
                }
            }
        }

        $work_days = Money::round($workedDays);                                   // [R01] payroll.ts:493
        // DIVERGENCE 6: the reference has no such field — its `work_days`
        // carries these days instead. Rounded like its sibling at [R01].
        $paid_leave_days = Money::round($leaveDays);                              // [R01a] DIVERGENCE 6
        $holiday_days = (float) $holidayDays;
        $rest_days = (float) $restDays;

        /*
         * QUIRK, preserved: the paid-day pay multiplies each month's ROUNDED
         * day count by the UNROUNDED daily rate — payroll.ts:497 reads
         * `work_days * daily`, not `workedDays * daily`.
         */
        /*
         * Summed per month, and the QUIRK above is preserved per month too:
         * each month's day count is rounded before it is priced, exactly as
         * the reference rounds its single total. With one month in the map
         * this is `Money::round(Money::round($paidDays) * $daily)`, character
         * for character what payroll.ts:497 computes — and `$paidDaysByMonth`
         * holds exactly what the reference's `workedDays` holds, punched days
         * and paid leave together, so [R02] is the reference's arithmetic.
         */
        // DIVERGENCE 17: the buckets are keyed by the pricing date (the
        // month's first day, or the version's first day inside it), so each
        // is priced by its own version; with one version the key IS the
        // month's first day and this is the reference's loop.
        $paidDayEarnings = 0.0;
        foreach ($paidDaysByMonth as $priceDate => $count) {
            $paidDayEarnings += Money::round($count) * $dailyFor($priceDate);
        }

        /*
         * DIVERGENCE 13, the MONTHLY basis under a fixed daily rate: the
         * salary covers every scheduled day, so each month's pay is the
         * salary pro rata to the period's scheduled days in it, less a day's
         * rate for each of them not paid above. Written as a residual on the
         * additive sum: `monthly × k/S − k × daily`, k the period's scheduled
         * days in the month (inside employment), S the month's. Under the
         * month's-working-days method the residual is zero by construction
         * and is not added, so that path keeps the reference's operations.
         * A Rate Per Day employee is paid by the day whatever the basis.
         */
        // DIVERGENCE 17 and 18: counted per pricing date, so each version's
        // days carry their own residual under their own basis, method and
        // salary; a Rate Per Day in force on the date is paid by the day.
        $scheduledInPeriod = [];
        foreach ($days as $day) {
            $priceDate = $priceDateFor($day->date);
            $dayRules = $rulesOn($priceDate);
            if ($salaryOn($priceDate)['daily'] <= 0 && $dayRules->basis === PayRules::BASIS_MONTHLY && $dayRules->dailyRateMethod !== PayRules::METHOD_MONTH && ShiftResolver::worksOnDate($day->date, $schedule)) {
                $scheduledInPeriod[$priceDate] = ($scheduledInPeriod[$priceDate] ?? 0) + 1;
            }
        }
        foreach ($scheduledInPeriod as $priceDate => $k) {
            $paidDayEarnings += $salaryOn($priceDate)['monthly'] * $k / $scheduledFor($priceDate) - $k * $dailyFor($priceDate);
        }

        $paid_day_pay = Money::round($paidDayEarnings);                           // [R02] payroll.ts:497

        /*
         * DIVERGENCE 6, the carve-out. The leave line is priced the same way,
         * then SUBTRACTED from the one total above rather than the two being
         * rounded independently — see the bucket comment. `$work_days_pay` is
         * the residual, so `work_days_pay + leave_pay` is always exactly the
         * figure payroll.ts:497 produced and `gross_pay` cannot move.
         *
         * The subtraction is rounded because two exact 2-decimal floats do not
         * subtract to one: 2045.45 - 681.82 is 1363.6299999999999.
         */
        $leaveEarnings = 0.0;
        foreach ($leaveDaysByMonth as $priceDate => $count) {
            $leaveEarnings += Money::round($count) * $dailyFor($priceDate);
        }

        $computedLeavePay = Money::round($leaveEarnings);                         // [R02a] DIVERGENCE 6
        $work_days_pay = Money::round($paid_day_pay - $computedLeavePay);         // [R02b] DIVERGENCE 6
        $holiday_pay = Money::round($holidayEarnings);                            // [R03] payroll.ts:498
        $rest_day_pay = Money::round($restDayEarnings);                           // [R04] payroll.ts:499
        $basic_pay = Money::round($work_days_pay + $holiday_pay + $rest_day_pay); // [R05] payroll.ts:500

        // payroll.ts:502-509 — `s.absences + incompleteDays`: ABSENT days,
        // plus INCOMPLETE ones on a day the schedule works, because an open
        // TIME_IN has no defensible duration and cannot silently become a
        // fully paid day. HR can resolve the attendance record before
        // finalization. Counted in one pass through DayRecord::isAbsenceDay,
        // the one per-day test (the Daily Time Record's Absences tile counts
        // with it too); a day has one status, so the sum is the same.
        $absenceDayCount = 0;
        foreach ($days as $day) {
            if ($day->isAbsenceDay($schedule)) {
                $absenceDayCount += 1;
            }
        }

        // Reported on the payslip, but costing nothing on their own: an
        // unworked day simply earns none of the buckets above.
        $absence_days = (float) $absenceDayCount;
        $unpaid_leave_days = Money::round($s->unpaidLeave);                       // [R06] payroll.ts:510

        // Employee-profile allowances are not a payroll input. A draft carries
        // the manager-entered figure for this run instead.
        $allowances = self::entered($allowancesOverride);                         // [R07] payroll.ts:514

        /*
         * Computed from the shift, but a typed figure wins — payroll sometimes
         * has to settle a night by hand. Leaving the field untouched keeps the
         * computed amount. The reference repeats the is-a-finite-number test
         * outside `entered()` (payroll.ts:524-528); so does this.
         */
        $enteredNsd = $earningsOverride['night_differential'] ?? null;
        $computedNsd = array_sum(array_map(
            static fn (string $key): float => $nsdPerNight($nightDates[$key], $nightAddOns[$key]) * $nightGroups[$key],
            array_keys($nightGroups),
        ));
        // DIVERGENCE 13: night pay on overtime hours, when the version says
        // so — the overtime loop below measures them; never on the
        // reference path.
        $nightOvertimeEarnings = 0.0;
        $night_differential = self::isNumberValue($enteredNsd)
            ? self::entered((float) $enteredNsd)                                  // [R07] payroll.ts:514
            : null;

        /*
         * DIVERGENCE 6. Computed from the approved leave the DTR resolved,
         * but a typed figure wins — payroll sometimes has to settle a disputed
         * leave day by hand. Leaving the field untouched keeps the computed
         * amount. Same shape, and the same is-a-finite-number test outside
         * `entered()`, that `night_differential` uses above.
         *
         * In the reference this field means something else entirely — "pay for
         * working a day already filed as leave" (payroll.ts:254) — because
         * there was no leave module to compute it from.
         *
         * NOTE the override is nullable on purpose: NULL means "no override"
         * and is NOT the same as a typed 0, which pays nothing. Never coerce
         * one into the other on save or load.
         */
        $enteredLeave = $earningsOverride['leave_pay'] ?? null;
        $leave_pay = self::isNumberValue($enteredLeave)
            ? self::entered((float) $enteredLeave)                                // [R07] payroll.ts:514
            : $computedLeavePay;                                                  // [R02a]
        $other_earnings = self::entered(self::numberOrNull($earningsOverride['other_earnings'] ?? null));

        /*
         * Ported faithfully: the reference hard-wires this to 0 with the
         * comment "13th-month pay is processed separately and is not part of a
         * regular cut-off", and a regular cut-off here keeps it at 0 so the two
         * engines reconcile to the centavo. In EMS it IS processed
         * separately — the yearly THIRTEENTH_MONTH run (DELIBERATE DIVERGENCE,
         * Modules\Payroll\Application\ThirteenthMonthData), never this engine.
         * The statutory tables refuse anything starting after 2026-12-31, the
         * 13th month run included.
         */
        $thirteenth_month = 0.0;

        /*
         * Caps from Formulas apply per day, not to the period total, and each
         * day is paid at its own rate — an hour past a regular holiday is worth
         * far more than an hour past an ordinary Tuesday.
         *
         * `Minutes::round()` here, not Money::round(): payroll.ts:538 is
         * `Math.round(maxHours * 60)`, a whole-MINUTE cap, not a centavo.
         */
        $overtimeMinutes = 0.0;
        $overtimeEarnings = 0.0;
        // DIVERGENCE 17: whether any day's version pays night on overtime.
        $nightOnOvertime = false;

        foreach ($days as $day) {
            $dayRules = $rulesOn($day->date);
            $dayRates = $dayRules->rates;
            $cap = Minutes::round($dayRates->maxOtHours * 60);
            // DIVERGENCE 16: a day approved as seaport overtime has no cap.
            $seaport = ($overtimeCategories[$day->date] ?? null) === PayRules::OVERTIME_SEAPORT;
            $dayCap = $seaport ? 1440.0 : $cap;
            // DIVERGENCE 7: under per-day approval a day pays only the minutes
            // the DTR approved, and never more than it computed or the cap.
            $minutes = $overtimeApprovals === null
                ? min($day->overtimeMinutes, $dayCap)
                : min($day->overtimeMinutes, (float) ($overtimeApprovals[$day->date] ?? 0), $dayCap);
            if ($minutes <= 0) {
                continue;
            }

            $holiday = HolidaySetting::on($day->date, $holidays);

            // A worked holiday pays its own rate and then 130% of it; a rest
            // day pays its premium and then the ordinary overtime rate on top.
            // A holiday that falls on a rest day is priced as the holiday,
            // matching the day bucket.
            $onWorkDay = ShiftResolver::worksOnDate($day->date, $schedule);
            $dayType = $dayTypeFor($day->date, $onWorkDay);

            // DIVERGENCE 13: an explicit matrix cell is the overtime hour's
            // percent of the base hourly rate; a null cell is the reference's
            // derivation, with the version's holiday overtime factor (1.3).
            // DIVERGENCE 16: a seaport day takes the version's seaport rate,
            // whatever the day type, when the version has one.
            $overtimeCell = $dayRules->matrix[$dayType]['overtime'];
            if ($seaport && $dayRules->seaportRate !== null) {
                $dayRate = $dayRules->seaportRate;
            } elseif ($overtimeCell !== null) {
                $dayRate = $overtimeCell / 100;
            } elseif ($holiday !== null) {
                $dayRate = (self::num($holiday->percent) / 100) * $dayRules->holidayOvertimeFactor;

                // Overtime is priced off the day's own rate, so if the day
                // compounded above, its overtime compounds with it — by the
                // same floored factor, for the same reason.
                if (! $onWorkDay) {
                    $dayRate *= max(1.0, $dayRates->restDayRate);
                }
            } else {
                $dayRate = $onWorkDay ? $dayRates->otRate : $dayRates->restDayRate * $dayRates->otRate;
            }

            $overtimeMinutes += $minutes;
            // DIVERGENCE 16: the overtime hour's rate rounded before the hours
            // multiply it, when the version rounds.
            $overtimeEarnings += $roundsOn($day->date)
                ? ($minutes / 60) * $dayRules->roundRate($hourlyFor($day->date) * $dayRate)
                : ($minutes / 60) * $hourlyFor($day->date) * $dayRate;

            // DIVERGENCE 13: the overtime hours that fall in the night
            // window, after the scheduled shift ends, earn the night add-on.
            if ($dayRules->nightOnOvertime) {
                $nightOnOvertime = true;
                $span = ShiftResolver::shiftSpan($schedule->forDate($day->date));
                if ($span !== null) {
                    $nightOvertime = ShiftResolver::nightMinutesBetween($span[1], $span[1] + $minutes, $dayRules->nightStart, $dayRules->nightEnd);
                    $nightOvertimeEarnings += $roundsOn($day->date)
                        ? $dayRules->roundRate($derivedHourlyFor($day->date) * $nightAddOnFor($day->date, $dayType, $holiday)) * ($nightOvertime / 60)
                        : $derivedHourlyFor($day->date) * $nightAddOnFor($day->date, $dayType, $holiday) * ($nightOvertime / 60);
                }
            }
        }

        $night_differential ??= Money::round($nightOnOvertime                    // [R08] payroll.ts:528
            ? $computedNsd + $nightOvertimeEarnings
            : $computedNsd);

        $overtime_hours = Money::round($overtimeMinutes / 60);                    // [R09] payroll.ts:556
        $overtime_pay = Money::round($overtimeEarnings);                          // [R10] payroll.ts:557
        // Null: the reference's run-level switch. Under per-day approval the
        // approved minutes ARE the acknowledgement, so the flag reports
        // whether any approved overtime reached gross.
        $overtime_acknowledged = $overtimeApprovals === null
            ? ($earningsOverride['overtime_acknowledged'] ?? null) === true
            : $overtimeMinutes > 0;

        $total_base_pay = Money::round($basic_pay + $allowances);                 // [R11] payroll.ts:560

        /*
         * QUIRK, preserved: the RAW, unrounded `$bonus` enters gross pay
         * (payroll.ts:567) while the payslip's own bonus field is rounded
         * separately at [R25] (payroll.ts:700). A bonus carrying sub-centavo
         * precision therefore contributes its full value to gross pay and its
         * rounded value to the displayed line.
         */
        $gross_pay = Money::round(                                                // [R12] payroll.ts:561
            $total_base_pay
            + ($overtime_acknowledged ? $overtime_pay : 0.0)
            + $night_differential
            + $leave_pay
            + $other_earnings
            + $bonus,
        );
        // DIVERGENCE 14: the settlement of earlier presumed days is pay for
        // those days, so it joins gross — and so the taxable base — as one
        // more term of [R12]. Only when it is something: a payslip that
        // settles nothing keeps the reference's sum above, operation for
        // operation.
        if ($settlement !== 0.0) {
            $gross_pay = Money::round(                                            // [R12] again, one term longer
                $total_base_pay
                + ($overtime_acknowledged ? $overtime_pay : 0.0)
                + $night_differential
                + $leave_pay
                + $other_earnings
                + $bonus
                + $settlement,
            );
        }

        // An incomplete punch is treated as an absence. Do not also charge the
        // late minutes from its unmatched TIME_IN.
        $lateByRate = [];
        $undertimeByRate = [];
        $rateDates = [];
        foreach ($days as $day) {
            if ($day->status !== DayRecord::PRESENT && $day->status !== DayRecord::LATE) {
                continue;
            }
            // DIVERGENCE 17: keyed by the pricing date, so a version change
            // inside the month prices each side's minutes at its own hour.
            $key = $priceDateFor($day->date).'|'.($storedHourly > 0 ? 'override' : $dailyHoursFor($day->date));
            $rateDates[$key] = $day->date;
            // DIVERGENCE 15: the part of the day's minutes leave credits covered
            // is not priced; with no charges (the reference) the factor is
            // exactly 1.0, and the reference's addition is kept as it is.
            $uncovered = $leaveCharges === null ? 1.0 : 1.0 - min(1.0, max(0.0, self::num($leaveCharges['late'][$day->date] ?? 0.0)));
            $lateByRate[$key] = ($lateByRate[$key] ?? 0.0) + ($uncovered === 1.0 ? $day->lateMinutes : $day->lateMinutes * $uncovered);
            $undertimeByRate[$key] = ($undertimeByRate[$key] ?? 0.0) + ($uncovered === 1.0 ? $day->undertimeMinutes : $day->undertimeMinutes * $uncovered);
        }

        // The payslip reports one figure for each. With a single month in the
        // map this is the reference's addition, in the same order.
        $lateMinutes = array_sum($lateByRate);
        $undertimeMinutes = array_sum($undertimeByRate);

        /*
         * The minutes are summed per month and rate and THEN priced, rather than each
         * day being priced and the amounts summed. Both are correct; only the
         * first is bit-identical to `(Σ minutes / 60) * $hourly` when the
         * period is in one month, and float addition is not associative enough
         * for "both are correct" to survive a half-centavo boundary.
         */
        $priceMinutes = static function (array $byRate) use ($hourlyFor, $rateDates): float {
            $total = 0.0;
            foreach ($byRate as $key => $minutes) {
                $total += ($minutes / 60) * $hourlyFor($rateDates[$key]);
            }

            return $total;
        };

        $late_deduction = Money::round($priceMinutes($lateByRate));              // [R13] payroll.ts:580
        $undertime_deduction = Money::round($priceMinutes($undertimeByRate));    // [R14] payroll.ts:581

        /*
         * ─────────────────────────────────────────────────────────────────────
         * CONTRIBUTIONS AND TAX — payroll.ts:583-660.
         * ─────────────────────────────────────────────────────────────────────
         * The lines are computed literally as the reference computes them, so
         * the 6,921 golden vectors, emitted with the automatic path, hold these
         * fields strictly.
         *
         * THE REFERENCE RULE, on purpose: the monthly table figure for the
         * employee's `monthly_rate` (or, under divergence 10, the contribution
         * base below) is halved on every payslip, and an employee whose mode
         * is anything but SEMI_MONTHLY pays the whole month on the payslip
         * whose period ends after the 15th and nothing on the other. A run
         * over a non-standard range of days still carries half a month; the
         * run workspace warns about such a period
         * (Modules\Payroll\Domain\CutoffAdvice) and the officer can overtype
         * any line.
         *
         * An officer's typed figure is applied AFTER this, in the payroll
         * application: PayrollData::rederiveWithholding() first recomputes the
         * tax from the employee shares actually posted (through the same
         * StatutoryLines functions used here), then applyDeductionOverrides()
         * applies the typed lines and re-foots — both from calculateInputs.
         *
         * The employer shares are computed and reported; they never enter
         * total_deductions ([R23] sums the employee lines only).
         *
         * The figures come from the `$statutory` schedule handed in — the
         * Contribution & Tax Table versions in force, which the application's
         * StatutoryTables::cutoffError keeps a run inside — or, for a caller
         * that hands none in, the reference's built-in figures.
         *
         * DIVERGENCE 10, the contribution base: the tables read
         * `monthly_rate` whenever it has a value, so a Rate Per Day changes
         * nobody's contributions who has a monthly rate. Only when the
         * monthly rate is blank or zero and a Rate Per Day is entered do they
         * read the day rate times the working days the schedule holds in the
         * period's first month — the inverse of the division above. Without
         * it such an employee would silently post no contributions at all
         * (every table returns 0 for 0).
         */
        $contributionBase = $monthly > 0 || $storedDaily <= 0
            ? $monthly
            : $storedDaily * $scheduledFor($periodStart);
        // The tables in force for the period (Contribution & Tax Tables), or
        // the reference's built-in figures when none is handed in.
        $sssTable = Statutory::sss($contributionBase, $statutory);
        $philhealthTable = Statutory::philhealth($contributionBase, $statutory);
        $pagibigTable = Statutory::pagibig($contributionBase, $statutory);
        $endsMonth = StatutoryLines::endsMonth($periodEnd);                      // payroll.ts:587
        $hasGrossPay = $gross_pay > 0.0;                                          // payroll.ts:588
        // DIVERGENCE 19: what the month's posted payslips already took of
        // each share; 0 on the reference path, which never reads it.
        $posted = static fn (string $key): float => self::num($contributionPosted[$key] ?? 0.0);

        $rawSss = Money::round(StatutoryLines::contribution(                     // [R15] payroll.ts:589
            $employee->allow_sss ?? null, $hasGrossPay, $employee->fixed_sss_ee ?? null,
            $sssTable['employee'], $employee->sss_mode ?? null, $endsMonth, $posted('sss'),
        ));
        $sss_er = Money::round(StatutoryLines::contribution(                     // [R16] payroll.ts:598
            $employee->allow_sss ?? null, $hasGrossPay, $employee->fixed_sss_er ?? null,
            $sssTable['employer'], $employee->sss_mode ?? null, $endsMonth, $posted('sss_er'),
        ));
        $rawPhilhealth = Money::round(StatutoryLines::contribution(              // [R17] payroll.ts:607
            $employee->allow_philhealth ?? null, $hasGrossPay, $employee->fixed_philhealth_ee ?? null,
            $philhealthTable['employee'], $employee->philhealth_mode ?? null, $endsMonth, $posted('philhealth'),
        ));
        $philhealth_er = Money::round(StatutoryLines::contribution(              // [R18] payroll.ts:616
            $employee->allow_philhealth ?? null, $hasGrossPay, $employee->fixed_philhealth_er ?? null,
            $philhealthTable['employer'], $employee->philhealth_mode ?? null, $endsMonth, $posted('philhealth_er'),
        ));
        $rawPagibig = Money::round(StatutoryLines::contribution(                 // [R19] payroll.ts:625
            $employee->allow_pagibig ?? null, $hasGrossPay, $employee->fixed_pagibig_ee ?? null,
            $pagibigTable['employee'], $employee->pagibig_mode ?? null, $endsMonth, $posted('pagibig'),
        ));
        $pagibig_er = Money::round(StatutoryLines::contribution(                 // [R20] payroll.ts:634
            $employee->allow_pagibig ?? null, $hasGrossPay, $employee->fixed_pagibig_er ?? null,
            $pagibigTable['employer'], $employee->pagibig_mode ?? null, $endsMonth, $posted('pagibig_er'),
        ));

        $taxable = StatutoryLines::taxable(                                       // payroll.ts:644-647
            $gross_pay, $rawSss, $rawPhilhealth, $rawPagibig, $late_deduction, $undertime_deduction,
        );
        $rawWithholdingTax = StatutoryLines::withholding($employee, $taxable, $statutory);   // [R21] payroll.ts:652

        $deductions = PayslipDraft::normalizeDeductions([                         // [R22] payroll.ts:319
            'late_deduction' => $late_deduction,
            'undertime_deduction' => $undertime_deduction,
            'sss' => $rawSss,
            'philhealth' => $rawPhilhealth,
            'pagibig' => $rawPagibig,
            'withholding_tax' => $rawWithholdingTax,
            // Nothing derives this one — it is whatever the payroll manager types.
            'other_deductions' => 0.0,
        ]);

        $total_deductions = PayslipDraft::sumDeductions($deductions);             // [R23] payroll.ts:672

        return new PayslipDraft(
            employee_id: (string) ($employee->id ?? ''),
            period_start: $periodStart,
            period_end: $periodEnd,
            daily_rate: Money::round($daily),                                     // [R24] payroll.ts:680

            work_days: $work_days,
            holiday_days: $holiday_days,
            rest_days: $rest_days,
            absence_days: $absence_days,
            unpaid_leave_days: $unpaid_leave_days,
            paid_leave_days: $paid_leave_days,

            work_days_pay: $work_days_pay,
            holiday_pay: $holiday_pay,
            rest_day_pay: $rest_day_pay,
            basic_pay: $basic_pay,
            allowances: $allowances,
            total_base_pay: $total_base_pay,
            overtime_hours: $overtime_hours,
            overtime_pay: $overtime_pay,
            overtime_acknowledged: $overtime_acknowledged,
            night_differential: $night_differential,
            leave_pay: $leave_pay,
            other_earnings: $other_earnings,
            bonus: Money::round($bonus),                                          // [R25] payroll.ts:700
            thirteenth_month: $thirteenth_month,
            gross_pay: $gross_pay,

            sss: $deductions['sss'],
            sss_er: $sss_er,
            philhealth: $deductions['philhealth'],
            philhealth_er: $philhealth_er,
            pagibig: $deductions['pagibig'],
            pagibig_er: $pagibig_er,
            withholding_tax: $deductions['withholding_tax'],
            other_deductions: $deductions['other_deductions'],
            late_minutes: $lateMinutes,
            late_deduction: $deductions['late_deduction'],
            undertime_minutes: $undertimeMinutes,
            undertime_deduction: $deductions['undertime_deduction'],
            total_deductions: $total_deductions,
            // Deliberately unclamped. This CAN be negative.
            net_pay: Money::round($gross_pay - $total_deductions),                // [R26] payroll.ts:717
            // DIVERGENCE 14: counts and a settled amount, reported beside
            // the day buckets; 0 on the reference path.
            presumed_days: (float) count($presumed),
            settlement: $settlement,
            // DIVERGENCE 15: the days of leave credits this payslip charged,
            // absences and converted lateness together; 0 on the reference path.
            leave_charged_days: $chargedAbsenceDays + ($leaveCharges === null ? 0.0 : self::num($leaveCharges['late_days'])),
            // DIVERGENCE 19: the salary the tables were read at, reported.
            contribution_base: Money::round($contributionBase),
        );
    }

    /**
     * The DTR rows a payslip prices, and the presumed dates among them: the
     * reference's employment window over the period, then divergence 14's
     * presumption after the counted date. Public so the payroll application
     * can read the SAME rows the engine prices — for what a day's lateness
     * and absences charge to leave credits (divergence 15) — without a
     * second reading of the attendance.
     *
     * The employment window clips the DTR range. Both comparisons are plain
     * lexicographic comparisons on 'Y-m-d', exactly as the reference does
     * them — strcmp() rather than PHP's `>` so a numeric-looking value can
     * never trip PHP's string-to-number comparison rules. A missing
     * date_hired reproduces JS `undefined > "2025-01-01"` === false, which
     * selects periodStart; a null-or-empty date_resigned is falsy in the
     * reference and selects periodEnd.
     *
     * DELIBERATE DIVERGENCE 14 — THE ADVANCE PAYROLL. A payroll processed
     * before its period ends reads attendance only through
     * `$attendanceThrough`; the days after it are paid as they are
     * scheduled, and what they turn out to be worth is settled on the next
     * payroll (`$settlement` in compute()). The DTR rows of those days are
     * REPLACED here, before anything is priced, so every rule sees a full
     * worked day and never the punches the day happens to carry: the
     * presumption is a decision about the days, not a reading of them. Null
     * — the reference, and every golden vector — replaces nothing, and the
     * rows are the DTR's own.
     *
     * @param  list<AttendanceLog>  $logs
     * @param  list<LeaveRequest>  $leaves
     * @param  list<HolidaySetting>  $holidays
     * @return array{0: list<DayRecord>, 1: array<string, true>} the rows, and the presumed dates
     */
    public static function attendanceDays(object $employee, array $logs, ?Shift $shift, array $leaves, string $periodStart, string $periodEnd, array $holidays, ?string $attendanceThrough): array
    {
        $hired = self::dateKey($employee->date_hired ?? null) ?? $periodStart;
        $resigned = self::dateKey($employee->date_resigned ?? null);

        $effectiveStart = strcmp($hired, $periodStart) > 0 ? $hired : $periodStart;
        $effectiveEnd = ($resigned !== null && strcmp($resigned, $periodEnd) < 0)
            ? $resigned
            : $periodEnd;

        /** @var list<DayRecord> $days */
        $days = strcmp($effectiveStart, $effectiveEnd) <= 0
            ? DtrCalculator::compute($logs, $shift, $leaves, $effectiveStart, $effectiveEnd, $holidays)
            : [];

        /** @var array<string, true> $presumed */
        $presumed = [];
        if ($attendanceThrough !== null && strcmp($attendanceThrough, $effectiveEnd) < 0) {
            $days = self::presumeDays($days, $attendanceThrough, $shift ?? Shift::default(), $holidays, $presumed);
        }

        return [$days, $presumed];
    }

    /**
     * What one day of `$date` pays this employee, exactly as compute()
     * prices a paid day: an entered Rate Per Day as it stands (DIVERGENCE
     * 10), else the monthly rate by the rules' daily-rate method over the
     * month's scheduled working days (DIVERGENCE 13) — the same operations
     * `$dailyFor` performs inside compute(), restated here for a caller
     * that prices days outside a payslip's attendance: the leave credit
     * cash-out, which pays days of credits at this rate. Never used by
     * compute() itself.
     */
    public static function dailyRateOn(object $employee, string $date, ?Shift $shift, PayRules $rules): float
    {
        $storedDaily = self::num($employee->daily_rate ?? null);
        $monthly = self::num($employee->monthly_rate ?? null);
        // DIVERGENCE 18: the salary version in force on the date, when one
        // took over after the first day.
        foreach (is_array($employee->salary_rates ?? null) ? $employee->salary_rates : [] as $segment) {
            if (is_array($segment) && is_string($segment['from'] ?? null) && $segment['from'] <= $date) {
                $monthly = self::num($segment['monthly_rate'] ?? null);
                $storedDaily = self::num($segment['daily_rate'] ?? null);
            }
        }
        if ($storedDaily > 0) {
            return $storedDaily;
        }
        $range = PayrollPeriod::calendarMonthRange($date);
        $scheduled = ShiftResolver::scheduledWorkDaysForShift($range['from'], $range['to'], $shift ?? Shift::default());
        if ($scheduled === 0) {
            throw new RuntimeException('The weekly shift roster needs at least one working day.');
        }

        // DIVERGENCE 16: rounded as compute() rounds its own daily rate.
        return $rules->roundRate(match ($rules->dailyRateMethod) {
            PayRules::METHOD_FACTOR => $monthly * 12 / $rules->dailyRateValue,
            PayRules::METHOD_DIVISOR => $monthly / $rules->dailyRateValue,
            default => $monthly / $scheduled,
        });
    }

    /**
     * DIVERGENCE 14: the DTR rows after `$attendanceThrough`, each scheduled
     * working day among them replaced by a PRESUMED day — present for the
     * paid shift, nothing late, nothing short, no overtime, no night (the
     * night loop skips it) — and recorded in `$presumed`. The rule is about
     * the DAYS: a punch the day already carries, complete or open, is set
     * aside, so finalizing on the 12th prices the 12th to the 15th the same
     * whoever is at work right now.
     *
     * Three kinds of day keep the DTR's own row: a rest day (nothing is
     * owed for it), a holiday (an unworked holiday is priced by its own
     * rule, as the reference prices it) and a whole day of approved leave
     * without a punch (leave is priced as leave). Official business is kept
     * as the DTR credits it — the paid shift, nothing late — with any
     * overtime its punches carried set aside like every other presumed
     * day's.
     *
     * @param  list<DayRecord>  $days
     * @param  list<HolidaySetting>  $holidays
     * @param  array<string, true>  $presumed  filled with the presumed dates
     * @return list<DayRecord>
     */
    private static function presumeDays(array $days, string $attendanceThrough, Shift $schedule, array $holidays, array &$presumed): array
    {
        $result = [];
        foreach ($days as $day) {
            if (strcmp($day->date, $attendanceThrough) <= 0
                || ! ShiftResolver::worksOnDate($day->date, $schedule)
                || HolidaySetting::on($day->date, $holidays) !== null
                || ($day->status === DayRecord::EXCUSED && $day->excuseDays >= 1.0)) {
                $result[] = $day;

                continue;
            }
            $presumed[$day->date] = true;
            $officialBusiness = $day->status === DayRecord::OFFICIAL_BUSINESS;
            $result[] = new DayRecord(
                date: $day->date,
                timeIn: null,
                breakOut: null,
                breakIn: null,
                timeOut: null,
                workedMinutes: ShiftResolver::paidShiftMinutes($schedule->forDate($day->date)),
                lateMinutes: 0.0,
                undertimeMinutes: 0.0,
                overtimeMinutes: 0.0,
                excused: $officialBusiness,
                excuseType: $officialBusiness ? $day->excuseType : null,
                excusePaid: null,
                breakMinutes: 0.0,
                excuseDays: 0.0,
                status: $officialBusiness ? DayRecord::OFFICIAL_BUSINESS : DayRecord::PRESENT,
            );
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Coercions. Each reproduces a JS operator the reference leans on.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * payroll.ts:513-514 — `entered()`.
     *
     *     round(typeof value === "number" && Number.isFinite(value) ? Math.max(0, value) : 0)
     *
     * A hand-entered amount, floored at zero and rounded to cents. This is
     * rounding call site [R07]: one textual site in the reference and one here,
     * reached by allowances, leave_pay, other_earnings and a typed night
     * differential.
     */
    private static function entered(?float $value): float
    {
        $usable = $value !== null && is_finite($value) ? max(0.0, $value) : 0.0;

        return Money::round($usable);                                             // [R07] payroll.ts:514
    }

    /**
     * `typeof value === "number" && Number.isFinite(value)`.
     *
     * A JSON-decoded draft override carries real ints and floats; the string
     * "5" is NOT a number in the reference and must not become one here.
     */
    private static function isNumberValue(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function numberOrNull(mixed $value): ?float
    {
        return self::isNumberValue($value) ? (float) $value : null;
    }

    /**
     * JS `Number(v)`, as far as this engine can reach it.
     *
     * The reference wraps every 201-file numeric column in `Number(...)`.
     * Garbage yields NaN there and 0.0 here; every use of the result is either a
     * `> 0` test or an argument to a Statutory table that absorbs non-finite
     * input, and `NaN > 0` is false exactly as `0.0 > 0` is, so the two agree on
     * every reachable input. PDO also hands DECIMAL columns back as strings,
     * which is the case this mainly exists for.
     */
    private static function num(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * 'Y-m-d' for a date column that may arrive as a string or a date object.
     *
     * The reference receives serialised 'Y-m-d' strings and the whole
     * employment-window clip is lexicographic string comparison. An empty string
     * is falsy in the reference, so it reads as absent here too.
     */
    private static function dateKey(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * TS: `payrollRates(settings?.formulas)` — payroll.ts:168-194.
     *
     * PayrollRates resolves the active OT, NSD and rest-day multipliers and
     * hour caps. Invalid values use their defaults; zero is a legitimate cap.
     *
     * @param  list<mixed>  $formulas
     */
}
