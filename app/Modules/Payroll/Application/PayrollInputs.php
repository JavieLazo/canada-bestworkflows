<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Domain\Payroll\PayrollPeriod;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\PayRulesTimeline;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Modules\Payroll\Domain\Adjustments\AdjustmentRule;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\Benefits\LeaveCashOutValue;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
use App\Modules\Timekeeping\Contracts\LeaveCashOutSource;
use App\Modules\Workforce\Contracts\EmployeePayData;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The detached inputs used by one computation, serializable without rereading source records.
 *
 * Schema 2 adds the sixteen statutory switches and overrides of each
 * employee (EmployeePayData), because the engine computes SSS,
 * PhilHealth, Pag-IBIG and withholding from them again, and each employee's
 * per-day overtime decisions from the Daily Time Record
 * (attendance[id].overtime_approvals), because only approved minutes are paid,
 * and each employee's incentive and bonus lines (`incentives`: the type's id,
 * code, name and taxable flag AS THEY WERE, with the amount) together with
 * the tax-exempt benefits already posted that calendar year
 * (`exempt_benefits_ytd`, from ExemptBenefitLedger, for the employees with a
 * tax-exempt line), because the PHP 90,000 ceiling splits those lines. Schema-1
 * evidence is refused by restore(), and is refused for replay anyway by its
 * recorded calculation version. Schema 3 captures the entire weekday and
 * date-exception shift maps: a posted Monday night never becomes Tuesday's current day shift when
 * its captured facts are replayed. Older schemas retain their stored
 * results; they are never reinterpreted under this calculation version.
 * Schema 4 captures named Payroll Deductions separately from the typed Other
 * amount, so a catalog rename cannot change a reviewed or posted calculation.
 * Schema 5 adds each employee's `daily_rate` (Rate Per Day), which prices
 * every day when it is above zero: a rate entered or cleared after a
 * preview stales it like any other input.
 * Schema 6 adds `active_calculations`: which computed deductions the
 * Payroll Deductions list switched ON when the inputs were captured
 * (`DeductionCalculation`). Deleting SSS from the list switches the SSS
 * deduction off on every draft, so the fingerprint must move, and a
 * replay must never read the live list.
 * Schema 7 captures each leave request's cancelled dates. Schema 6 retains
 * its original shape and fingerprint, with no cancelled dates inferred.
 * Schema 8 captures the Contribution & Tax Table versions that price the
 * period (`statutory_tables`: kind => version id, dates and the figures
 * themselves), so a new version stales the preview and a replay never reads
 * the live tables. Schema 6 and 7 evidence restores with no tables: the
 * engine's built-in figures, which are what those runs computed from.
 * Schema 10 captures each member's recurring lines live on the period
 * (`recurring_lines`: the line, its catalog row's code and name AS THEY
 * WERE, its figures and what was already paid against it, from
 * RecurringLineLedger) and the ones skipped on this payroll
 * (`skipped_recurring_lines`), because they propose lines and trim a
 * loan's last payment. Older evidence restores with none.
 * Schema 11 captures the advance payroll (DELIBERATE DIVERGENCE 14):
 * `attendance_through`, the last day whose attendance the run reads (null:
 * the whole period), with the punches and overtime decisions in
 * `attendance` already stopping there; and `settlements`, each member's
 * presumed days on earlier posted payslips priced again here
 * (SettlementInputs: the advance payroll's employee, Formulas, holidays and
 * rates, the schedule and leaves it presumed from, and the days' attendance
 * as it stands). Older evidence restores with neither.
 * Schema 12 captures the leave-credit charges (DELIBERATE DIVERGENCE 15):
 * `leave_charges`, each member's entry from Timekeeping's LeaveChargeSource —
 * the policy's charge rules, the conversion table in use, each leave
 * type's balance and floor as of the capture, the credits year and the
 * dates an earlier payslip charged — so the plan is made from the evidence
 * and a changed balance stales the preview. Older evidence restores with
 * none, and charges nothing. A balance of a leave type with a waiting period,
 * or one not everyone may use, also carries `available_from`, the first day
 * it is the employee's (acquired, or eligible); a balance without the key is
 * charged on every day, as all evidence before it reads.
 * Schema 13 settles from frozen figures: each settlement carries
 * `presumed_lines`, the presumed days' lines as the ADVANCE payroll's own
 * evidence froze them when it posted (null for a payslip posted before
 * they were recorded, which is priced again from the capture), and
 * `resigned`, the employee's leaving date as it stands, applied to the
 * actual side alone while `employee` stays the advance payroll's copy.
 * Schema 11 and 12 settlements restore with neither.
 * Schema 14 captures the leave credit cash-outs a run pays:
 * `leave_cash_outs`, each member's approved and unpaid requests
 * (Timekeeping's LeaveCashOutSource: id, token, the type's name, days), and
 * `cash_out_type`, the Incentives & Bonuses type they are paid as (id,
 * code, name, tax category, 13th-month flag) or null when none is marked —
 * both in the fingerprint, so an approval, a payment elsewhere or a catalog
 * change stales the preview and a replay never reads the live tables.
 * Older evidence restores with none, and pays none.
 * Schema 15 captures each member's Contributions & tax version in force
 * on the period's first day (`gov_policies`: employee id => version id,
 * date and the figures — the sixteen switches already laid over the
 * employee record, and the rates of their own on percentage deductions,
 * which price those lines), so a newly approved version stales the
 * preview and a replay never reads the live table. A member with no
 * version is absent: their employee record carries the baseline. Older
 * evidence restores with none.
 * Schema 16 captures the approved adjustments this payroll pays
 * (`adjustments`: employee id => the lines, each the category copied —
 * name, kind, tax — with the amount and the reason), so a newly approved
 * line stales the preview and a replay never reads the ledger. Older
 * evidence restores with none, and pays none.
 * Schema 17 captures each member's overtime CATEGORIES beside the approved
 * minutes (`attendance[id].overtime_categories`: date => SEAPORT for a day
 * approved as seaport overtime, which payroll never caps and prices at the
 * version's seaport rate — divergence 16). Older evidence restores with
 * none: every day ordinary overtime.
 * Schema 18 captures, on each member's `pay_rules` entry, the versions
 * that take over INSIDE the period (`segments`: from, scope, version id,
 * date, figures — divergence 17), so the engine prices each day by its own
 * version and a replay never reads the live table. Older evidence has one
 * version for the whole period, which is what it was priced under.
 * Schema 19 captures, on each member's `employees` entry, the salary
 * versions taking over INSIDE the period (`salary_rates`: from, monthly
 * and daily rate — divergence 18); `monthly_rate` / `daily_rate` are the
 * salary in force on the first day. Older evidence has one salary for the
 * whole period, which is what it was priced under.
 * Schema 20 captures the CONTRIBUTION MONTH the payroll's shares count
 * toward (`contribution_month`, `YYYY-MM`, null = the period's start
 * month) and what that month's posted payslips already took of each
 * member's six shares (`contribution_posted` — divergence 19), so a
 * payslip posted meanwhile stales the preview and a replay never reads the
 * live table. Older evidence restores with nothing posted.
 * Schema 21 captures the yearly TAX-FREE CEILING version in force on the
 * period's first day (`benefit_ceiling`, null = the constant the engine
 * carried) and each member's approved BENEFIT ASSIGNMENTS reaching the
 * period (`benefit_assignments`: id, type, span, own amount), so a new
 * ceiling or an approval stales the preview and a replay never reads the
 * live tables. Older evidence restores with the constant and none.
 * Schema 24 captures what a converted day of leave credits pays: the
 * paying type's `day_value` (FACTOR or DAILY) and `factor`
 * (LeaveCashOutValue) on `cash_out_type`, and on each `leave_cash_outs`
 * request its `purpose` (MONETIZATION or TERMINAL) and, for terminal
 * leave, the `salary` it is priced from (the highest on record: from,
 * monthly and daily rate; null otherwise). Older evidence restores as it
 * was priced: every request a monetization at the daily rate.
 */
