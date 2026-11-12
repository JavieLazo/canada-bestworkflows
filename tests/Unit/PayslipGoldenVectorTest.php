<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\CalendarDays;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Domain\Attendance\ShiftResolver;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayRulesTimeline;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\PayslipDraft;
use App\Domain\Payroll\Statutory;
use App\Support\ManilaClock;
use App\Support\Money;

/**
 * Differential test of the WHOLE payslip chain against vectors emitted from
 * the real TypeScript engine — computePayslip() at copems/src/lib/payroll.ts,
 * which internally runs computeDtr(), so one vector exercises punch pairing,
 * the overnight carry-over slice, day buckets, holiday pricing, OT/NSD/
 * rest-day, lateness/undertime, contributions and withholding end to end.
 *
 * Regenerate with:  bun tools/emit-payslip-vectors.ts
 *
 * Comparison is PER FIELD with strict identity, never a whole-object JSON
 * string compare: PHP renders large-magnitude doubles as 1.0e+21 where JS
 * renders 1e+21, so a string-level comparison would fail on formatting rather
 * than on arithmetic. Numeric fields are compared float-to-float; PHP's ===
 * on floats is numeric, so a JS -0 that JSON.stringify flattened to 0 still
 * matches a PHP -0.0.
 */
const PAYSLIP_STRING_FIELDS = ['employee_id', 'period_start', 'period_end'];
const PAYSLIP_BOOL_FIELDS = ['overtime_acknowledged'];

/*
|--------------------------------------------------------------------------
| The first divergence, RETIRED
|--------------------------------------------------------------------------
| The engine computes SSS, PhilHealth, Pag-IBIG and withholding literally as
| payroll.ts:583-660 does (PayslipCalculator [R15]-[R21], through
| StatutoryLines), so every one of the seven fields is held to the
| TypeScript engine.
|
| They are held STRICTLY wherever the engine saw the same inputs the
| reference did. Where a later divergence (2-6) moved gross, late or
| undertime, the tax-derived figures cannot equal the reference's and are
| RESTATED instead — never relaxed to existence: the reference's own formula
| (vectorContribution, vectorWithholding, both ported from payroll.ts over
| Statutory's tables, which GoldenVectorTest pins across 102,197 rows) is
| applied to the ENGINE's gross, late and undertime, and the engine must
| produce exactly that. The six contribution lines depend on gross only
| through the `gross > 0` gate (payroll.ts:588), so they are strict whenever
| both engines agree on that gate.
*/
const PAYSLIP_CONTRIBUTIONS = ['sss', 'sss_er', 'philhealth', 'philhealth_er', 'pagibig', 'pagibig_er'];

/*
|--------------------------------------------------------------------------
| The second divergence: a holiday worked on a rest day
|--------------------------------------------------------------------------
| The reference prices such a day as the holiday alone. EMS compounds the
| holiday's percent by the Formulas -> Rest Day percentage (floored at 1), so
| a regular holiday worked on a Sunday pays 200% x 1.3 rather than 200%.
|
| Which DAYS compound is not visible from a vector's output, so these fields
| are bounded rather than predicted: compounding only ever raises pay, and it
| cannot raise any component by more than the uplift itself. A vector whose
| uplift is 1.0 — 477 of them set the Rest Day percentage to 0 — can compound
| nothing, and every field of it is still held to strict identity.
|
| The exact arithmetic is pinned in PayrollModuleTest instead, where the
| holiday, the rest day and the rate are all chosen rather than swept.
*/
const PAYSLIP_COMPOUNDABLE = [
    'holiday_pay', 'basic_pay', 'total_base_pay', 'overtime_pay', 'gross_pay',
];

/*
|--------------------------------------------------------------------------
| The third divergence: scheduled break slots
|--------------------------------------------------------------------------
| The EMS treats a fixed shift with a real break window as four neutral DTR
| slots: First Time In, First Time Out, Second Time In, Final Time Out. The
| TypeScript corpus has no such policy, so vectors with such a window cannot
| be held to strict identity on fields derived from worked, late,
| undertime or overtime minutes. The arithmetic is pinned in
| DtrBreakWindowTest; this corpus still checks every unaffected vector and the
| non-time-derived fields around them.
*/
const PAYSLIP_SCHEDULED_BREAK_SLOT_AFFECTED = [
    'work_days', 'holiday_days', 'rest_days', 'absence_days', 'unpaid_leave_days',
    'work_days_pay', 'holiday_pay', 'rest_day_pay', 'basic_pay', 'total_base_pay',
    'overtime_hours', 'overtime_pay', 'night_differential', 'leave_pay', 'gross_pay',
    'late_minutes', 'late_deduction', 'undertime_minutes', 'undertime_deduction',
    'total_deductions', 'net_pay',
];

/*
|--------------------------------------------------------------------------
| The fourth divergence: unwindowed overnight carry-over ordering
|--------------------------------------------------------------------------
| A fixed overnight shift without scheduled break slots is one continuous punch
| stream: 20:00 -> 01:00 is five hours on the shift-start work date. A flat
| break allowance changes minutes after pairing; it does not create a separate
| break slot. The TypeScript reference slices the next day's carry-over logs
| before sorting them, so newest-first input can drop the next-day TIME_OUT and
| turn a valid night shift into false undertime.
|
|--------------------------------------------------------------------------
| The fifth divergence: flexible cross-midnight carry-over
|--------------------------------------------------------------------------
| Flexible shifts do not have fixed overnight geometry, but an open flexible
| TIME_IN still pairs with the next TIME_OUT before the employee's next TIME_IN.
| The TypeScript reference leaves those next-day outs on the next calendar row.
*/

/*
|--------------------------------------------------------------------------
| The sixth divergence: approved paid leave is its own payslip line
|--------------------------------------------------------------------------
| The reference folds an approved paid-leave day into `workedDays`
| (payroll.ts:492) and prices it as work-day pay, leaving `leave_pay` to mean
| something else entirely — "hand-entered pay for working a day already filed
| as leave" (payroll.ts:254). It has no leave module to compute from. EMS does,
| so the Leave line carries those pesos and `work_days` counts only days
| the employee actually punched.
|
| THE PESOS DO NOT MOVE. The leave line is CARVED OUT of the same rounded total
| payroll.ts:497 produces, never rounded a second time, so on a vector with no
| leave_pay override `gross_pay` is still reference-exact — and `net_pay` with
| it. That is the proof this is a reclassification and not a pay rise.
|
| These fields are therefore NOT relaxed to existence, unlike divergences 3-5.
| Every one of them is still pinned exactly, restated in the reference's own
| figures the way `total_deductions` is: `$reclassified` is read back out of
| the reference's `work_days_pay`, and each field must equal the reference less
| exactly that. Where the second divergence also applies — a holiday worked on
| a rest day — the reclassified figure is put through that same bound instead.
|
| Deliberately UNCHANGED, and not an oversight: leave on an unpunched rest day
| or a declared holiday still pays nothing (DtrCalculator.php:466), and a
| whole-day leave the employee worked anyway still pays one work day and no
| leave (DtrCalculator.php:532). Those are separate decisions, not this one.
|
| The arithmetic is pinned directly in PayrollLeavePayTest.
*/
const PAYSLIP_PAID_LEAVE_RECLASSIFIED = [
    'work_days', 'work_days_pay', 'basic_pay', 'total_base_pay', 'leave_pay', 'gross_pay',
];

