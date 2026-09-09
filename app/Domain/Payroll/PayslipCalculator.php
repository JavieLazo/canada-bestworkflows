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
     * lands on a rest day is now compounded rather than approximated — the
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
     * @param  float  $bonus  Always 0 from calculatePayrollDrafts today.
     * @param  float|null  $allowancesOverride  The manager-entered allowance for this run.
     *                                          Null is "not provided", which is 0.
     * @param  array<string, mixed>|null  $earningsOverride  Keys: night_differential,
     *                                                       leave_pay, other_earnings, overtime_acknowledged.
     * @param  array{formulas?: list<mixed>, holidays?: list<HolidaySetting>}|null  $settings
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
    ): PayslipDraft {
        $r = self::rates(is_array($settings['formulas'] ?? null) ? $settings['formulas'] : []);

        /** @var list<HolidaySetting> $holidays */
        $holidays = is_array($settings['holidays'] ?? null) ? $settings['holidays'] : [];

        /*
         * The employment window clips the DTR range. Both comparisons are plain
         * lexicographic comparisons on 'Y-m-d', exactly as the reference does
         * them — strcmp() rather than PHP's `>` so a numeric-looking value can
         * never trip PHP's string-to-number comparison rules.
         *
         * A missing date_hired reproduces JS `undefined > "2025-01-01"` ===
         * false, which selects periodStart; a null-or-empty date_resigned is
         * falsy in the reference and selects periodEnd.
         */
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

        /** @var DtrSummary $s */
        $s = DtrCalculator::summarize($days);

        $monthly = self::num($employee->monthly_rate ?? null);

        /*
         * payroll.ts:415 calls workWeek(shift) inline for the divisor and again
         * at :444 for the day-bucket loop. workWeek is pure, so the two values
         * are identical; it is resolved once here.
         */
        $week = ShiftResolver::workWeek($shift);

        /*
         * The monthly rate spread over the days this employee is actually
         * scheduled to work that month, so a full cut-off always pays the
         * monthly rate pro rata however the calendar falls. A fixed divisor
         * cannot: months hold 20 to 23 weekdays, so perfect attendance would
         * pay a different amount each one.
         *
         * The daily rate therefore moves a little month to month, which is the
         * trade — and it is why `hourly_rate` exists as an override for anyone
         * whose rate must not move.
         *
         * Formulas' `days_per_month` is deliberately NOT used here. It is a
         * parameter of the SIL formula that this calculation used to borrow,
         * and borrowing it is what made a full cut-off pay the wrong amount
         * whenever the month held other than 24 working days. PayrollRates
         * still exposes it; this must not start reading it.
         *
         * `workWeek` never returns an empty week, so a month always holds at
         * least four scheduled days and this never divides by zero. Ported
         * without a guard, exactly as the reference has none — note only that
         * where JS would yield Infinity, PHP raises DivisionByZeroError. The
         * reference's own invariant proves the branch unreachable, and raising
         * is the safer of the two behaviours if that proof ever breaks.
         */
        /*
         * ONE RATE PER CALENDAR MONTH, NOT ONE PER PERIOD.
         *
         * A period may now span months (PayrollPeriod::cutoffError no longer
         * refuses it), and the divisor above is a property of a month, not of
         * a payroll run. Resolving it once from the start date would price an
         * October day at September's rate — a 22-working-day month against a
         * 23-working-day one is a 4.5% error on every day past the boundary,
         * silently.
         *
         * So each day is priced by its OWN month, and everything below that
         * counts days counts them per month. Memoised on 'YYYY-MM': a period
         * touches one or two months, and long ones touch few.
         *
         * FOR A PERIOD INSIDE ONE MONTH EVERY FORMULA BELOW REDUCES TO EXACTLY
         * WHAT IT WAS — one month in the map, one term in each sum, the same
         * multiplication. That is what the 6,921 golden vectors, all of them
         * single-month, are checking: this reorganises the arithmetic without
         * moving a centavo of it.
         */
        $dailyRates = [];
        $dailyFor = static function (string $date) use (&$dailyRates, $monthly, $week): float {
            $key = substr($date, 0, 7);

            if (! array_key_exists($key, $dailyRates)) {
                $range = PayrollPeriod::calendarMonthRange($date);
                $dailyRates[$key] = $monthly
                    / ShiftResolver::scheduledWorkDays($range['from'], $range['to'], $week);
            }

            return $dailyRates[$key];
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
        $dailyMinutes = ShiftResolver::paidShiftMinutes($shift);
        $dailyHours = $dailyMinutes > 0 ? $dailyMinutes / 60 : self::DEFAULT_WORKING_HOURS;

        // The stored hourly rate may be an intentional override, but it is
        // rounded to cents. Do not multiply that rounded value back into the
        // daily rate.
        $storedHourly = self::num($employee->hourly_rate ?? null);
        $hourlyFor = static fn (string $date): float => $storedHourly > 0
            ? $storedHourly
            : $dailyFor($date) / $dailyHours;

        // The override does not move month to month, which is the point of it.
        $hourly = $hourlyFor($periodStart);

        /*
         * The night differential is measured off the *scheduled* shift, not the
         * punches: a night-shift employee earns it for the night hours their
         * shift covers on any day they turned up. It is the same figure every
         * night, so it is computed once and multiplied by the nights worked.
         *
         * Capped by the paid day as well as by the configured maximum: the
         * unpaid break can sit inside the night window, and no differential is
         * owed on an hour that is not paid in the first place. A shift that is
         * night from end to end therefore earns exactly `nsdRate` of a daily
         * rate, whatever its length.
         */
        $nightHours = min(ShiftResolver::nightShiftMinutes($shift) / 60, $r->maxNsdHours, $dailyHours);
        $nsdPerNight = static fn (float $dayRate): float => ($dayRate / $dailyHours) * $r->nsdRate * $nightHours;

        /*
         * Every paid day falls into exactly one bucket, so no day is priced
         * twice. A worked holiday is paid from its own `percent` — the whole
         * day's worth, not a premium stacked on an ordinary day — and skips the
         * work-day bucket. A holiday nobody worked earns nothing.
         *
         * Approved paid leave is a paid day even though it was never punched,
         * and a half day counts as half. Someone who came in anyway is already
         * counted as a worked day and carries `excuseDays` 0, so the two cannot
         * stack.
         */
        $workedDays = 0.0;
        $workedDaysByMonth = [];
        $nightsByMonth = [];
        $holidayDays = 0;
        $holidayEarnings = 0.0;
        $restDays = 0;
        $restDayEarnings = 0.0;
        $nightsWorked = 0;

        foreach ($days as $day) {
            $holiday = HolidaySetting::on($day->date, $holidays);
            $dayMonth = substr($day->date, 0, 7);
            $dayDaily = $dailyFor($day->date);

            if ($day->workedMinutes > 0) {
                $nightsWorked += 1;
                $nightsByMonth[$dayMonth] = ($nightsByMonth[$dayMonth] ?? 0) + 1;

                // A worked holiday outranks a rest day: it is priced from its
                // own percent, and pricing it twice is exactly what these
                // buckets prevent.
                if ($holiday !== null) {
                    $holidayDays += 1;
                    /*
                     * A holiday that lands on a rest day is BOTH, and is now
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
                     * It stays ONE bucket. The rest-day bucket is still not
                     * also credited: that would pay the day twice over, at 330%
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
                     * The two are not the same double — rewriting it moved a
                     * centavo on holiday_pay for a 123,456.78 monthly rate —
                     * and the uplift is applied as a separate factor after it.
                     * Multiplying by exactly 1.0 is exact in IEEE 754, so a day
                     * that does not compound is bit-identical to before.
                     */
                    $dayHolidayPay = ($dayDaily * self::num($holiday->percent)) / 100;

                    if (! ShiftResolver::worksOn($day->date, $week)) {
                        $dayHolidayPay *= max(1.0, $r->restDayRate);
                    }

                    $holidayEarnings += $dayHolidayPay;
                } elseif (! ShiftResolver::worksOn($day->date, $week)) {
                    $restDays += 1;
                    /*
                     * Priced by the hour, so half a rest day pays half. The cap
                     * is what keeps a long rest day from being paid twice:
                     * hours beyond it are already overtime, and overtime on a
                     * rest day carries the same premium through `$dayRate`.
                     */
                    $restHours = min($day->workedMinutes / 60, $r->maxRestDayHours);
                    $restDayEarnings += ($dayDaily / $dailyHours) * $r->restDayRate * $restHours;
                } else {
                    $workedDays += 1;
                    $workedDaysByMonth[$dayMonth] = ($workedDaysByMonth[$dayMonth] ?? 0.0) + 1;
                }

                continue;
            }

            if ($day->excusePaid === true) {
                $workedDays += $day->excuseDays;
                $workedDaysByMonth[$dayMonth] = ($workedDaysByMonth[$dayMonth] ?? 0.0) + $day->excuseDays;
            }
        }

        $work_days = Money::round($workedDays);                                   // [R01] payroll.ts:493
        $holiday_days = (float) $holidayDays;
        $rest_days = (float) $restDays;

        /*
         * QUIRK, preserved: work_days_pay multiplies the ROUNDED `work_days` by
         * the UNROUNDED daily rate — payroll.ts:497 reads `work_days * daily`,
         * not `workedDays * daily`. The two differ wherever a half-day of paid
         * leave leaves `workedDays` with more than two decimals.
         */
        /*
         * Summed per month, and the QUIRK above is preserved per month too:
         * each month's day count is rounded before it is priced, exactly as
         * the single total used to be. With one month in the map this is
         * `Money::round(Money::round($workedDays) * $daily)`, character for
         * character what payroll.ts:497 computes.
         */
        $workDaysEarnings = 0.0;
        foreach ($workedDaysByMonth as $ym => $count) {
            $workDaysEarnings += Money::round($count) * $dailyFor($ym.'-01');
        }

        $work_days_pay = Money::round($workDaysEarnings);                         // [R02] payroll.ts:497
        $holiday_pay = Money::round($holidayEarnings);                            // [R03] payroll.ts:498
        $rest_day_pay = Money::round($restDayEarnings);                           // [R04] payroll.ts:499
        $basic_pay = Money::round($work_days_pay + $holiday_pay + $rest_day_pay); // [R05] payroll.ts:500

        // An open TIME_IN has no defensible duration and cannot silently become
        // a fully paid day. HR can resolve the attendance record before
        // finalization.
        $incompleteDays = 0;
        foreach ($days as $day) {
            if ($day->status === DayRecord::INCOMPLETE && ShiftResolver::worksOn($day->date, $week)) {
                $incompleteDays += 1;
            }
        }

        // Reported on the payslip, but costing nothing on their own: an
        // unworked day simply earns none of the buckets above.
        $absence_days = (float) ($s->absences + $incompleteDays);
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
        $night_differential = self::isNumberValue($enteredNsd)
            ? self::entered((float) $enteredNsd)                                  // [R07] payroll.ts:514
            : Money::round(array_sum(array_map(                                   // [R08] payroll.ts:528
                static fn (string $ym): float => $nsdPerNight($dailyFor($ym.'-01')) * $nightsByMonth[$ym],
                array_keys($nightsByMonth),
            )));

        $leave_pay = self::entered(self::numberOrNull($earningsOverride['leave_pay'] ?? null));
        $other_earnings = self::entered(self::numberOrNull($earningsOverride['other_earnings'] ?? null));

        /*
         * KNOWN GAP, ported faithfully rather than fixed. The reference
         * hard-wires this to 0 with the comment "13th-month pay is processed
         * separately and is not part of a regular cut-off" — separately nowhere.
         * PH law requires 13th-month pay by 24 December; there is no module,
         * screen, computation or phase for it anywhere in the port plan, and the
         * statutory tables refuse any cut-off starting after 2026-12-31.
         * See docs/03-OPEN-ISSUES.md. Kept at 0 so the two engines reconcile to
         * the centavo. Escalated, not fixed here.
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

        foreach ($days as $day) {
            $minutes = min($day->overtimeMinutes, Minutes::round($r->maxOtHours * 60));
            if ($minutes <= 0) {
                continue;
            }

            $holiday = HolidaySetting::on($day->date, $holidays);

            // A worked holiday pays its own rate and then 130% of it; a rest
            // day pays its premium and then the ordinary overtime rate on top.
            // A holiday that falls on a rest day is priced as the holiday,
            // matching the day bucket.
            $onWorkDay = ShiftResolver::worksOn($day->date, $week);

            if ($holiday !== null) {
                $dayRate = (self::num($holiday->percent) / 100) * self::HOLIDAY_OT_PREMIUM;

                // Overtime is priced off the day's own rate, so if the day
                // compounded above, its overtime compounds with it — by the
                // same floored factor, for the same reason.
                if (! $onWorkDay) {
                    $dayRate *= max(1.0, $r->restDayRate);
                }
            } else {
                $dayRate = $onWorkDay ? $r->otRate : $r->restDayRate * $r->otRate;
            }

            $overtimeMinutes += $minutes;
            $overtimeEarnings += ($minutes / 60) * $hourlyFor($day->date) * $dayRate;
        }

        $overtime_hours = Money::round($overtimeMinutes / 60);                    // [R09] payroll.ts:556
        $overtime_pay = Money::round($overtimeEarnings);                          // [R10] payroll.ts:557
        $overtime_acknowledged = ($earningsOverride['overtime_acknowledged'] ?? null) === true;

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

        // An incomplete punch is treated as an absence. Do not also charge the
        // late minutes from its unmatched TIME_IN.
        $lateByMonth = [];
        $undertimeByMonth = [];
        foreach ($days as $day) {
            if ($day->status !== DayRecord::PRESENT && $day->status !== DayRecord::LATE) {
                continue;
            }
            $ym = substr($day->date, 0, 7);
            $lateByMonth[$ym] = ($lateByMonth[$ym] ?? 0.0) + $day->lateMinutes;
            $undertimeByMonth[$ym] = ($undertimeByMonth[$ym] ?? 0.0) + $day->undertimeMinutes;
        }

        // The payslip still reports one figure for each. With a single month in
        // the map this is the same addition, in the same order, as before.
        $lateMinutes = array_sum($lateByMonth);
        $undertimeMinutes = array_sum($undertimeByMonth);

        /*
         * The minutes are summed per month and THEN priced, rather than each
         * day being priced and the amounts summed. Both are correct; only the
         * first is bit-identical to `(Σ minutes / 60) * $hourly` when the
         * period is in one month, and float addition is not associative enough
         * for "both are correct" to survive a half-centavo boundary.
         */
        $priceMinutes = static function (array $byMonth) use ($hourlyFor): float {
            $total = 0.0;
            foreach ($byMonth as $ym => $minutes) {
                $total += ($minutes / 60) * $hourlyFor($ym.'-01');
            }

            return $total;
        };

        $late_deduction = Money::round($priceMinutes($lateByMonth));              // [R13] payroll.ts:580
        $undertime_deduction = Money::round($priceMinutes($undertimeByMonth));    // [R14] payroll.ts:581

        /*
         * ─────────────────────────────────────────────────────────────────────
         * CONTRIBUTIONS AND TAX ARE NOT COMPUTED. THEY ARE TYPED.
         * ─────────────────────────────────────────────────────────────────────
         * SSS, PhilHealth, Pag-IBIG, withholding tax and other deductions are
         * entered by hand, per employee, in the run workspace — five editable
         * cells per row for as long as the run is a draft
         * (PayrollData::DEDUCTION_LABELS, run-workspace.blade.php). The figures
         * that go on a payslip are the ones the agencies actually assessed, and
         * the payroll manager has them. So the engine no longer guesses.
         *
         * It seeds every one of them at zero. This is not a stand-in for a
         * computation that should be here: a wrong pre-filled figure is worse
         * than an empty one, because an empty cell asks to be filled and a
         * populated one asks to be trusted. The reference engine pre-filled
         * half a monthly contribution on every payslip, which was right only
         * while a period could only ever be half a month; once any range of
         * days became payable (see PayrollPeriod::cutoffError) a two-day run
         * would have arrived carrying a fortnight of SSS.
         *
         * THE EMPLOYER SHARES GO WITH THEM. `sss_er`, `philhealth_er` and
         * `pagibig_er` are reported on the payslip and never enter
         * total_deductions, and they are not editable anywhere — so they now
         * report zero on every payslip. Computing an employer share from the
         * statutory table beside an employee share somebody typed would state a
         * pairing that is not true.
         *
         * WHAT THIS LEAVES INERT: the employee's allow_sss / allow_philhealth /
         * allow_pagibig / allow_withholding_tax switches, the six fixed_*_ee
         * and fixed_*_er overrides, the three *_mode selects, and annualize_tax
         * / fixed_tax / percent_tax. Every one of them is still stored and
         * still editable in Employee Records; none of them reaches a payslip.
         *
         * Statutory keeps its tables — SSS, PhilHealth, Pag-IBIG, the BIR
         * brackets — and GoldenVectorTest keeps proving them against the
         * reference. Nothing on this path reads them any more.
         *
         * Late and undertime above are NOT part of this. They are computed from
         * the DTR, they are read-only in the workspace, and they stay.
         */
        $rawSss = 0.0;
        $sss_er = 0.0;
        $rawPhilhealth = 0.0;
        $philhealth_er = 0.0;
        $rawPagibig = 0.0;
        $pagibig_er = 0.0;
        $rawWithholdingTax = 0.0;

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
        );
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
     * The only collaborator in this file whose contract is not yet fixed by a
     * written class. It must expose, as public readonly floats:
     *
     *     otRate  maxOtHours  daysPerMonth  nsdRate  maxNsdHours
     *     restDayRate  maxRestDayHours
     *
     * resolved from the Formulas rows OT.percentage, OT.max_ot,
     * SIL.days_per_month, NSD.percentage, NSD.max_nsd, REST_DAY.percentage and
     * REST_DAY.max_hours, with PAYROLL_DEFAULTS (1.25, 8, 24, 0.1, 8, 1.3, 8)
     * as fallbacks. Divisors fall back unless finite AND > 0; rates and caps
     * fall back unless finite AND >= 0, because zero is a legitimate cap.
     *
     * Kept behind this one seam so a naming change costs one line.
     *
     * @param  list<mixed>  $formulas
     */
    private static function rates(array $formulas): PayrollRates
    {
        return PayrollRates::fromFormulas($formulas);
    }
}