final readonly class PayrollInputs
{
    public const int SCHEMA_VERSION = 24;

    /**
     * @param  list<string>  $employeeIds
     * @param  list<EmployeePayData>  $employees
     * @param  array<string, AttendanceInputs>  $attendance
     * @param  list<array<string, mixed>>  $formulas
     * @param  list<HolidaySetting>  $holidays
     * @param  array<string, array<string, mixed>>  $deductions
     * @param  array<string, mixed>  $allowances
     * @param  array<string, array<string, mixed>>  $earnings
     * @param  array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool}>>  $incentives  the typed lines; a scheduled benefit's automatic line is computed from `benefitRules`
     * @param  array<string, float>  $exemptBenefitsYearToDate
     * @param  array<string, list<array{type_id: string, code: string, name: string, amount: float}>>  $customDeductions
     * @param  list<string>  $activeCalculations  the computed deductions switched on, in DeductionCalculation::all() order
     * @param  array<string, array{version_id: ?string, effective_from: ?string, covered_through: ?string, figures: array<string, mixed>}>  $statutoryTables  the four table versions in force, keyed by kind (empty: the built-in figures)
     * @param  array<string, array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>, segments?: list<array{from: string, scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}>}>  $payRules  the Pay Basis & Rates version pricing each member on the first day, keyed by employee id (absent: the reference's rules over `formulas`), with the versions taking over inside the period (schema 18)
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $benefitRules  the catalog's scheduled benefits as captured, keyed by type id — what payroll proposes on its own
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $deductionRules  the Payroll Deductions rows priced as a percentage, as captured, keyed by row id
     * @param  array<string, list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>>  $recurringLines  each member's recurring lines live on the period and not paid off — a paid-off deduction only when the draft typed an amount on its row — as captured
     * @param  array<string, list<string>>  $skippedRecurringLines  each member's recurring lines skipped on this payroll
     * @param  string|null  $attendanceThrough  the last day whose attendance this payroll reads; null for the whole period
     * @param  array<string, list<SettlementInputs>>  $settlements  each member's earlier presumed days settled on this payroll
     * @param  array<string, array<string, mixed>>  $leaveCharges  each member's leave-credit charge facts (LeaveChargeSource); absent for a member whose policy charges nothing
     * @param  array<string, list<array{id: string, request_type: string, name: string, days: float, purpose: string, salary: array{from: ?string, monthly_rate: ?float, daily_rate: ?float}|null}>>  $leaveCashOuts  each member's approved, unpaid leave credit cash-outs (LeaveCashOutSource), a terminal one with the salary it is priced from
     * @param  array{type_id: string, code: string, name: string, is_taxable: bool, tax: string, counts_toward_13th: bool, day_value: string, factor: ?float}|null  $cashOutType  the Incentives & Bonuses type cash-outs are paid as, with what a converted day pays on it, as captured; null when none is marked
     * @param  array<string, array{version_id: string, effective_from: string, figures: array<string, mixed>}>  $govPolicies  each member's Contributions & tax version in force, as captured (GovDeductionSettings figures); absent for a member on their baseline
     * @param  array<string, list<array{id: string, category_id: string, code: string, name: string, kind: string, tax: ?string, amount: float, reason: string}>>  $adjustments  the approved adjustment lines this payroll pays, by member, as captured (Adjustments::entry)
     * @param  string|null  $contributionMonth  the month ('YYYY-MM') the shares count toward; null = the period's start month
     * @param  array<string, array<string, float>>  $contributionPosted  each member's shares already posted this contribution month (ContributionMonthToDate::LINES); absent when none
     * @param  array{version_id: string, effective_from: string, ceiling: float}|null  $benefitCeiling  the yearly tax-free ceiling version in force, as captured; null = the constant
     * @param  array<string, list<array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float}>>  $benefitAssignments  each member's approved benefit assignments reaching the period (BenefitAssignments::entry); absent when none
     * @param  array{year: int, payrolls_left: int, posted: array<string, array{taxable: float, withheld: float}>}|null  $taxYear  the tax year, the regular payrolls left in it after this one and each whole-year member's posted figures (TaxYearToDate::entry); null when no member is priced by the whole-year estimate
     */
    public function __construct(
        public array $employeeIds,
        public string $periodStart,
        public string $periodEnd,
        public array $employees,
        public array $attendance,
        public array $formulas,
        public array $holidays,
        public array $deductions,
        public array $allowances,
        public array $earnings,
        public string $calculatorVersion,
        public array $incentives = [],
        public array $exemptBenefitsYearToDate = [],
        public array $customDeductions = [],
        public array $activeCalculations = [],
        public array $statutoryTables = [],
        public array $payRules = [],
        public array $benefitRules = [],
        public array $deductionRules = [],
        public array $recurringLines = [],
        public array $skippedRecurringLines = [],
        public ?string $attendanceThrough = null,
        public array $settlements = [],
        public array $leaveCharges = [],
        public array $leaveCashOuts = [],
        public ?array $cashOutType = null,
        public array $govPolicies = [],
        public array $adjustments = [],
        public ?string $contributionMonth = null,
        public array $contributionPosted = [],
        public ?array $benefitCeiling = null,
        public array $benefitAssignments = [],
        public ?array $taxYear = null,
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** The pesos of exempt benefits the year may hold tax-free: the captured version, or the constant. */
    public function ceiling(): float
    {
        return BenefitCeilings::ceilingOf($this->benefitCeiling);
    }

    /** The month the shares count toward: the captured one, else the period's start month. */
    public function contributionMonth(): string
    {
        return $this->contributionMonth ?? substr($this->periodStart, 0, 7);
    }

    /** The last day priced from attendance: the counted-through date, else the period's end. */
    public function attendanceEnd(): string
    {
        return $this->attendanceThrough !== null && $this->attendanceThrough < $this->periodEnd ? $this->attendanceThrough : $this->periodEnd;
    }

    /** The Pay Basis & Rates a member is priced under on the period's first day: the captured version, or the reference's rules over the captured Formulas rows. */
    public function rulesFor(string $employeeId): PayRules
    {
        $entry = $this->payRules[$employeeId] ?? null;

        return $entry === null ? PayRules::fromFormulas($this->formulas) : PayRulesStore::rulesFor($entry, $this->formulas);
    }

    /**
     * The versions pricing a member's days (DIVERGENCE 17): the first day's,
     * then each captured segment from its date. One segment, the reference's
     * shape, when none took over inside the period.
     */
    public function timelineFor(string $employeeId): PayRulesTimeline
    {
        $segments = [['from' => PayRulesTimeline::ALWAYS, 'rules' => $this->rulesFor($employeeId)]];
        foreach ($this->payRules[$employeeId]['segments'] ?? [] as $segment) {
            $segments[] = ['from' => $segment['from'], 'rules' => PayRulesStore::rulesFor($segment, $this->formulas)];
        }

        return PayRulesTimeline::of($segments);
    }

    /**
     * The figures the engine prices this period from: the captured table
     * versions, or the built-in figures when the evidence carries none.
     */
    public function schedule(): StatutorySchedule
    {
        if ($this->statutoryTables === []) {
            return Statutory::builtIn();
        }

        return StatutorySchedule::fromArrays(array_map(static fn (array $table): array => $table['figures'], $this->statutoryTables));
    }

    /**
     * No Eloquent objects, private leave reasons, credentials or geolocation enter the evidence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attendance = [];
        foreach ($this->employeeIds as $id) {
            $attendance[$id] = $this->attendanceToArray($this->attendance[$id]);
        }
        $settlements = [];
        foreach ($this->settlements as $id => $entries) {
            $settlements[$id] = array_map(fn (SettlementInputs $s): array => [
                'payslip_id' => $s->payslipId, 'run_code' => $s->runCode, 'from' => $s->from, 'to' => $s->to,
                'employee' => self::employeeRowForSchema(self::employeeToArray($s->employee), $this->schemaVersion),
                'formulas' => $s->formulas,
                'holidays' => array_map(static fn (HolidaySetting $h): array => get_object_vars($h), $s->holidays),
                'pay_rules' => $s->payRules,
                'presumed' => ['shift' => $s->presumedShift?->toArray(), 'leaves' => $this->leavesToArray($s->presumedLeaves)],
                'actual' => $this->attendanceToArray($s->actual),
                ...($this->schemaVersion >= 13 ? ['resigned' => $s->resigned, 'presumed_lines' => $s->presumedLines] : []),
            ], $entries);
        }

        return [
            'schema_version' => $this->schemaVersion, 'calculator_version' => $this->calculatorVersion,
            'employee_ids' => $this->employeeIds, 'period_start' => $this->periodStart, 'period_end' => $this->periodEnd,
            'employees' => array_map(fn (EmployeePayData $e): array => self::employeeRowForSchema(self::employeeToArray($e), $this->schemaVersion), $this->employees),
            'attendance' => $attendance, 'formulas' => $this->formulas,
            'holidays' => array_map(static fn (HolidaySetting $h): array => get_object_vars($h), $this->holidays),
            'deductions' => $this->deductions, 'allowances' => $this->allowances, 'earnings' => $this->earnings,
            // Older evidence carries no tax category on a line: its flag is the whole category.
            'incentives' => $this->schemaVersion >= 9 ? $this->incentives : array_map(static fn (array $lines): array => array_map(static fn (array $line): array => array_diff_key($line, ['tax' => true, 'counts_toward_13th' => true]), $lines), $this->incentives),
            'exempt_benefits_ytd' => $this->exemptBenefitsYearToDate,
            'custom_deductions' => $this->customDeductions,
            'active_calculations' => $this->activeCalculations,
            // Schema ≤ 17 carried one version per member for the whole period.
            ...($this->schemaVersion >= 8 ? ['statutory_tables' => $this->statutoryTables, 'pay_rules' => $this->schemaVersion >= 18 ? $this->payRules : array_map(static fn (array $entry): array => array_diff_key($entry, ['segments' => true]), $this->payRules)] : []),
            ...($this->schemaVersion >= 9 ? ['benefit_rules' => $this->benefitRules, 'deduction_rules' => $this->deductionRules] : []),
            ...($this->schemaVersion >= 10 ? ['recurring_lines' => $this->recurringLines, 'skipped_recurring_lines' => $this->skippedRecurringLines] : []),
            ...($this->schemaVersion >= 11 ? ['attendance_through' => $this->attendanceThrough, 'settlements' => $settlements] : []),
            ...($this->schemaVersion >= 12 ? ['leave_charges' => $this->leaveCharges] : []),
            ...($this->schemaVersion >= 14 ? [
                'leave_cash_outs' => $this->schemaVersion >= 24 ? $this->leaveCashOuts : array_map(static fn (array $list): array => array_map(static fn (array $entry): array => array_diff_key($entry, ['purpose' => true, 'salary' => true]), $list), $this->leaveCashOuts),
                'cash_out_type' => $this->schemaVersion >= 24 || $this->cashOutType === null ? $this->cashOutType : array_diff_key($this->cashOutType, ['day_value' => true, 'factor' => true]),
            ] : []),
            ...($this->schemaVersion >= 15 ? ['gov_policies' => $this->govPolicies] : []),
            ...($this->schemaVersion >= 16 ? ['adjustments' => $this->adjustments] : []),
            ...($this->schemaVersion >= 20 ? ['contribution_month' => $this->contributionMonth, 'contribution_posted' => $this->contributionPosted] : []),
            ...($this->schemaVersion >= 21 ? ['benefit_ceiling' => $this->benefitCeiling, 'benefit_assignments' => $this->benefitAssignments] : []),
            ...($this->schemaVersion >= 22 ? ['tax_year' => $this->taxYear] : []),
        ];
    }

    /**
     * One employee's attendance facts as the evidence carries them: the
     * punches (id, employee, type, instant), the schedule, the leaves, the
     * Daily Time Record's decisions (date => approved minutes) and, from
     * schema 17, the category of each day not approved as ordinary
     * overtime (date => SEAPORT).
     *
     * @return array{logs: list<array<string, mixed>>, shift: ?array<string, mixed>, leaves: list<array<string, mixed>>, overtime_approvals: array<string, int>, overtime_categories?: array<string, string>}
     */
    private function attendanceToArray(AttendanceInputs $facts): array
    {
        return [
            'logs' => array_map(static fn (AttendanceLog $log): array => [
                'id' => $log->id, 'employee_id' => $log->employeeId,
                'log_type' => $log->logType, 'logged_at' => $log->loggedAt,
            ], $facts->logs),
            'shift' => $facts->shift?->toArray(),
            'leaves' => $this->leavesToArray($facts->leaves),
            'overtime_approvals' => $facts->overtimeApprovals,
            ...($this->schemaVersion >= 17 ? ['overtime_categories' => $facts->overtimeCategories] : []),
        ];
    }

    /**
     * @param  list<LeaveRequest>  $leaves
     * @return list<array<string, mixed>>
     */
    private function leavesToArray(array $leaves): array
    {
        return array_map(fn (LeaveRequest $leave): array => [
            'id' => $leave->id, 'employee_id' => $leave->employeeId, 'request_type' => $leave->requestType,
            'start_date' => $leave->startDate, 'end_date' => $leave->endDate, 'status' => $leave->status,
            'is_paid' => $leave->isPaid, 'day_type' => $leave->dayType,
            ...($this->schemaVersion >= 7 ? ['cancelled_dates' => $leave->cancelledDates] : []),
            // Divergence 21: which half of a half day is off (schema 23).
            ...($this->schemaVersion >= 23 ? ['half' => $leave->half] : []),
        ], $leaves);
    }

    /**
     * The reverse of attendanceToArray(), strictly. `$categories` is whether
     * the schema carries the overtime categories (17 on); schema ≤ 16 has
     * none, and every day is ordinary overtime.
     */
    private static function attendanceFromArray(mixed $value, bool $categories): AttendanceInputs
    {
        $row = self::map($value);

        return new AttendanceInputs(
            AttendanceLog::listFromArrays(self::rows($row['logs'] ?? null)),
            ($row['shift'] ?? null) === null ? null : Shift::fromArray(self::map($row['shift'])),
            LeaveRequest::listFromArrays(self::rows($row['leaves'] ?? null)),
            self::minutesMap($row['overtime_approvals'] ?? null),
            $categories ? self::categoryMap($row['overtime_categories'] ?? null) : [],
        );
    }

    /**
     * The overtime categories strictly: a date to the one category a day may
     * carry.
     *
     * @return array<string, string>
     */
    private static function categoryMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll overtime evidence.');
        }

        $map = [];
        foreach ($value as $date => $category) {
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $category !== PayRules::OVERTIME_SEAPORT) {
                throw new RuntimeException('Invalid payroll overtime evidence.');
            }
            $map[$date] = $category;
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    public function fingerprint(): string
    {
        return hash('sha256', self::json($this->toArray()));
    }

    /** Restores each supported schema's exact shape without loading live source records. */
    public static function restore(string $json): self
    {
        $data = self::map(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $schemaVersion = $data['schema_version'] ?? null;
        if (! in_array($schemaVersion, [6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, self::SCHEMA_VERSION], true)) {
            throw new RuntimeException('Unsupported payroll evidence schema.');
        }
        $employees = array_map(self::employeeFromArray(...), self::rows($data['employees'] ?? null));
        $attendance = [];
        foreach (self::map($data['attendance'] ?? null) as $id => $value) {
            $attendance[$id] = self::attendanceFromArray($value, $schemaVersion >= 17);
        }
        $ids = array_values(array_map(self::text(...), self::map($data['employee_ids'] ?? null)));
        if (array_diff($ids, array_keys($attendance)) !== []) {
            throw new RuntimeException('Incomplete payroll attendance evidence.');
        }
        $inputs = new self(
            $ids, self::text($data['period_start'] ?? null), self::text($data['period_end'] ?? null),
            $employees, $attendance, self::rows($data['formulas'] ?? null),
            HolidaySetting::listFromArrays(self::rows($data['holidays'] ?? null)),
            array_map(self::map(...), self::map($data['deductions'] ?? null)),
            self::map($data['allowances'] ?? null),
            array_map(self::map(...), self::map($data['earnings'] ?? null)),
            self::text($data['calculator_version'] ?? null),
            self::incentiveMap($data['incentives'] ?? null, $ids, $schemaVersion >= 9),
            self::moneyMap($data['exempt_benefits_ytd'] ?? null, $ids),
            self::customDeductionMap($data['custom_deductions'] ?? null, $ids),
            self::calculationList($data['active_calculations'] ?? null),
            $schemaVersion >= 8 ? self::statutoryTableMap($data['statutory_tables'] ?? null) : [],
            $schemaVersion >= 8 ? self::payRuleMap($data['pay_rules'] ?? null, $ids, $schemaVersion >= 18) : [],
            $schemaVersion >= 9 ? self::benefitRuleMap($data['benefit_rules'] ?? null) : [],
            $schemaVersion >= 9 ? self::deductionRuleMap($data['deduction_rules'] ?? null) : [],
            $schemaVersion >= 10 ? self::recurringLineMap($data['recurring_lines'] ?? null, $ids) : [],
            $schemaVersion >= 10 ? self::skippedLineMap($data['skipped_recurring_lines'] ?? null, $ids) : [],
            $schemaVersion >= 11 ? self::attendanceThrough($data['attendance_through'] ?? null) : null,
            $schemaVersion >= 11 ? self::settlementMap($data['settlements'] ?? null, $ids, $schemaVersion >= 13, $schemaVersion >= 17) : [],
            $schemaVersion >= 12 ? self::leaveChargeMap($data['leave_charges'] ?? null, $ids) : [],
            $schemaVersion >= 14 ? self::leaveCashOutMap($data['leave_cash_outs'] ?? null, $ids, $schemaVersion >= 24) : [],
            $schemaVersion >= 14 ? self::cashOutTypeMap($data['cash_out_type'] ?? null, $schemaVersion >= 24) : null,
            $schemaVersion >= 15 ? self::govPolicyMap($data['gov_policies'] ?? null, $ids) : [],
            $schemaVersion >= 16 ? self::adjustmentMap($data['adjustments'] ?? null, $ids) : [],
            $schemaVersion >= 20 ? self::contributionMonthOf($data['contribution_month'] ?? null) : null,
            $schemaVersion >= 20 ? self::contributionPostedMap($data['contribution_posted'] ?? null, $ids) : [],
            $schemaVersion >= 21 ? self::benefitCeilingEntry($data['benefit_ceiling'] ?? null) : null,
            $schemaVersion >= 21 ? self::benefitAssignmentMap($data['benefit_assignments'] ?? null, $ids) : [],
            $schemaVersion >= 22 ? self::taxYearEntry($data['tax_year'] ?? null, $ids) : null,
            $schemaVersion,
        );
        // Reject missing, coerced or unknown values rather than silently changing saved evidence.
        if (self::json($inputs->toArray()) !== self::json($data)) {
            throw new RuntimeException('Payroll evidence does not match its recorded schema.');
        }

        return $inputs;
    }

    /**
     * One employee as the evidence carries it: identity, employment dates,
     * rates (monthly, daily and hourly) and the sixteen statutory settings. Shared with the 13th month
     * run's evidence (ThirteenthMonthInputs), so both capture an employee the
     * same way.
     *
     * @return array<string, mixed>
     */
    public static function employeeToArray(EmployeePayData $e): array
    {
        return [
            'id' => $e->id, 'employee_code' => $e->employee_code, 'first_name' => $e->first_name,
            'last_name' => $e->last_name, 'suffix' => $e->suffix, 'position' => $e->position,
            'photo_path' => $e->photo_path, 'date_hired' => $e->date_hired->format('Y-m-d'),
            'date_resigned' => $e->date_resigned?->format('Y-m-d'), 'monthly_rate' => $e->monthly_rate,
            'daily_rate' => $e->daily_rate, 'hourly_rate' => $e->hourly_rate, 'is_active' => $e->is_active,
            'allow_sss' => $e->allow_sss, 'allow_philhealth' => $e->allow_philhealth,
            'allow_pagibig' => $e->allow_pagibig, 'allow_withholding_tax' => $e->allow_withholding_tax,
            'annualize_tax' => $e->annualize_tax, 'fixed_tax' => $e->fixed_tax, 'percent_tax' => $e->percent_tax,
            'fixed_sss_ee' => $e->fixed_sss_ee, 'fixed_sss_er' => $e->fixed_sss_er,
            'fixed_philhealth_ee' => $e->fixed_philhealth_ee, 'fixed_philhealth_er' => $e->fixed_philhealth_er,
            'fixed_pagibig_ee' => $e->fixed_pagibig_ee, 'fixed_pagibig_er' => $e->fixed_pagibig_er,
            'sss_mode' => $e->sss_mode, 'philhealth_mode' => $e->philhealth_mode, 'pagibig_mode' => $e->pagibig_mode,
            // The Employment status, by name and by the list entry it names
            // (which Pay Basis & Rates overrides are keyed on).
            'employment_status' => $e->employment_status, 'employment_status_id' => $e->employment_status_id,
            // DIVERGENCE 18: the salary versions taking over inside the period.
            'salary_rates' => $e->salary_rates,
            // DIVERGENCE 20: the whole-year withholding estimate and its spread.
            'whole_year_tax' => $e->whole_year_tax, 'tax_spread' => $e->tax_spread,
        ];
    }

    /**
     * `employeeToArray` as an older schema carried it: without the salary
     * segments (schema 19) or the whole-year keys (schema 22).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function employeeRowForSchema(array $row, int $schemaVersion): array
    {
        if ($schemaVersion < 19) {
            unset($row['salary_rates']);
        }
        if ($schemaVersion < 22) {
            unset($row['whole_year_tax'], $row['tax_spread']);
        }

        return $row;
    }

    /**
     * The reverse, strictly: a missing, coerced or mistyped value is refused.
     *
     * @param  array<string, mixed>  $e
     */
    public static function employeeFromArray(array $e): EmployeePayData
    {
        return new EmployeePayData(
            self::text($e['id'] ?? null), self::text($e['employee_code'] ?? null),
            self::text($e['first_name'] ?? null), self::text($e['last_name'] ?? null),
            self::nullableText($e['suffix'] ?? null), self::nullableText($e['position'] ?? null),
            self::nullableText($e['photo_path'] ?? null),
            CarbonImmutable::parse(self::text($e['date_hired'] ?? null), 'UTC'),
            ($e['date_resigned'] ?? null) === null ? null : CarbonImmutable::parse(self::text($e['date_resigned']), 'UTC'),
            ($e['monthly_rate'] ?? null) === null ? null : self::number($e['monthly_rate']),
            ($e['daily_rate'] ?? null) === null ? null : self::number($e['daily_rate']),
            ($e['hourly_rate'] ?? null) === null ? null : self::number($e['hourly_rate']),
            self::boolean($e['is_active'] ?? null),
            allow_sss: self::boolean($e['allow_sss'] ?? null),
            allow_philhealth: self::boolean($e['allow_philhealth'] ?? null),
            allow_pagibig: self::boolean($e['allow_pagibig'] ?? null),
            allow_withholding_tax: self::boolean($e['allow_withholding_tax'] ?? null),
            annualize_tax: self::boolean($e['annualize_tax'] ?? null),
            fixed_tax: self::number($e['fixed_tax'] ?? null),
            percent_tax: self::number($e['percent_tax'] ?? null),
            fixed_sss_ee: self::number($e['fixed_sss_ee'] ?? null),
            fixed_sss_er: self::number($e['fixed_sss_er'] ?? null),
            fixed_philhealth_ee: self::number($e['fixed_philhealth_ee'] ?? null),
            fixed_philhealth_er: self::number($e['fixed_philhealth_er'] ?? null),
            fixed_pagibig_ee: self::number($e['fixed_pagibig_ee'] ?? null),
            fixed_pagibig_er: self::number($e['fixed_pagibig_er'] ?? null),
            sss_mode: self::nullableText($e['sss_mode'] ?? null),
            philhealth_mode: self::nullableText($e['philhealth_mode'] ?? null),
            pagibig_mode: self::nullableText($e['pagibig_mode'] ?? null),
            employment_status: self::nullableText($e['employment_status'] ?? null),
            employment_status_id: self::nullableText($e['employment_status_id'] ?? null),
            // Schema ≤ 18 carried no salary segments: one salary for the period.
            salary_rates: array_key_exists('salary_rates', $e) ? self::salarySegments($e['salary_rates']) : [],
            // Schema ≤ 21 carried no whole-year keys: the reference's methods.
            whole_year_tax: array_key_exists('whole_year_tax', $e) ? self::boolean($e['whole_year_tax']) : false,
            tax_spread: array_key_exists('tax_spread', $e) ? self::nullableText($e['tax_spread']) : null,
        );
    }

    /**
     * The salary segments strictly: dated, ascending, each a monthly and a
     * daily rate (null = blank), at least one of them.
     *
     * @return list<array{from: string, monthly_rate: ?float, daily_rate: ?float}>
     */
    private static function salarySegments(mixed $value): array
    {
        $list = [];
        $previous = null;
        foreach (self::rows($value) as $segment) {
            $keys = array_keys($segment);
            sort($keys, SORT_STRING);
            $from = self::text($segment['from'] ?? null);
            if ($keys !== ['daily_rate', 'from', 'monthly_rate'] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || ($previous !== null && $from <= $previous)) {
                throw new RuntimeException('Invalid payroll salary evidence.');
            }
            $monthly = $segment['monthly_rate'] === null ? null : self::number($segment['monthly_rate']);
            $daily = $segment['daily_rate'] === null ? null : self::number($segment['daily_rate']);
            if ($monthly === null && $daily === null) {
                throw new RuntimeException('Invalid payroll salary evidence.');
            }
            $list[] = ['from' => $from, 'monthly_rate' => $monthly, 'daily_rate' => $daily];
            $previous = $from;
        }

        return $list;
    }

    /**
     * The cash-outs exactly as captured: members' ids only, each a list of
     * requests with their id, token, name and positive days — and from
     * schema 24 their purpose and a terminal one's salary. Older evidence
     * reads every request as a monetization, as it was priced.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{id: string, request_type: string, name: string, days: float, purpose: string, salary: array{from: ?string, monthly_rate: ?float, daily_rate: ?float}|null}>>
     */
    private static function leaveCashOutMap(mixed $value, array $ids, bool $priced): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll cash conversion evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $entries) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('Invalid payroll cash conversion evidence.');
            }
            foreach ($entries as $entry) {
                $row = self::map($entry);
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== ($priced ? ['days', 'id', 'name', 'purpose', 'request_type', 'salary'] : ['days', 'id', 'name', 'request_type'])) {
                    throw new RuntimeException('Invalid payroll cash conversion evidence.');
                }
                $days = self::number($row['days']);
                if ($days <= 0) {
                    throw new RuntimeException('Invalid payroll cash conversion evidence.');
                }
                $purpose = $priced ? self::text($row['purpose']) : LeaveCashOutSource::MONETIZATION;
                if (! in_array($purpose, [LeaveCashOutSource::MONETIZATION, LeaveCashOutSource::TERMINAL], true)) {
                    throw new RuntimeException('Invalid payroll cash conversion evidence.');
                }
                $salary = $priced && $row['salary'] !== null ? self::cashOutSalary($row['salary']) : null;
                if (($salary !== null) !== ($purpose === LeaveCashOutSource::TERMINAL)) {
                    throw new RuntimeException('Invalid payroll cash conversion evidence.');
                }
                $map[$employeeId][] = ['id' => self::text($row['id']), 'request_type' => self::text($row['request_type']), 'name' => self::text($row['name']), 'days' => $days, 'purpose' => $purpose, 'salary' => $salary];
            }
        }

        return $map;
    }

    /**
     * A terminal request's salary exactly as captured: the version's date
     * (null for the record's undated rates) and its two rates.
     *
     * @return array{from: ?string, monthly_rate: ?float, daily_rate: ?float}
     */
    private static function cashOutSalary(mixed $value): array
    {
        $row = self::map($value);
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== ['daily_rate', 'from', 'monthly_rate']) {
            throw new RuntimeException('Invalid payroll cash conversion evidence.');
        }
        if ($row['from'] !== null && (! is_string($row['from']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['from']) !== 1)) {
            throw new RuntimeException('Invalid payroll cash conversion evidence.');
        }

        return [
            'from' => $row['from'],
            'monthly_rate' => $row['monthly_rate'] === null ? null : self::number($row['monthly_rate']),
            'daily_rate' => $row['daily_rate'] === null ? null : self::number($row['daily_rate']),
        ];
    }

    /**
     * The cash-out type exactly as captured, or null — from schema 24 with
     * what a converted day pays on it; older evidence pays the daily rate,
     * as it was priced.
     *
     * @return array{type_id: string, code: string, name: string, is_taxable: bool, tax: string, counts_toward_13th: bool, day_value: string, factor: ?float}|null
     */
    private static function cashOutTypeMap(mixed $value, bool $priced): ?array
    {
        if ($value === null) {
            return null;
        }
        $row = self::map($value);
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== ($priced ? ['code', 'counts_toward_13th', 'day_value', 'factor', 'is_taxable', 'name', 'tax', 'type_id'] : ['code', 'counts_toward_13th', 'is_taxable', 'name', 'tax', 'type_id'])) {
            throw new RuntimeException('Invalid payroll cash conversion evidence.');
        }
        $taxable = self::boolean($row['is_taxable']);
        $tax = self::text($row['tax']);
        if (! in_array($tax, BenefitRule::TAXES, true) || ($tax === BenefitRule::TAX_TAXABLE) !== $taxable) {
            throw new RuntimeException('Invalid payroll cash conversion evidence.');
        }
        $dayValue = LeaveCashOutValue::daily();
        if ($priced) {
            $method = self::text($row['day_value']);
            $factor = $row['factor'] === null ? null : self::number($row['factor']);
            if (! in_array($method, LeaveCashOutValue::METHODS, true) || ($method === LeaveCashOutValue::FACTOR) !== ($factor !== null && $factor > 0.0 && $factor <= 1.0)) {
                throw new RuntimeException('Invalid payroll cash conversion evidence.');
            }
            $dayValue = new LeaveCashOutValue($method, $factor);
        }

        return ['type_id' => self::text($row['type_id']), 'code' => self::text($row['code']), 'name' => self::text($row['name']), 'is_taxable' => $taxable, 'tax' => $tax, 'counts_toward_13th' => self::boolean($row['counts_toward_13th']), 'day_value' => $dayValue->method, 'factor' => $dayValue->factor];
    }

    /**
     * The tax-year entry, strictly (divergence 20): the year, the payrolls
     * left and each listed member's posted figures, or null.
     *
     * @param  list<string>  $ids
     * @return array{year: int, payrolls_left: int, posted: array<string, array{taxable: float, withheld: float}>}|null
     */
    public static function taxYearEntry(mixed $value, array $ids): ?array
    {
        if ($value === null) {
            return null;
        }
        $row = self::map($value);
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== ['payrolls_left', 'posted', 'year'] || ! is_int($row['year']) || ! is_int($row['payrolls_left'])
            || $row['year'] < 2000 || $row['year'] > 2100 || $row['payrolls_left'] < 0 || $row['payrolls_left'] > 400) {
            throw new RuntimeException('Invalid payroll tax year evidence.');
        }
        $posted = [];
        foreach (self::map($row['posted']) as $employeeId => $figures) {
            if (! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll tax year evidence.');
            }
            $entry = self::map($figures);
            $entryKeys = array_keys($entry);
            sort($entryKeys, SORT_STRING);
            if ($entryKeys !== ['taxable', 'withheld']) {
                throw new RuntimeException('Invalid payroll tax year evidence.');
            }
            $posted[$employeeId] = ['taxable' => self::number($entry['taxable']), 'withheld' => self::number($entry['withheld'])];
        }

        return ['year' => $row['year'], 'payrolls_left' => $row['payrolls_left'], 'posted' => $posted];
    }

    /**
     * The tax-free ceiling version strictly: null, or its id, date and
     * pesos at or above zero. Shared with ThirteenthMonthInputs.
     *
     * @return array{version_id: string, effective_from: string, ceiling: float}|null
     */
    public static function benefitCeilingEntry(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $row = self::map($value);
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        $from = self::text($row['effective_from'] ?? null);
        $ceiling = self::number($row['ceiling'] ?? null);
        if ($keys !== ['ceiling', 'effective_from', 'version_id'] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || $ceiling < 0.0) {
            throw new RuntimeException('Invalid payroll benefit ceiling evidence.');
        }

        return ['version_id' => self::text($row['version_id'] ?? null), 'effective_from' => $from, 'ceiling' => $ceiling];
    }

    /**
     * The benefit assignments strictly: members' ids only, each a list of
     * dated assignments with an amount above zero or null.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{id: string, type_id: string, starts_on: string, ends_on: ?string, amount: ?float}>>
     */
    private static function benefitAssignmentMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll benefit assignment evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $rows) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll benefit assignment evidence.');
            }
            $list = [];
            foreach (self::rows($rows) as $row) {
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                $starts = self::text($row['starts_on'] ?? null);
                $ends = self::nullableText($row['ends_on'] ?? null);
                if ($keys !== ['amount', 'ends_on', 'id', 'starts_on', 'type_id'] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1
                    || ($ends !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) !== 1 || $ends < $starts))) {
                    throw new RuntimeException('Invalid payroll benefit assignment evidence.');
                }
                $amount = $row['amount'] === null ? null : self::number($row['amount']);
                if ($amount !== null && $amount <= 0.0) {
                    throw new RuntimeException('Invalid payroll benefit assignment evidence.');
                }
                $list[] = ['id' => self::text($row['id'] ?? null), 'type_id' => self::text($row['type_id'] ?? null), 'starts_on' => $starts, 'ends_on' => $ends, 'amount' => $amount];
            }
            $map[$employeeId] = $list;
        }

        return $map;
    }

    /** The contribution month strictly: null, or 'YYYY-MM'. */
    private static function contributionMonthOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            throw new RuntimeException('Invalid payroll contribution evidence.');
        }

        return $value;
    }

    /**
     * The shares already posted strictly: members' ids only, each the six
     * lines as non-negative numbers.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, float>>
     */
    private static function contributionPostedMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll contribution evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $lines) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll contribution evidence.');
            }
            $row = self::map($lines);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            $expected = ContributionMonthToDate::LINES;
            sort($expected, SORT_STRING);
            if ($keys !== $expected) {
                throw new RuntimeException('Invalid payroll contribution evidence.');
            }
            $sums = [];
            foreach (ContributionMonthToDate::LINES as $line) {
                $sum = self::number($row[$line]);
                if ($sum < 0.0) {
                    throw new RuntimeException('Invalid payroll contribution evidence.');
                }
                $sums[$line] = $sum;
            }
            $map[$employeeId] = $sums;
        }

        return $map;
    }

    /**
     * The approved adjustment lines exactly as captured: members' ids only,
     * each line with the keys Adjustments::entry gives it, a readable kind,
     * tax and amount (AdjustmentRule).
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{id: string, category_id: string, code: string, name: string, kind: string, tax: ?string, amount: float, reason: string}>>
     */
    private static function adjustmentMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll adjustment evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $lines) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($lines) || ! array_is_list($lines) || $lines === []) {
                throw new RuntimeException('Invalid payroll adjustment evidence.');
            }
            foreach ($lines as $line) {
                $row = self::map($line);
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== ['amount', 'category_id', 'code', 'id', 'kind', 'name', 'reason', 'tax'] || AdjustmentRule::lineError($row) !== null) {
                    throw new RuntimeException('Invalid payroll adjustment evidence.');
                }
                $map[$employeeId][] = [
                    'id' => self::text($row['id']), 'category_id' => self::text($row['category_id']), 'code' => self::text($row['code']),
                    'name' => self::text($row['name']), 'kind' => self::text($row['kind']), 'tax' => $row['tax'] === null ? null : self::text($row['tax']),
                    'amount' => self::number($row['amount']), 'reason' => self::text($row['reason']),
                ];
            }
        }

        return $map;
    }

    /**
     * Each member's Contributions & tax version exactly as captured:
     * members' ids only, a version id, a 'Y-m-d' date and figures
     * GovDeductionSettings reads strictly.
     *
     * @param  list<string>  $ids
     * @return array<string, array{version_id: string, effective_from: string, figures: array<string, mixed>}>
     */
    private static function govPolicyMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll contribution policy evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $entry) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll contribution policy evidence.');
            }
            $row = self::map($entry);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['effective_from', 'figures', 'version_id']) {
                throw new RuntimeException('Invalid payroll contribution policy evidence.');
            }
            $from = self::text($row['effective_from']);
            $figures = self::map($row['figures']);
            if (! PayrollPeriod::isPayrollDate($from) || GovDeductionSettings::error($figures) !== null) {
                throw new RuntimeException('Invalid payroll contribution policy evidence.');
            }
            $map[$employeeId] = ['version_id' => self::text($row['version_id']), 'effective_from' => $from, 'figures' => $figures];
        }

        return $map;
    }

    /**
     * The leave-credit charge facts exactly as captured: members' ids only,
     * each entry with the keys LeaveChargeSource gives it, every balance a
     * finite number, every charged date a 'Y-m-d'.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private static function leaveChargeMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $entry) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
            }
            $row = self::map($entry);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['balances', 'charged', 'charges', 'conversion', 'policy_id', 'year']) {
                throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
            }
            $charges = self::map($row['charges']);
            $chargeKeys = array_keys($charges);
            sort($chargeKeys, SORT_STRING);
            if ($chargeKeys !== ['absence', 'combine', 'enabled', 'late'] || ! is_bool($charges['enabled']) || ! is_string($charges['combine'])
                || ! is_array($charges['late']) || ! array_is_list($charges['late']) || ! is_array($charges['absence']) || ! array_is_list($charges['absence'])) {
                throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
            }
            foreach ([...$charges['late'], ...$charges['absence']] as $token) {
                self::text($token);
            }
            if ($row['conversion'] !== null) {
                $conversion = self::map($row['conversion']);
                $conversionKeys = array_keys($conversion);
                sort($conversionKeys, SORT_STRING);
                if ($conversionKeys !== ['decimals', 'hours_per_day', 'rows', 'version_id'] || ! is_int($conversion['decimals'])) {
                    throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                }
                self::text($conversion['version_id']);
                self::number($conversion['hours_per_day']);
                $rows = self::map($conversion['rows']);
                $rowKeys = array_keys($rows);
                sort($rowKeys, SORT_STRING);
                if ($rowKeys !== ['hours', 'minutes']) {
                    throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                }
                foreach ([...array_values(self::map($rows['minutes'])), ...array_values(self::map($rows['hours']))] as $cell) {
                    if (! is_string($cell) && ! is_int($cell) && ! is_float($cell)) {
                        throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                    }
                }
            }
            foreach (self::map($row['balances']) as $token => $balance) {
                $balance = self::map($balance);
                $balanceKeys = array_keys($balance);
                sort($balanceKeys, SORT_STRING);
                // `available_from` is on a balance only for a leave type with a
                // waiting period or one not everyone may use: the first day
                // it is the employee's.
                if ($balanceKeys !== ['available', 'below_zero_limit', 'name'] && $balanceKeys !== ['available', 'available_from', 'below_zero_limit', 'name']) {
                    throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                }
                if (array_key_exists('available_from', $balance) && (! is_string($balance['available_from']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $balance['available_from']) !== 1)) {
                    throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                }
                self::text($balance['name']);
                self::number($balance['available']);
                self::number($balance['below_zero_limit']);
            }
            if (! is_int($row['year'])) {
                throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
            }
            self::text($row['policy_id']);
            $charged = self::map($row['charged']);
            $chargedKeys = array_keys($charged);
            sort($chargedKeys, SORT_STRING);
            if ($chargedKeys !== ['absence', 'late']) {
                throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
            }
            foreach ([...array_values(self::map($charged['late'])), ...array_values(self::map($charged['absence']))] as $date) {
                if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw new RuntimeException('Invalid payroll leave credit deduction evidence.');
                }
            }
            $map[$employeeId] = $row;
        }

        return $map;
    }

    private static function attendanceThrough(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new RuntimeException('Invalid payroll attendance evidence.');
        }

        return $value;
    }

    /**
     * The settlements exactly as captured: members' ids only, each entry
     * with the keys it was captured with, the employee, Formulas, holidays
     * and rates as the advance payroll's evidence held them, and the facts.
     * Schema 13 entries also carry today's leaving date and the frozen
     * presumed lines (`$frozen`); older ones restore with neither.
     *
     * @param  list<string>  $ids
     * @return array<string, list<SettlementInputs>>
     */
    private static function settlementMap(mixed $value, array $ids, bool $frozen, bool $categories): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll settlement evidence.');
        }
        $expectedKeys = ['actual', 'employee', 'formulas', 'from', 'holidays', 'pay_rules', 'payslip_id', 'presumed', 'run_code', 'to'];
        if ($frozen) {
            $expectedKeys = [...$expectedKeys, 'presumed_lines', 'resigned'];
            sort($expectedKeys, SORT_STRING);
        }
        $map = [];
        foreach ($value as $employeeId => $entries) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('Invalid payroll settlement evidence.');
            }
            foreach ($entries as $entry) {
                $row = self::map($entry);
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== $expectedKeys) {
                    throw new RuntimeException('Invalid payroll settlement evidence.');
                }
                $resigned = null;
                $presumedLines = null;
                if ($frozen) {
                    if ($row['resigned'] !== null && (! is_string($row['resigned']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['resigned']) !== 1)) {
                        throw new RuntimeException('Invalid payroll settlement evidence.');
                    }
                    $resigned = $row['resigned'];
                    if ($row['presumed_lines'] !== null) {
                        $presumedLines = AdvanceSettlements::linesFrom($row['presumed_lines']);
                        if ($presumedLines === null) {
                            throw new RuntimeException('Invalid payroll settlement evidence.');
                        }
                    }
                }
                $from = self::text($row['from']);
                $to = self::text($row['to']);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to) {
                    throw new RuntimeException('Invalid payroll settlement evidence.');
                }
                $employee = self::employeeFromArray(self::map($row['employee']));
                if ($employee->id !== $employeeId) {
                    throw new RuntimeException('Invalid payroll settlement evidence.');
                }
                $presumed = self::map($row['presumed']);
                $presumedKeys = array_keys($presumed);
                sort($presumedKeys, SORT_STRING);
                if ($presumedKeys !== ['leaves', 'shift']) {
                    throw new RuntimeException('Invalid payroll settlement evidence.');
                }
                // A settlement keeps the advance payroll's first-day version alone.
                $payRules = $row['pay_rules'] === null ? null : (self::payRuleMap([$employeeId => $row['pay_rules']], $ids, false)[$employeeId] ?? null);
                $map[$employeeId][] = new SettlementInputs(
                    self::text($row['payslip_id']), self::text($row['run_code']), $from, $to,
                    $employee, self::rows($row['formulas']),
                    HolidaySetting::listFromArrays(self::rows($row['holidays'])),
                    $payRules,
                    ($presumed['shift'] ?? null) === null ? null : Shift::fromArray(self::map($presumed['shift'])),
                    LeaveRequest::listFromArrays(self::rows($presumed['leaves'] ?? null)),
                    self::attendanceFromArray($row['actual'], $categories),
                    $resigned,
                    $presumedLines,
                );
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll evidence structure.');
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return array_values(array_map(self::map(...), self::map($value)));
    }

    /**
     * An overtime-decision map exactly as captured: 'Y-m-d' keys, whole
     * minutes 0..1440, in date order. Anything else is refused, never coerced.
     *
     * @return array<string, int>
     */
    private static function minutesMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll overtime evidence.');
        }

        // The raw array, not self::map(): its keys are checked here, and a
        // numeric-looking key would arrive as an int.
        $map = [];
        foreach ($value as $date => $minutes) {
            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! is_int($minutes) || $minutes < 0 || $minutes > 1440) {
                throw new RuntimeException('Invalid payroll overtime evidence.');
            }
            $map[$date] = $minutes;
        }
        ksort($map, SORT_STRING);

        return $map;
    }

    /**
     * Incentive lines exactly as captured: members' ids only, each line a
     * type id, code, name, flag and a positive amount. Anything else is
     * refused, never coerced.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{type_id: string, code: string, name: string, is_taxable: bool, amount: float, tax: string, counts_toward_13th: bool}>>
     */
    private static function incentiveMap(mixed $value, array $ids, bool $withCategory = true): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll incentive evidence.');
        }

        $map = [];
        foreach ($value as $employeeId => $lines) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($lines) || ! array_is_list($lines)) {
                throw new RuntimeException('Invalid payroll incentive evidence.');
            }
            foreach ($lines as $line) {
                $row = self::map($line);
                $amount = self::number($row['amount'] ?? null);
                if ($amount <= 0) {
                    throw new RuntimeException('Invalid payroll incentive evidence.');
                }
                $taxable = self::boolean($row['is_taxable'] ?? null);
                // The category, from schema 9; before it the flag was the whole category.
                $tax = $withCategory ? self::text($row['tax'] ?? null) : ($taxable ? BenefitRule::TAX_TAXABLE : BenefitRule::TAX_EXEMPT);
                if (! in_array($tax, BenefitRule::TAXES, true) || ($tax === BenefitRule::TAX_TAXABLE) !== $taxable) {
                    throw new RuntimeException('Invalid payroll incentive evidence.');
                }
                $map[$employeeId][] = [
                    'type_id' => self::text($row['type_id'] ?? null),
                    'code' => self::text($row['code'] ?? null),
                    'name' => self::text($row['name'] ?? null),
                    'is_taxable' => $taxable,
                    'amount' => $amount,
                    'tax' => $tax,
                    'counts_toward_13th' => $withCategory ? self::boolean($row['counts_toward_13th'] ?? null) : false,
                ];
            }
        }

        return $map;
    }

    /**
     * The scheduled benefits exactly as captured: each type's code, name
     * and the figures BenefitRule accepts. A replay must never read the
     * catalog to learn what was due.
     *
     * @return array<string, array{code: string, name: string, figures: array<string, mixed>}>
     */
    private static function benefitRuleMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll benefit rule evidence.');
        }
        $map = [];
        foreach ($value as $typeId => $entry) {
            if (! is_string($typeId) || $typeId === '') {
                throw new RuntimeException('Invalid payroll benefit rule evidence.');
            }
            $row = self::map($entry);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['code', 'figures', 'name']) {
                throw new RuntimeException('Invalid payroll benefit rule evidence.');
            }
            $figures = self::map($row['figures']);
            if (BenefitRule::error($figures) !== null || ! BenefitRule::fromArray($figures)->isScheduled()) {
                throw new RuntimeException('Invalid payroll benefit rule evidence.');
            }
            $map[$typeId] = ['code' => self::text($row['code']), 'name' => self::text($row['name']), 'figures' => $figures];
        }

        return $map;
    }

    /**
     * The percentage deductions exactly as captured: each row's code, name
     * and the figures PercentDeduction accepts.
     *
     * @return array<string, array{code: string, name: string, figures: array<string, mixed>}>
     */
    private static function deductionRuleMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll deduction rule evidence.');
        }
        $map = [];
        foreach ($value as $rowId => $entry) {
            if (! is_string($rowId) || $rowId === '') {
                throw new RuntimeException('Invalid payroll deduction rule evidence.');
            }
            $row = self::map($entry);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['code', 'figures', 'name']) {
                throw new RuntimeException('Invalid payroll deduction rule evidence.');
            }
            $figures = self::map($row['figures']);
            if (PercentDeduction::error($figures) !== null) {
                throw new RuntimeException('Invalid payroll deduction rule evidence.');
            }
            $map[$rowId] = ['code' => self::text($row['code']), 'name' => self::text($row['name']), 'figures' => $figures];
        }

        return $map;
    }

    /**
     * The members' recurring lines exactly as captured: members' ids only,
     * each line with the keys it was captured with, figures RecurringLine
     * accepts, a deduction carrying no tax category and an incentive one,
     * and a non-negative amount already paid.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{line_id: string, kind: string, type_id: string, code: string, name: string, amount: float, total: ?float, paid_before: float, frequency: string, starts_on: string, ends_on: ?string, is_taxable: bool, tax: ?string, counts_toward_13th: bool}>>
     */
    private static function recurringLineMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll recurring line evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $lines) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($lines) || ! array_is_list($lines)) {
                throw new RuntimeException('Invalid payroll recurring line evidence.');
            }
            foreach ($lines as $line) {
                $row = self::map($line);
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== ['amount', 'code', 'counts_toward_13th', 'ends_on', 'frequency', 'is_taxable', 'kind', 'line_id', 'name', 'paid_before', 'starts_on', 'tax', 'total', 'type_id']) {
                    throw new RuntimeException('Invalid payroll recurring line evidence.');
                }
                $figures = ['kind' => $row['kind'], 'amount' => $row['amount'], 'total' => $row['total'], 'frequency' => $row['frequency'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on']];
                $tax = self::nullableText($row['tax']);
                $paid = self::number($row['paid_before']);
                if (RecurringLine::error($figures) !== null || $paid < 0
                    || ($row['kind'] === RecurringLine::KIND_DEDUCTION) !== ($tax === null)
                    || ($tax !== null && ! in_array($tax, BenefitRule::TAXES, true))) {
                    throw new RuntimeException('Invalid payroll recurring line evidence.');
                }
                $map[$employeeId][] = [
                    'line_id' => self::text($row['line_id']), 'kind' => (string) $row['kind'],
                    'type_id' => self::text($row['type_id']), 'code' => self::text($row['code']), 'name' => self::text($row['name']),
                    'amount' => self::number($row['amount']), 'total' => $row['total'] === null ? null : self::number($row['total']),
                    'paid_before' => $paid, 'frequency' => (string) $row['frequency'],
                    'starts_on' => (string) $row['starts_on'], 'ends_on' => self::nullableText($row['ends_on']),
                    'is_taxable' => self::boolean($row['is_taxable']), 'tax' => $tax, 'counts_toward_13th' => self::boolean($row['counts_toward_13th']),
                ];
            }
        }

        return $map;
    }

    /**
     * The recurring lines skipped on this payroll exactly as captured:
     * members' ids only, each a list of line ids.
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private static function skippedLineMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll recurring line evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $lineIds) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($lineIds) || ! array_is_list($lineIds)) {
                throw new RuntimeException('Invalid payroll recurring line evidence.');
            }
            $map[$employeeId] = array_map(self::text(...), $lineIds);
        }

        return $map;
    }

    /**
     * Named deductions exactly as reviewed. A replay must never read the
     * mutable catalog to learn a name or an amount.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{type_id: string, code: string, name: string, amount: float}>>
     */
    private static function customDeductionMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll deduction evidence.');
        }

        $map = [];
        foreach ($value as $employeeId => $lines) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true) || ! is_array($lines) || ! array_is_list($lines)) {
                throw new RuntimeException('Invalid payroll deduction evidence.');
            }
            foreach ($lines as $line) {
                $row = self::map($line);
                $amount = self::number($row['amount'] ?? null);
                if ($amount < 0) {
                    throw new RuntimeException('Invalid payroll deduction evidence.');
                }
                $map[$employeeId][] = [
                    'type_id' => self::text($row['type_id'] ?? null),
                    'code' => self::text($row['code'] ?? null),
                    'name' => self::text($row['name'] ?? null),
                    'amount' => $amount,
                ];
            }
        }

        return $map;
    }

    /**
     * The switched-on computed deductions exactly as captured: known
     * calculations only, each once, in the constants' order.
     *
     * @return list<string>
     */
    private static function calculationList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Invalid payroll deduction evidence.');
        }
        $list = [];
        foreach ($value as $calculation) {
            if (! is_string($calculation) || ! DeductionCalculation::isValid($calculation) || in_array($calculation, $list, true)) {
                throw new RuntimeException('Invalid payroll deduction evidence.');
            }
            $list[] = $calculation;
        }

        return DeductionCalculation::order($list);
    }

    /**
     * The four Contribution & Tax Table versions exactly as captured: every
     * kind once, each a version id and dates (null for the built-in
     * figures) and figures the engine accepts. Shared with the 13th month
     * run's evidence, which captures the withholding table the same way.
     *
     * @param  list<string>|null  $kinds  the kinds expected, in capture order (every kind when null)
     * @return array<string, array{version_id: ?string, effective_from: ?string, covered_through: ?string, figures: array<string, mixed>}>
     */
    public static function statutoryTableMap(mixed $value, ?array $kinds = null): array
    {
        $kinds ??= StatutorySchedule::KINDS;
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll statutory table evidence.');
        }
        // The canonical JSON sorts map keys, so the kinds and the row keys
        // are compared as sets and rebuilt in the capture's order.
        $given = array_map('strval', array_keys($value));
        sort($given, SORT_STRING);
        $expected = $kinds;
        sort($expected, SORT_STRING);
        if ($given !== $expected) {
            throw new RuntimeException('Invalid payroll statutory table evidence.');
        }

        $map = [];
        foreach ($kinds as $kind) {
            $row = self::map($value[$kind]);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ['covered_through', 'effective_from', 'figures', 'version_id']) {
                throw new RuntimeException('Invalid payroll statutory table evidence.');
            }
            $versionId = self::nullableText($row['version_id']);
            $from = self::nullableText($row['effective_from']);
            $through = self::nullableText($row['covered_through']);
            if (($versionId === null) !== ($from === null) || ($versionId === null) !== ($through === null)
                || ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1)
                || ($through !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $through) !== 1)) {
                throw new RuntimeException('Invalid payroll statutory table evidence.');
            }
            $figures = self::map($row['figures']);
            if (StatutorySchedule::error($kind, $figures) !== null) {
                throw new RuntimeException('Invalid payroll statutory table evidence.');
            }
            $map[$kind] = ['version_id' => $versionId, 'effective_from' => $from, 'covered_through' => $through, 'figures' => $figures];
        }

        return $map;
    }

    /**
     * The Pay Basis & Rates version pricing each member, exactly as
     * captured: members' ids only, each a scope, a version id and date (null
     * for the reference's rules) and figures PayRules accepts.
     *
     * @param  list<string>  $ids
     * @return array<string, array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}>
     */
    private static function payRuleMap(mixed $value, array $ids, bool $segments): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll pay rules evidence.');
        }
        $map = [];
        foreach ($value as $employeeId => $entry) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll pay rules evidence.');
            }
            $row = self::map($entry);
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== ($segments ? ['effective_from', 'figures', 'scope', 'segments', 'version_id'] : ['effective_from', 'figures', 'scope', 'version_id'])) {
                throw new RuntimeException('Invalid payroll pay rules evidence.');
            }
            $map[$employeeId] = self::payRuleEntry($row);
            if ($segments) {
                // DIVERGENCE 17: the versions taking over inside the period,
                // ascending, each dated.
                $previous = null;
                $list = [];
                foreach (self::rows($row['segments']) as $segment) {
                    $segmentKeys = array_keys($segment);
                    sort($segmentKeys, SORT_STRING);
                    $from = self::text($segment['from'] ?? null);
                    if ($segmentKeys !== ['effective_from', 'figures', 'from', 'scope', 'version_id'] || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || ($previous !== null && $from <= $previous)) {
                        throw new RuntimeException('Invalid payroll pay rules evidence.');
                    }
                    $list[] = ['from' => $from, ...self::payRuleEntry($segment)];
                    $previous = $from;
                }
                $map[$employeeId]['segments'] = $list;
            }
        }

        return $map;
    }

    /**
     * One captured version, strictly.
     *
     * @param  array<string, mixed>  $row
     * @return array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}
     */
    private static function payRuleEntry(array $row): array
    {
        $versionId = self::nullableText($row['version_id'] ?? null);
        $from = self::nullableText($row['effective_from'] ?? null);
        if (($versionId === null) !== ($from === null) || ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1)) {
            throw new RuntimeException('Invalid payroll pay rules evidence.');
        }
        $figures = self::map($row['figures'] ?? null);
        if (PayRules::error($figures) !== null) {
            throw new RuntimeException('Invalid payroll pay rules evidence.');
        }

        return ['scope' => self::text($row['scope'] ?? null), 'version_id' => $versionId, 'effective_from' => $from, 'figures' => $figures];
    }

    /**
     * A per-member peso map exactly as captured: members' ids only, finite,
     * never negative.
     *
     * @param  list<string>  $ids
     * @return array<string, float>
     */
    private static function moneyMap(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid payroll benefit evidence.');
        }

        $map = [];
        foreach ($value as $employeeId => $amount) {
            if (! is_string($employeeId) || ! in_array($employeeId, $ids, true)) {
                throw new RuntimeException('Invalid payroll benefit evidence.');
            }
            $number = self::number($amount);
            if ($number < 0) {
                throw new RuntimeException('Invalid payroll benefit evidence.');
            }
            $map[$employeeId] = $number;
        }

        return $map;
    }

    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Invalid payroll evidence text.');
        }

        return $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : self::text($value);
    }

    private static function number(mixed $value): float
    {
        if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
            throw new RuntimeException('Invalid payroll evidence number.');
        }

        return (float) $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException('Invalid payroll evidence flag.');
        }

        return $value;
    }

    /**
     * Stable map ordering; list order and null/zero/false distinctions are calculation inputs.
     *
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data): string
    {
        // Livewire transports 0.0 as 0; JSON numbers have the same calculation meaning.
        return json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new RuntimeException('Payroll evidence must contain plain calculation values.');
            }

            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