/**
 * Whether a vector actually reclassified anything.
 *
 * Deliberately read off the ENGINE's own `paid_leave_days` rather than off the
 * vector's leave rows: 376 vectors carry an approved, non-travel, paid leave
 * overlapping the period, but only 193 of them fold one — the rest fall on an
 * unpunched rest day, a holiday, or a day the employee worked anyway. Matching
 * on the input would unpin 183 vectors that never moved. The direction of this
 * choice is safe: an engine that wrongly reported 0 would not take this branch
 * and would be caught by strict identity below.
 *
 * @param  array<string, mixed>  $actual
 */
function vectorReclassifiesPaidLeave(array $actual): bool
{
    return $actual['paid_leave_days'] > 0.0;
}

/**
 * The Rest Day uplift a vector's own settings imply, floored as the engine floors it.
 *
 * @param  array<string, mixed>  $input
 */
function vectorRestDayUplift(array $input): float
{
    $rate = 1.3; // PayrollRates::DEFAULT_REST_DAY_RATE

    foreach (($input['settings']['formulas'] ?? []) as $formula) {
        if (($formula['key'] ?? null) !== 'REST_DAY') {
            continue;
        }
        foreach (($formula['params'] ?? []) as $param) {
            if (($param['key'] ?? null) === 'percentage' && is_numeric($param['value'] ?? null)) {
                $rate = (float) $param['value'];
            }
        }
    }

    return max(1.0, $rate);
}

/**
 * @param  array<string, mixed>  $input
 */
function vectorUsesScheduledBreakSlots(array $input): bool
{
    return is_array($input['shift'] ?? null)
        && ShiftResolver::scheduledBreakWindow(Shift::fromArray($input['shift'])) !== null;
}

/**
 * @param  array<string, mixed>  $input
 */
function vectorUsesUnwindowedFixedOvernightShift(array $input): bool
{
    if (! is_array($input['shift'] ?? null)) {
        return false;
    }

    $shift = Shift::fromArray($input['shift']);
    $geometry = ShiftResolver::geometry($shift);

    return ! $shift->isFlexible()
        && $geometry->overnight
        && ShiftResolver::scheduledBreakWindow($shift, $geometry) === null;
}

/**
 * @param  list<AttendanceLog>  $logs
 */
function vectorHasOpenTimeIn(array $logs): bool
{
    usort(
        $logs,
        static fn (AttendanceLog $a, AttendanceLog $b): int => ManilaClock::ms(ManilaClock::instant($a->loggedAt))
            <=> ManilaClock::ms(ManilaClock::instant($b->loggedAt)),
    );

    $openIn = null;
    foreach ($logs as $log) {
        if ($log->logType === AttendanceLog::TIME_IN) {
            if ($openIn === null || $openIn === '') {
                $openIn = $log->loggedAt;
            }

            continue;
        }

        if ($openIn !== null && $openIn !== '') {
            $openIn = null;
        }
    }

    return $openIn !== null && $openIn !== '';
}

/**
 * @param  array<string, mixed>  $input
 */
function vectorUsesFlexibleCrossMidnightCarry(array $input): bool
{
    if (! is_array($input['shift'] ?? null)) {
        return false;
    }

    $shift = Shift::fromArray($input['shift']);
    if (! $shift->isFlexible()) {
        return false;
    }

    /** @var array<string, list<AttendanceLog>> $byDay */
    $byDay = [];
    foreach (AttendanceLog::listFromArrays($input['logs'] ?? []) as $log) {
        $byDay[ManilaClock::dayKey(ManilaClock::instant($log->loggedAt))][] = $log;
    }

    $dates = array_merge(
        [CalendarDays::previous((string) $input['periodStart'])],
        CalendarDays::each((string) $input['periodStart'], (string) $input['periodEnd']),
    );

    foreach ($dates as $date) {
        if (! vectorHasOpenTimeIn($byDay[$date] ?? [])) {
            continue;
        }

        $next = $byDay[CalendarDays::next($date)] ?? [];
        usort(
            $next,
            static fn (AttendanceLog $a, AttendanceLog $b): int => ManilaClock::ms(ManilaClock::instant($a->loggedAt))
                <=> ManilaClock::ms(ManilaClock::instant($b->loggedAt)),
        );

        foreach ($next as $log) {
            if ($log->logType === AttendanceLog::TIME_IN) {
                break;
            }

            return true;
        }
    }

    return false;
}

/**
 * JavaScript truthiness, as the reference reads an employee switch or mode.
 */
function vectorTruthy(mixed $value): bool
{
    return match (true) {
        $value === null => false,
        is_bool($value) => $value,
        is_int($value), is_float($value) => $value != 0 && ! is_nan((float) $value),
        is_string($value) => $value !== '',
        default => true,
    };
}

/** JavaScript `Number()` for an amount: junk and non-finite values read as 0. */
function vectorNumber(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return is_finite((float) $value) ? (float) $value : 0.0;
    }

    return is_numeric($value) ? (float) $value : 0.0;
}

/**
 * One of the six contribution lines as the reference computes it
 * (payroll.ts:201-212 at the call sites :589-640), over Statutory's tables,
 * given the `gross > 0` gate the ENGINE saw. The three employee lines then
 * pass normalizedEmployeeDeductions (payroll.ts:319), which floors them at 0.
 *
 * @param  array<string, mixed>  $employee
 */
