<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Attendance\AttendanceLog;
use App\Domain\Attendance\HolidaySetting;
use App\Domain\Attendance\LeaveRequest;
use App\Domain\Attendance\Shift;
use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Modules\Payroll\Domain\DeductionCalculation;
use App\Modules\Payroll\Domain\Deductions\PercentDeduction;
use App\Modules\Timekeeping\Contracts\AttendanceInputs;
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
 */
final readonly class PayrollInputs
{
    public const int SCHEMA_VERSION = 9;

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
     * @param  array<string, array{scope: string, version_id: ?string, effective_from: ?string, figures: array<string, mixed>}>  $payRules  the Pay Basis & Rates version pricing each member, keyed by employee id (absent: the reference's rules over `formulas`)
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $benefitRules  the catalog's scheduled benefits as captured, keyed by type id — what payroll proposes on its own
     * @param  array<string, array{code: string, name: string, figures: array<string, mixed>}>  $deductionRules  the Payroll Deductions rows priced as a percentage, as captured, keyed by row id
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
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** The Pay Basis & Rates a member is priced under: the captured version, or the reference's rules over the captured Formulas rows. */
    public function rulesFor(string $employeeId): PayRules
    {
        $entry = $this->payRules[$employeeId] ?? null;

        return $entry === null ? PayRules::fromFormulas($this->formulas) : PayRulesStore::rulesFor($entry, $this->formulas);
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
            $facts = $this->attendance[$id];
            $s = $facts->shift;
            $attendance[$id] = [
                'logs' => array_map(static fn (AttendanceLog $log): array => [
                    'id' => $log->id, 'employee_id' => $log->employeeId,
                    'log_type' => $log->logType, 'logged_at' => $log->loggedAt,
                ], $facts->logs),
                'shift' => $s?->toArray(),
                'leaves' => array_map(fn (LeaveRequest $leave): array => [
                    'id' => $leave->id, 'employee_id' => $leave->employeeId, 'request_type' => $leave->requestType,
                    'start_date' => $leave->startDate, 'end_date' => $leave->endDate, 'status' => $leave->status,
                    'is_paid' => $leave->isPaid, 'day_type' => $leave->dayType,
                    ...($this->schemaVersion >= 7 ? ['cancelled_dates' => $leave->cancelledDates] : []),
                ], $facts->leaves),
                // The Daily Time Record's decisions: date => approved minutes.
                'overtime_approvals' => $facts->overtimeApprovals,
            ];
        }

        return [
            'schema_version' => $this->schemaVersion, 'calculator_version' => $this->calculatorVersion,
            'employee_ids' => $this->employeeIds, 'period_start' => $this->periodStart, 'period_end' => $this->periodEnd,
            'employees' => array_map(self::employeeToArray(...), $this->employees),
            'attendance' => $attendance, 'formulas' => $this->formulas,
            'holidays' => array_map(static fn (HolidaySetting $h): array => get_object_vars($h), $this->holidays),
            'deductions' => $this->deductions, 'allowances' => $this->allowances, 'earnings' => $this->earnings,
            // Older evidence carries no tax category on a line: its flag is the whole category.
            'incentives' => $this->schemaVersion >= 9 ? $this->incentives : array_map(static fn (array $lines): array => array_map(static fn (array $line): array => array_diff_key($line, ['tax' => true, 'counts_toward_13th' => true]), $lines), $this->incentives),
            'exempt_benefits_ytd' => $this->exemptBenefitsYearToDate,
            'custom_deductions' => $this->customDeductions,
            'active_calculations' => $this->activeCalculations,
            ...($this->schemaVersion >= 8 ? ['statutory_tables' => $this->statutoryTables, 'pay_rules' => $this->payRules] : []),
            ...($this->schemaVersion >= 9 ? ['benefit_rules' => $this->benefitRules, 'deduction_rules' => $this->deductionRules] : []),
        ];
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
        if (! in_array($schemaVersion, [6, 7, 8, self::SCHEMA_VERSION], true)) {
            throw new RuntimeException('Unsupported payroll evidence schema.');
        }
        $employees = array_map(self::employeeFromArray(...), self::rows($data['employees'] ?? null));
        $attendance = [];
        foreach (self::map($data['attendance'] ?? null) as $id => $value) {
            $row = self::map($value);
            $attendance[$id] = new AttendanceInputs(
                AttendanceLog::listFromArrays(self::rows($row['logs'] ?? null)),
                ($row['shift'] ?? null) === null ? null : Shift::fromArray(self::map($row['shift'])),
                LeaveRequest::listFromArrays(self::rows($row['leaves'] ?? null)),
                self::minutesMap($row['overtime_approvals'] ?? null),
            );
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
            $schemaVersion >= 8 ? self::payRuleMap($data['pay_rules'] ?? null, $ids) : [],
            $schemaVersion >= 9 ? self::benefitRuleMap($data['benefit_rules'] ?? null) : [],
            $schemaVersion >= 9 ? self::deductionRuleMap($data['deduction_rules'] ?? null) : [],
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
        ];
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
        );
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
                if ($amount <= 0) {
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
    private static function payRuleMap(mixed $value, array $ids): array
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
            if ($keys !== ['effective_from', 'figures', 'scope', 'version_id']) {
                throw new RuntimeException('Invalid payroll pay rules evidence.');
            }
            $versionId = self::nullableText($row['version_id']);
            $from = self::nullableText($row['effective_from']);
            if (($versionId === null) !== ($from === null) || ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1)) {
                throw new RuntimeException('Invalid payroll pay rules evidence.');
            }
            $figures = self::map($row['figures']);
            if (PayRules::error($figures) !== null) {
                throw new RuntimeException('Invalid payroll pay rules evidence.');
            }
            $map[$employeeId] = ['scope' => self::text($row['scope']), 'version_id' => $versionId, 'effective_from' => $from, 'figures' => $figures];
        }

        return $map;
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