function vectorContribution(array $employee, string $field, string $periodEnd, bool $hasGrossPay): float
{
    [$agency, $side, $suffix] = match ($field) {
        'sss' => ['sss', 'employee', 'ee'],
        'sss_er' => ['sss', 'employer', 'er'],
        'philhealth' => ['philhealth', 'employee', 'ee'],
        'philhealth_er' => ['philhealth', 'employer', 'er'],
        'pagibig' => ['pagibig', 'employee', 'ee'],
        'pagibig_er' => ['pagibig', 'employer', 'er'],
        default => throw new LogicException("Not a contribution line: {$field}"),
    };

    if (! vectorTruthy($employee['allow_'.$agency] ?? null) || ! $hasGrossPay) {
        return 0.0;
    }

    $salary = vectorNumber($employee['monthly_rate'] ?? null);
    $table = match ($agency) {
        'sss' => Statutory::sss($salary),
        'philhealth' => Statutory::philhealth($salary),
        'pagibig' => Statutory::pagibig($salary),
    };
    $fixed = vectorNumber($employee["fixed_{$agency}_{$suffix}"] ?? null);
    $monthly = $fixed > 0 ? $fixed : $table[$side];
    $mode = $employee[$agency.'_mode'] ?? null;

    $raw = (vectorTruthy($mode) && $mode !== 'SEMI_MONTHLY')
        ? ((int) substr($periodEnd, 8, 2) > 15 ? $monthly : 0.0)
        : $monthly / 2;

    $value = Money::round($raw);

    return $side === 'employee' ? max(0.0, $value) : $value;
}

/**
 * The withholding line as the reference computes it (payroll.ts:648-660,
 * then the payroll.ts:319 floor) on a given taxable base.
 *
 * @param  array<string, mixed>  $employee
 */
function vectorWithholding(array $employee, float $taxable): float
{
    if (! vectorTruthy($employee['allow_withholding_tax'] ?? null)) {
        return 0.0;
    }

    $fixed = vectorNumber($employee['fixed_tax'] ?? null);
    $percent = vectorNumber($employee['percent_tax'] ?? null);
    $graduated = vectorTruthy($employee['annualize_tax'] ?? null)
        ? Statutory::annualizedSemiMonthlyWithholdingTax($taxable)
        : Statutory::semiMonthlyWithholdingTax($taxable);

    return max(0.0, Money::round($fixed > 0 ? $fixed : ($percent > 0 ? ($taxable * $percent) / 100 : $graduated)));
}

/*
|--------------------------------------------------------------------------
| The seventh divergence: overtime is approved per day on the DTR
|--------------------------------------------------------------------------
| The reference pays a run's overtime once a payroll officer ticks one
| "acknowledged" switch for the employee (payroll.ts:538-563). EMS approves
| it per day on the Daily Time Record, and PayslipCalculator::compute() takes
| the decisions as a trailing `$overtimeApprovals` map: a day pays
| min(computed, approved, the Formulas cap), and an undecided day pays nothing.
|
| NO FIELD IS RELAXED. Every vector passes no map (null), which is the
| reference's own regime byte for byte, so the comparison below stays strict.
| What the vectors cannot see is the map itself, so the loop carries a GUARD
| instead of a predicate: on every vector with overtime it computes again
| through the map and requires
|
|  - an acknowledged vector, with every day approved in full, to reproduce
|    the reference-path draft EXACTLY, field for field; and
|  - an unacknowledged vector, with nothing approved, to reproduce it too,
|    except `overtime_hours` and `overtime_pay`, which the reference reports
|    unpaid and the map path reports as nothing approved.
|
| That is the proof the divergence changes WHICH minutes are paid, never how
| a minute is priced. The per-day arithmetic is pinned in
| PayrollOvertimeApprovalTest.
*/

/**
 * Observed on a green run: 1,965 of the 6,921 vectors compute overtime on
 * the reference path. Under the eleventh divergence the 454 flexible ones
 * among them compute none, so 1,511 remain.
 */
const PAYSLIP_OVERTIME_GUARDED_FLOOR = 1_511;

/*
|--------------------------------------------------------------------------
| The eighth divergence: weekly and one-date rosters choose different hours
|--------------------------------------------------------------------------
| The reference carries one shift for every date. EMS can substitute a copied
| shift on each weekday (or mark that weekday a rest day), and a one-date
| exception outranks that weekly choice. DTR geometry, scheduled-day
| divisors, hourly charges and scheduled night differential follow the shift
| START date. The corpus has no such map.
|
| NO FIELD IS RELAXED. Every vector is recomputed with its existing scalar
| shift explicitly repeated on all seven weekdays and every period date;
| the entire result must
| equal its original computation strictly. WeeklyShiftScheduleTest separately
| pins mixed night/day hours, previous-night ownership, unequal daily hours,
| changed rest days and exact pay. The strict reference floor stays intact.
*/
const PAYSLIP_WEEKLY_GUARDED_FLOOR = 6_921;

/*
|--------------------------------------------------------------------------
| The ninth divergence: named Payroll Deductions
|--------------------------------------------------------------------------
| Records Management supplies named Cash Advance and loan deductions.
| They are charged after statutory tax, separate from the reference's Other
| amount, and reduce net pay. The TypeScript corpus has no named lines.
|
| NO REFERENCE COMPARISON IS RELAXED. Every vector below checks that the
| empty named-line path is byte-for-byte the same draft, then checks a
| synthetic amount changes only the custom aggregate, total and net.
| PayrollCustomDeductionsTest pins the captured and posted application path.
*/
const PAYSLIP_CUSTOM_DEDUCTION_GUARDED_FLOOR = 6_921;

/*
|--------------------------------------------------------------------------
| The tenth divergence: Rate Per Day
|--------------------------------------------------------------------------
| The reference derives every day's pay from `monthly_rate`: that rate over
| the month's scheduled working days. EMS's employee record also carries a
| Rate Per Day (`daily_rate`), and when it is above zero PayslipCalculator
| prices every day at it as it stands. When the monthly rate is blank or
| zero, the contribution tables read the day rate times the month's working
| days instead of 0. The corpus has no such field, so every vector still
| takes the reference path.
|
| NO FIELD IS RELAXED. Every single-month vector with a monthly rate is
| recomputed with a Rate Per Day equal to the unrounded day the reference
| derives, and the whole draft must equal the reference path strictly. That
| proves the rate substitutes at the one derivation point and nowhere else,
| and that a monthly rate keeps the contribution base. PayrollDailyRateTest
| pins a rate that differs from the derived one, the blank-monthly base and
| the per-hour figures.
*/
/** Observed on a green run: 6,600 of the 6,921 vectors are single-month with a monthly rate above 0. */
const PAYSLIP_DAILY_RATE_GUARDED_FLOOR = 6_600;

/*
|--------------------------------------------------------------------------
| The eleventh divergence: a flexible day computes no overtime
|--------------------------------------------------------------------------
| The reference credits a FLEXIBLE day every minute past its `required_hours`
| as overtime (dtr.ts:497), so a Flexi 8 employee in at 06:55 and out at
| 16:05 earns ten minutes of it. In EMS a flexible day computes NONE: it
| owes its hours, is short below them, and keeps everything above them as
| worked minutes (DtrCalculator, divergence 11). A FIXED shift is untouched.
|
| RESTATED EXACTLY, never relaxed. On every flexible vector the reference
| computed overtime for, `overtime_hours` and `overtime_pay` are pinned to
| 0.0. Where the reference's acknowledgement switch had also PAID that
| overtime, its `gross_pay` is restated as the reference's own figure less
| exactly its own `overtime_pay` — the rounded line the switch added at
| payroll.ts:561 — and that restated gross stands in for the reference in
| every later branch (the rest-day bound, the leave carve-out). The tax, the
| total and the net then follow the restatement every vector whose gross
| moved already gets: the reference's own tax on the engine's base. Nothing
| else changes: an unacknowledged vector keeps every other field strict, and
| the divergence 7 guard still runs on every vector that computes overtime —
| the flexible ones compute none, so its floor leaves them out.
|
| The arithmetic is pinned in DtrFlexibleOvertimeTest.
*/
const PAYSLIP_FLEXIBLE_OVERTIME_RETIRED = ['overtime_hours', 'overtime_pay'];

/** Observed on a green run: 437 of the 6,921 vectors are flexible shifts the reference computed overtime on, 56 of them with the switch on. */
const PAYSLIP_FLEXIBLE_OVERTIME_RETIRED_FLOOR = 437;

/*
|--------------------------------------------------------------------------
| The twelfth divergence: selected approved leave dates can be cancelled
|--------------------------------------------------------------------------
| Cancelled dates carry no leave excuse or leave pay; every uncancelled date
| retains its original approved facts. The reference has no cancellations,
| so NO reference comparison is relaxed. The guard cancels one covered date
| on each vector with overlapping approved leave and requires exact parity
| with the same leave represented as individual uncancelled dates. Focused
| DtrLeaveCancellationTest pins absent days, half days and actual pesos.
*/
const PAYSLIP_LEAVE_CANCELLATION_GUARDED_FLOOR = 964;

/*
|--------------------------------------------------------------------------
| The thirteenth divergence: Pay Basis & Rates are a saved version's figures
|--------------------------------------------------------------------------
| The reference prices every day one way: pay built up from the days worked,
| the daily rate as the monthly rate over the month's scheduled working
| days, overtime and night pay from three Formulas rows, holiday overtime at
| the holiday's percent times 1.3, the night window fixed at 22:00–06:00
| and no night pay on overtime hours. EMS hands the engine a PayRules
| value — the pay basis, the daily-rate method, the night window, whether
| overtime earns night pay, the holiday overtime factor and a matrix of
| explicit rates by day type — read strictly from a dated version. A blank
| matrix cell means "as the reference derives it". The corpus carries no
| version, so every vector still takes the reference path.
|
| NO FIELD IS RELAXED. Every vector is recomputed with the reference's own
| figures written out as a version's figures and read back strictly
| (PayRules::fromArray(PayRules::fromFormulas(...)->toArray())), and the
| whole draft must equal the reference path strictly. That proves the
| switches sit at the derivation points and nowhere else, and that a saved
| version round-trips without moving a bit. PayRulesTest pins what each
| switch changes, in pesos.
*/
const PAYSLIP_PAY_RULES_GUARDED_FLOOR = 6_921;

/*
|--------------------------------------------------------------------------
| The fourteenth divergence: the advance payroll
|--------------------------------------------------------------------------
| The reference reads every day of the period from its attendance. EMS lets
| a payroll be processed before its period ends: the engine takes an
| "attendance counted through" date and REPLACES the DTR rows after it with
| presumed full days (no lateness, undertime, overtime or night pay), and
| takes a settlement — what presumed days on an earlier payslip turned out
| to be worth, less what was presumed — into gross pay before the tax. The
| corpus carries neither, so every vector still takes the reference path.
|
| NO FIELD IS RELAXED. Every vector is recomputed with the counted date set
| to its own period end (which presumes nothing) and a settlement of zero,
| and the whole draft must equal the reference path strictly: the two
| switches are inert at their boundaries and sit nowhere else.
| PayrollAdvanceTest pins what each does, in days and pesos.
*/
const PAYSLIP_ADVANCE_GUARDED_FLOOR = 6_921;

/*
|--------------------------------------------------------------------------
| The fifteenth divergence: attendance charged to leave credits
|--------------------------------------------------------------------------
| The reference prices every late minute and every absence to pay. EMS lets
| an employee's leave policy charge them to leave credits first: the engine
| takes what credits cover — the covered fraction of each late day's minutes
| and the covered part of each absent day, which it pays as leave — and
| prices only the rest. The corpus carries no policy, so every vector takes
| the reference path.
|
| NO FIELD IS RELAXED. Every vector is recomputed with an EMPTY charge map
| (nothing covered) and the whole draft must equal the reference path
| strictly: an empty map is inert, and the switch sits nowhere else.
| LeaveChargesTest and PayrollLeaveChargeTest pin what a charge does.
*/
const PAYSLIP_LEAVE_CHARGE_GUARDED_FLOOR = 6_921;

/*
|--------------------------------------------------------------------------
| The sixteenth divergence: a version that rounds prices like a register
|--------------------------------------------------------------------------
| The reference prices from unrounded rates, day by day. A Pay Basis & Rates
| version with `rounding.decimals` set rounds the daily and hourly rates, adds
| up the hours at each hourly rate and factor, and rounds each amount once:
| a payroll register's ROUND(hours × hourly × factor, 2). The corpus carries
| no such version, so every vector takes the reference path.
|
| NO FIELD IS RELAXED. The thirteenth divergence's guard recomputes every
| vector under a saved version whose rounding is blank and requires the
| reference path strictly, which proves a blank version adds nothing.
| PayRulesTest pins the rounded pesos, MoneyRoundToTest the rounding itself.
*/

/**
 * Equivalent calculation inputs with a selected cancellation and with that
 * same date omitted from an otherwise identical set of single-day leaves.
 *
 * @param  array<string, mixed>  $input
 * @return array{array<string, mixed>, array<string, mixed>}|null
 */
function vectorSelectedLeaveCancellation(array $input): ?array
{
    foreach ($input['leaves'] as $index => $row) {
        $start = substr((string) $row['start_date'], 0, 10);
        $end = substr((string) $row['end_date'], 0, 10);
        if ($row['status'] !== 'APPROVED' || $row['request_type'] === 'BUSINESS_TRAVEL'
            || $start > $input['periodEnd'] || $end < $input['periodStart']) {
            continue;
        }
        $cancelled = max($start, (string) $input['periodStart']);
        $withCancellation = $input;
        $withCancellation['leaves'][$index]['cancelled_dates'] = [$cancelled];
        $singleDays = [];
        foreach (CalendarDays::each($start, $end) as $date) {
            if ($date !== $cancelled) {
                $singleDays[] = array_replace($row, ['start_date' => $date, 'end_date' => $date]);
            }
        }
        array_splice($input['leaves'], $index, 1, $singleDays);

        return [$withCancellation, $input];
    }

    return null;
}

/**
 * Whether the reference computed overtime on this vector's FLEXIBLE shift.
 *
 * @param  array<string, mixed>  $input
 * @param  array<string, mixed>  $want
 */
function vectorRetiresFlexibleOvertime(array $input, array $want): bool
{
    return is_array($input['shift'] ?? null)
        && Shift::fromArray($input['shift'])->isFlexible()
        && (float) ($want['overtime_hours'] ?? 0) > 0.0;
}

/**
 * The unrounded daily rate the engine derives for the vector's first month:
 * its monthly rate over that month's scheduled working days, the division
 * PayslipCalculator makes when no Rate Per Day is entered.
 *
 * @param  array<string, mixed>  $in
 */
function vectorDerivedDailyRate(array $in): float
{
    $schedule = is_array($in['shift'] ?? null) ? Shift::fromArray($in['shift']) : Shift::default();
    $range = PayrollPeriod::calendarMonthRange((string) $in['periodStart']);

    return vectorNumber($in['employee']['monthly_rate'] ?? null)
        / ShiftResolver::scheduledWorkDaysForShift($range['from'], $range['to'], $schedule);
}

/**
 * Every day of the vector's period approved far beyond anything it computed,
 * so min(computed, approved, cap) is the reference's min(computed, cap).
 *
 * @param  array<string, mixed>  $in
 * @return array<string, int>
 */
function vectorFullOvertimeApprovals(array $in): array
{
    $approvals = [];
    foreach (CalendarDays::each((string) $in['periodStart'], (string) $in['periodEnd']) as $date) {
        $approvals[$date] = 1_000_000;
    }

    return $approvals;
}

/**
 * Maps one JSON vector input onto the real PHP domain API — the same
 * fromArray() factories the application's own query layer uses. Nothing in
 * app/Domain is bent toward the fixture; if a vector could not be expressed
 * through this public API, that would be a finding, not a mapping hack.
 *
 * @param  array<string, mixed>  $in
 * @param  array<string, int>|null  $overtimeApprovals  null for the reference path; see the seventh divergence.
 * @param  array{late: array<string, float>, absence: array<string, float>, late_days: float}|null  $leaveCharges  null for the reference path; see the fifteenth divergence.
 * @param  list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>|null  $salaryRates  null for the reference path; the eighteenth divergence's segments.
 * @param  array<string, float>|null  $contributionPosted  null for the reference path; the nineteenth divergence's posted shares.
 * @return array<string, mixed>
 */
function computePayslipFromVector(array $in, ?array $overtimeApprovals = null, ?PayRules $rules = null, ?string $attendanceThrough = null, float $settlement = 0.0, ?array $leaveCharges = null, ?PayRulesTimeline $timeline = null, ?array $salaryRates = null, ?array $contributionPosted = null): array
{
    $settings = null;
    if (array_key_exists('settings', $in) && is_array($in['settings'])) {
        $settings = [
            'formulas' => $in['settings']['formulas'] ?? [],
            'holidays' => HolidaySetting::listFromArrays($in['settings']['holidays'] ?? []),
        ];
    }

    return PayslipCalculator::compute(
        employee: (object) ($salaryRates === null ? $in['employee'] : $in['employee'] + ['salary_rates' => $salaryRates]),
        logs: AttendanceLog::listFromArrays($in['logs']),
        shift: is_array($in['shift'] ?? null) ? Shift::fromArray($in['shift']) : null,
        leaves: LeaveRequest::listFromArrays($in['leaves']),
        periodStart: (string) $in['periodStart'],
        periodEnd: (string) $in['periodEnd'],
        bonus: (float) $in['bonus'],
        allowancesOverride: array_key_exists('allowancesOverride', $in)
            ? (float) $in['allowancesOverride']
            : null,
        earningsOverride: $in['earningsOverride'] ?? null,
        settings: $settings,
        overtimeApprovals: $overtimeApprovals,
        rules: $rules,
        attendanceThrough: $attendanceThrough,
        settlement: $settlement,
        leaveCharges: $leaveCharges,
        timeline: $timeline,
        contributionPosted: $contributionPosted,
    )->toArray();
}

it('PayslipGolden: matches the TypeScript engine on every golden payslip vector', function () {
    $path = __DIR__.'/../fixtures/golden-payslips.ndjson';
    expect(file_exists($path))->toBeTrue('Fixture missing — run: bun tools/emit-payslip-vectors.ts');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Could not open fixture {$path}");
    }
    $mismatches = [];
    $count = 0;

    // How many field comparisons reached the literal `===` branch below. The
    // divergence branches above it relax fields to existence or to a bound, so
    // the corpus can stay at 6,921 vectors while the number of fields actually
    // held to the TypeScript engine quietly shrinks. This count is what says
    // how much of the contract is still enforced; the floor after the loop
    // pins it.
    $strict = 0;
    // How many vectors the seventh divergence's guard recomputed.
    $approvalGuarded = 0;
    $weeklyGuarded = 0;
    $customGuarded = 0;
    $dailyGuarded = 0;
    $leaveCancellationGuarded = 0;
    $payRulesGuarded = 0;
    $advanceGuarded = 0;
    $leaveChargeGuarded = 0;
    // How many vectors the eleventh divergence restated.
    $flexibleRetired = 0;

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $vector = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $index = $count;
        $count++;

        $actual = computePayslipFromVector($vector['input']);
        $want = $vector['expected'];

        // DIVERGENCE 12 keeps the reference path strict and proves that
        // cancellation is exactly omission of those dates from the excuse.
        $leaveVariants = vectorSelectedLeaveCancellation($vector['input']);
        if ($leaveVariants !== null) {
            $withCancellation = computePayslipFromVector($leaveVariants[0]);
            $withoutDate = computePayslipFromVector($leaveVariants[1]);
            $leaveCancellationGuarded++;
            foreach ($withoutDate as $field => $value) {
                if ($withCancellation[$field] !== $value) {
                    $mismatches[] = sprintf('vector #%d divergence 12 guard %s: cancelled date differs from omitted date', $index, $field);
                }
            }
        }

        /*
         * DIVERGENCE 11 (see its banner): the reference computed overtime on
         * this flexible vector and the engine does not. Its overtime
         * fields are pinned to zero in the chain below; where the reference's
         * switch had paid that overtime, its gross is restated here — the
         * reference's own figure less exactly its own overtime line — and
         * stands in for the reference from this point on.
         */
        $retiresFlexibleOvertime = vectorRetiresFlexibleOvertime($vector['input'], $want);
        $retiredOvertimePay = 0.0;
        if ($retiresFlexibleOvertime) {
            $flexibleRetired++;
            if (($vector['input']['earningsOverride']['overtime_acknowledged'] ?? null) === true) {
                $retiredOvertimePay = (float) $want['overtime_pay'];
                $want['gross_pay'] = Money::round((float) $want['gross_pay'] - $retiredOvertimePay);
            }
        }

        $draft = new PayslipDraft(...$actual);
        $withoutNamed = $draft->withCustomDeductions([])->toArray();
        $withNamed = $draft->withCustomDeductions([123.45])->toArray();
        $customGuarded++;
        if ($withoutNamed !== $actual || $withNamed['custom_deductions'] !== 123.45
            || $withNamed['other_deductions'] !== $actual['other_deductions']
            || $withNamed['withholding_tax'] !== $actual['withholding_tax']
            || $withNamed['total_deductions'] !== Money::round($actual['total_deductions'] + 123.45)
            || $withNamed['net_pay'] !== Money::round($actual['gross_pay'] - $withNamed['total_deductions'])) {
            $mismatches[] = sprintf('vector #%d divergence 9 guard: named deduction did not foot separately', $index);
        }

        // DIVERGENCE 10 guard (see its banner): a Rate Per Day equal to the
        // day the reference derives reproduces the reference path exactly.
        $in = $vector['input'];
        if (vectorNumber($in['employee']['monthly_rate'] ?? null) > 0.0
            && substr((string) $in['periodStart'], 0, 7) === substr((string) $in['periodEnd'], 0, 7)) {
            $in['employee']['daily_rate'] = vectorDerivedDailyRate($in);
            $viaDaily = computePayslipFromVector($in);
            $dailyGuarded++;
            foreach ($actual as $field => $value) {
                if ($viaDaily[$field] !== $value) {
                    $mismatches[] = sprintf(
                        'vector #%d divergence 10 guard %s: monthly rate %s, Rate Per Day %s',
                        $index, $field, var_export($value, true), var_export($viaDaily[$field], true),
                    );
                }
            }
        }

        // DIVERGENCE 13 guard (see its banner): the reference's figures as a
        // saved version, read back strictly, reproduce the reference path.
        // DIVERGENCE 17 rides the same recompute: the version handed in as a
        // one-version TIMELINE, so every per-day lookup answers the same
        // figures and the pricing buckets stay the reference's per-month ones.
        $vectorFormulas = is_array($vector['input']['settings']['formulas'] ?? null) ? array_values($vector['input']['settings']['formulas']) : [];
        // DIVERGENCE 18 rides it too: the vector's own salary as a segment in
        // force since always, so every per-date salary lookup answers the same
        // figures and the buckets stay per month.
        $savedRules = PayRules::fromArray(PayRules::fromFormulas($vectorFormulas)->toArray());
        // DIVERGENCE 19 too: nothing posted this contribution month, handed
        // in as zeros — which the reference's two schedules never read.
        $viaRules = computePayslipFromVector($vector['input'], rules: $savedRules, timeline: PayRulesTimeline::single($savedRules), salaryRates: [[
            'from' => PayRulesTimeline::ALWAYS,
            'monthly_rate' => $vector['input']['employee']['monthly_rate'] ?? null,
            'daily_rate' => $vector['input']['employee']['daily_rate'] ?? null,
        ]], contributionPosted: ['sss' => 0.0, 'sss_er' => 0.0, 'philhealth' => 0.0, 'philhealth_er' => 0.0, 'pagibig' => 0.0, 'pagibig_er' => 0.0]);
        $payRulesGuarded++;
        foreach ($actual as $field => $value) {
            if ($viaRules[$field] !== $value) {
                $mismatches[] = sprintf(
                    'vector #%d divergence 13 guard %s: reference %s, saved version %s',
                    $index, $field, var_export($value, true), var_export($viaRules[$field], true),
                );
            }
        }

        // DIVERGENCE 14 guard (see its banner): a counted date on the period's
        // own end presumes nothing, and a zero settlement adds nothing — the
        // draft is the reference path exactly.
        $viaAdvance = computePayslipFromVector($vector['input'], attendanceThrough: (string) $vector['input']['periodEnd'], settlement: 0.0);
        $advanceGuarded++;
        foreach ($actual as $field => $value) {
            if ($viaAdvance[$field] !== $value) {
                $mismatches[] = sprintf(
                    'vector #%d divergence 14 guard %s: reference %s, counted through the period end %s',
                    $index, $field, var_export($value, true), var_export($viaAdvance[$field], true),
                );
            }
        }

        // DIVERGENCE 15 guard (see its banner): an empty charge map covers
        // nothing — the draft is the reference path exactly.
        $viaCharges = computePayslipFromVector($vector['input'], leaveCharges: ['late' => [], 'absence' => [], 'late_days' => 0.0]);
        $leaveChargeGuarded++;
        foreach ($actual as $field => $value) {
            if ($viaCharges[$field] !== $value) {
                $mismatches[] = sprintf(
                    'vector #%d divergence 15 guard %s: reference %s, with an empty charge map %s',
                    $index, $field, var_export($value, true), var_export($viaCharges[$field], true),
                );
            }
        }

        $weeklyInput = $vector['input'];
        $scalar = is_array($weeklyInput['shift'] ?? null)
            ? Shift::fromArray($weeklyInput['shift'])->toArray()
            : Shift::default()->toArray();
        $weeklyInput['shift'] = $scalar;
        $weeklyInput['shift']['weekday_shifts'] = array_fill(0, 7, $scalar);
        foreach (CalendarDays::each(CalendarDays::previous((string) $weeklyInput['periodStart']), CalendarDays::next((string) $weeklyInput['periodEnd'])) as $date) {
            $weeklyInput['shift']['date_shifts'][$date] = $scalar;
        }
        $viaWeekly = computePayslipFromVector($weeklyInput);
        $weeklyGuarded++;
        foreach ($actual as $field => $value) {
            if ($viaWeekly[$field] !== $value) {
                $mismatches[] = sprintf(
                    'vector #%d divergence 8 guard %s: standing shift %s, repeated weekdays %s',
                    $index, $field, var_export($value, true), var_export($viaWeekly[$field], true),
                );
            }
        }

        // DIVERGENCE 7 guard (see its banner): the per-day approval path must
        // reproduce the reference path whenever it approves what the
        // reference's switch paid.
        if ($actual['overtime_hours'] > 0.0) {
            $approvalGuarded++;
            $acknowledged = ($vector['input']['earningsOverride']['overtime_acknowledged'] ?? null) === true;
            $viaApprovals = computePayslipFromVector(
                $vector['input'],
                $acknowledged ? vectorFullOvertimeApprovals($vector['input']) : [],
            );
            $unpaid = $acknowledged ? [] : ['overtime_hours', 'overtime_pay'];
            foreach ($actual as $field => $value) {
                if (! in_array($field, $unpaid, true) && $viaApprovals[$field] !== $value) {
                    $mismatches[] = sprintf(
                        'vector #%d  divergence 7 guard (%s)  %s: reference path %s, approvals path %s',
                        $index,
                        $acknowledged ? 'all approved' : 'none approved',
                        $field,
                        var_export($value, true),
                        var_export($viaApprovals[$field], true),
                    );
                }
            }
        }

        // Whether the engine saw the reference's inputs to the statutory
        // lines: the `gross > 0` gate for the six contributions, and gross,
        // late and undertime for the tax, the total and net. Where a later
        // divergence moved them, those fields are restated below instead.
        $grossGateAgrees = ($actual['gross_pay'] > 0.0) === ((float) $want['gross_pay'] > 0.0);
        $taxInputsAgree = $retiredOvertimePay === 0.0
            && $actual['gross_pay'] === (float) $want['gross_pay']
            && $actual['late_deduction'] === (float) $want['late_deduction']
            && $actual['undertime_deduction'] === (float) $want['undertime_deduction'];

        $uplift = vectorRestDayUplift($vector['input']);
        $usesScheduledBreakSlots = vectorUsesScheduledBreakSlots($vector['input']);
        $usesUnwindowedFixedOvernightShift = vectorUsesUnwindowedFixedOvernightShift($vector['input']);
        $usesFlexibleCrossMidnightCarry = vectorUsesFlexibleCrossMidnightCarry($vector['input']);
        $reclassifiesPaidLeave = vectorReclassifiesPaidLeave($actual);

        /*
         * DIVERGENCE 6. How much of the reference's `work_days_pay` moved onto
         * the Leave line, read back out of the reference's own figure so every
         * assertion below stays stated in the reference's terms. It is the one
         * quantity the reclassification introduces, and it is never negative:
         * the engine only ever carves pay OUT of the work-day bucket.
         */
        $reclassified = $reclassifiesPaidLeave
            ? Money::round((float) $want['work_days_pay'] - $actual['work_days_pay'])
            : 0.0;

        foreach ($want as $field => $expected) {
            if (in_array($field, PAYSLIP_STRING_FIELDS, true)) {
                $ok = $actual[$field] === (string) $expected;
            } elseif (in_array($field, PAYSLIP_BOOL_FIELDS, true)) {
                $ok = $actual[$field] === (bool) $expected;
            } elseif (in_array($field, PAYSLIP_CONTRIBUTIONS, true)) {
                if ($grossGateAgrees) {
                    $strict++;
                    $ok = $actual[$field] === (float) $expected;
                } else {
                    // RESTATED: the reference's own contribution under the
                    // gate the engine saw.
                    $ok = $actual[$field] === vectorContribution(
                        $vector['input']['employee'],
                        $field,
                        (string) $vector['input']['periodEnd'],
                        $actual['gross_pay'] > 0.0,
                    );
                }
            } elseif ($field === 'withholding_tax') {
                if ($taxInputsAgree) {
                    $strict++;
                    $ok = $actual[$field] === (float) $expected;
                } else {
                    // RESTATED: the reference's own tax on the engine's base
                    // (payroll.ts:644-647 over the engine's figures).
                    $ok = $actual[$field] === vectorWithholding(
                        $vector['input']['employee'],
                        max($actual['gross_pay'] - ($actual['sss'] + $actual['philhealth'] + $actual['pagibig'])
                            - $actual['late_deduction'] - $actual['undertime_deduction'], 0.0),
                    );
                }
            } elseif ($retiresFlexibleOvertime && in_array($field, PAYSLIP_FLEXIBLE_OVERTIME_RETIRED, true)) {
                // DIVERGENCE 11: a flexible day computes no overtime, so both
                // overtime lines are exactly zero — pinned, never relaxed.
                $ok = $actual[$field] === 0.0;
            } elseif ($usesScheduledBreakSlots && in_array($field, PAYSLIP_SCHEDULED_BREAK_SLOT_AFFECTED, true)) {
                $ok = array_key_exists($field, $actual);
            } elseif ($usesUnwindowedFixedOvernightShift && in_array($field, PAYSLIP_SCHEDULED_BREAK_SLOT_AFFECTED, true)) {
                $ok = array_key_exists($field, $actual);
            } elseif ($usesFlexibleCrossMidnightCarry && in_array($field, PAYSLIP_SCHEDULED_BREAK_SLOT_AFFECTED, true)) {
                $ok = array_key_exists($field, $actual);
            } elseif ($reclassifiesPaidLeave && in_array($field, PAYSLIP_PAID_LEAVE_RECLASSIFIED, true)) {
                /*
                 * DIVERGENCE 6, pinned exactly rather than relaxed. Each field
                 * must equal the reference's own figure less the pesos that
                 * moved onto the Leave line — and `gross_pay`, which nothing
                 * moved out of, must still equal the reference exactly unless
                 * an operator override replaced the computed leave.
                 */
                if ($field === 'work_days') {
                    // The leave days left this bucket and went nowhere else.
                    $ok = $actual[$field] === (float) $expected - $actual['paid_leave_days'];
                } elseif ($field === 'work_days_pay') {
                    // The anchor $reclassified is derived from this field, so
                    // assert its direction and magnitude rather than itself.
                    $ok = $reclassified >= 0.0 && $reclassified <= (float) $expected;
                } elseif ($field === 'leave_pay') {
                    // A typed override wins and still matches the reference,
                    // which added the same typed figure. Otherwise the Leave
                    // line is exactly what left the work-day bucket.
                    $entered = $vector['input']['earningsOverride']['leave_pay'] ?? null;
                    $ok = (is_int($entered) || is_float($entered))
                        ? $actual[$field] === (float) $expected
                        : $actual[$field] === $reclassified;
                } else {
                    // basic_pay, total_base_pay and gross_pay. Gross loses the
                    // reclassified pesos out of total_base_pay and gains them
                    // back on the Leave line, so it only moves where an
                    // override replaced them.
                    $adjusted = $field === 'gross_pay'
                        ? Money::round((float) $expected - $reclassified
                            + ($actual['leave_pay'] - (float) $want['leave_pay']))
                        : Money::round((float) $expected - $reclassified);

                    if ($uplift > 1.0) {
                        // The second divergence applies to this field too, so
                        // reclassify first and then bound exactly as it does.
                        $bound = $adjusted * $uplift;
                        $slack = 0.005 * $uplift + 0.005;

                        $ok = $actual[$field] >= min($adjusted, $bound) - $slack
                            && $actual[$field] <= max($adjusted, $bound) + $slack;
                    } else {
                        $ok = $actual[$field] === $adjusted;
                    }
                }
            } elseif ($uplift > 1.0 && in_array($field, PAYSLIP_COMPOUNDABLE, true)) {
                // Never less than the reference, never more than the uplift.
                // A cent of slack: these are sums of independently rounded
                // components, so the upper bound is not exact to the centavo.
                // min/max rather than lo/hi: a handful of vectors carry a
                // NEGATIVE monthly rate, and multiplying those by the uplift
                // moves the bound downward, not upward.
                $bound = (float) $expected * $uplift;

                // The reference figure is already rounded to the centavo, so
                // multiplying it by the uplift carries that half-centavo up
                // with it; the engine's own result is rounded once more.
                $slack = 0.005 * $uplift + 0.005;

                $ok = $actual[$field] >= min((float) $expected, $bound) - $slack
                    && $actual[$field] <= max((float) $expected, $bound) + $slack;
            } elseif ($retiredOvertimePay > 0.0 && $field === 'gross_pay') {
                // DIVERGENCE 11: the reference's own gross less exactly the
                // overtime line its switch had paid (restated above). Not
                // counted as strict: it is not the corpus's literal figure.
                $ok = $actual[$field] === (float) $expected;
            } elseif ($field === 'total_deductions') {
                if ($taxInputsAgree) {
                    $strict++;
                    $ok = $actual[$field] === (float) $expected;
                } else {
                    // RESTATED: the seven reference lines, each checked on its
                    // own above, plus the zero named aggregate in stable order.
                    $sum = 0.0;
                    foreach (PayslipDraft::DEDUCTION_KEYS as $key) {
                        $sum += $actual[$key];
                    }
                    $ok = $actual[$field] === Money::round($sum);
                }
            } elseif ($field === 'net_pay') {
                if ($taxInputsAgree) {
                    $strict++;
                    $ok = $actual[$field] === (float) $expected;
                } else {
                    $ok = $actual[$field] === Money::round($actual['gross_pay'] - $actual['total_deductions']);
                }
            } else {
                // EVERY other numeric field of the draft is still the literal
                // contract with the TypeScript engine — day counts, every
                // earning bucket, gross, late and undertime.
                $strict++;
                $ok = $actual[$field] === (float) $expected;
            }

            if (! $ok) {
                $mismatches[] = sprintf(
                    'vector #%d  %s..%s  rate %s  %s: expected %s, got %s',
                    $index,
                    (string) $vector['input']['periodStart'],
                    (string) $vector['input']['periodEnd'],
                    var_export($vector['input']['employee']['monthly_rate'] ?? null, true),
                    $field,
                    var_export($expected, true),
                    var_export($actual[$field], true),
                );
            }
        }
    }
    fclose($handle);

    expect($count)->toBeGreaterThanOrEqual(5_000, 'Vector corpus too small to be meaningful.');

    // The floor is the count observed on a green run: 174,436 field comparisons
    // across the 6,921 vectors. The seven statutory lines, the total and net
    // pay are compared strictly wherever the engine saw the reference's
    // inputs (the first divergence is RETIRED). If it drops, a new or widened
    // divergence predicate is claiming fields otherwise compared strictly —
    // which is allowed, but must be a deliberate change to this number, not a
    // side effect. Raise it when a divergence is retired.
    //
    // The sixth divergence keeps 657 comparisons out of this counter, on the
    // 193 vectors that reclassify a paid leave day. Those fields are NOT
    // unchecked: unlike divergences 3-5, the sixth pins every one of them
    // exactly, in the reference's own figures. This counter only tracks
    // literal `===` against the corpus.
    //
    // The eleventh keeps 214 out: the overtime lines of the flexible vectors
    // the reference computed overtime on, and the gross, tax, total and net of
    // the 56 whose switch had paid it — counting only the comparisons the
    // fifth divergence does not already hold to existence. All 214 are
    // restated exactly; none is unchecked.
    // The seventh divergence's guard must actually run: GUARDED_FLOOR is the
    // observed number of vectors with overtime.
    expect($approvalGuarded)->toBeGreaterThanOrEqual(PAYSLIP_OVERTIME_GUARDED_FLOOR, 'The divergence 7 guard saw fewer vectors with overtime than it did when pinned.');
    expect($leaveCancellationGuarded)->toBeGreaterThanOrEqual(PAYSLIP_LEAVE_CANCELLATION_GUARDED_FLOOR, 'The divergence 12 guard must cover approved leave overlapping the period.');
    expect($payRulesGuarded)->toBeGreaterThanOrEqual(PAYSLIP_PAY_RULES_GUARDED_FLOOR, 'The divergence 13 guard must exercise the entire reference corpus.');
    expect($advanceGuarded)->toBeGreaterThanOrEqual(PAYSLIP_ADVANCE_GUARDED_FLOOR, 'The divergence 14 guard must exercise the entire reference corpus.');
    expect($leaveChargeGuarded)->toBeGreaterThanOrEqual(PAYSLIP_LEAVE_CHARGE_GUARDED_FLOOR, 'The divergence 15 guard must exercise the entire reference corpus.');
    expect($weeklyGuarded)->toBeGreaterThanOrEqual(PAYSLIP_WEEKLY_GUARDED_FLOOR, 'The divergence 8 guard must exercise the entire reference corpus.');
    expect($customGuarded)->toBeGreaterThanOrEqual(PAYSLIP_CUSTOM_DEDUCTION_GUARDED_FLOOR, 'The divergence 9 guard must exercise the entire reference corpus.');
    expect($dailyGuarded)->toBeGreaterThanOrEqual(PAYSLIP_DAILY_RATE_GUARDED_FLOOR, 'The divergence 10 guard saw fewer vectors with a monthly rate than it did when pinned.');
    expect($flexibleRetired)->toBeGreaterThanOrEqual(PAYSLIP_FLEXIBLE_OVERTIME_RETIRED_FLOOR, 'The divergence 11 restatement saw fewer flexible vectors with reference overtime than it did when pinned.');

    expect($strict)->toBeGreaterThanOrEqual(
        174_436,
        'Fewer payslip fields are compared strictly than the pinned floor — a divergence widened.',
    );

    if ($mismatches !== []) {
        $shown = array_slice($mismatches, 0, 10);
        $more = count($mismatches) - count($shown);

        $this->fail(sprintf(
            "%d field mismatches across %d vectors.\n%s%s",
            count($mismatches),
            $count,
            implode("\n", $shown),
            $more > 0 ? "\n... and {$more} more" : '',
        ));
    }

    expect($mismatches)->toBe([]);
});
