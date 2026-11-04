<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Domain\Enums\PayrollRunKind;
use App\Domain\Payroll\Statutory;
use App\Domain\Payroll\StatutorySchedule;
use App\Modules\Workforce\Contracts\EmployeePayData;
use RuntimeException;

/**
 * The detached inputs of one 13th month run's computation, serializable
 * without re-reading a source record — the yearly run's counterpart of
 * PayrollInputs, with its own schema.
 *
 * Per member: the basic pay EMS's finalized regular payslips paid that year
 * (ThirteenthMonthBasis), the taxable pay of the latest of them (the base of
 * the marginal tax on the excess), the basic pay typed as earned outside
 * EMS, a typed withholding figure if the officer overtyped one, and the
 * tax-exempt benefits already posted that year (ExemptBenefitLedger). The
 * employees are captured exactly as PayrollInputs captures them. Everything
 * is in the fingerprint: a regular payroll finalized, an outside figure
 * typed or a statutory switch flipped since a preview asks for a fresh
 * review.
 */
final readonly class ThirteenthMonthInputs
{
    /**
     * Schema 2: each captured employee carries `daily_rate`
     * (PayrollInputs::employeeToArray, shared), which schema-1 evidence lacks.
     * Schema 3 captures the withholding table version in force on 31
     * December of the year (`withholding_table`, the shape PayrollInputs
     * captures the four tables in), so a new version stales the preview and
     * a replay never reads the live tables; schema-2 evidence restores with
     * the built-in figures, which are what those runs computed from.
     */
    public const int SCHEMA_VERSION = 5;

    /**
     * @param  list<string>  $employeeIds
     * @param  list<EmployeePayData>  $employees
     * @param  array<string, float>  $basicPay  employee id => EMS basic pay in the year
     * @param  array<string, float|null>  $regularTaxable  employee id => taxable pay of the latest regular payslip, or null
     * @param  array<string, float>  $outsideBasicPay  employee id => typed basic pay earned outside EMS (absent: none)
     * @param  array<string, float>  $withholdingOverrides  employee id => typed withholding (absent: computed)
     * @param  array<string, float>  $exemptBenefitsYearToDate  employee id => exempt pesos already posted in the year
     * @param  array<string, array{version_id: ?string, effective_from: ?string, covered_through: ?string, figures: array<string, mixed>}>  $withholdingTable  the withholding table in force, keyed by its kind (empty: the built-in figures)
     */
    public function __construct(
        public array $employeeIds,
        public int $year,
        public array $employees,
        public array $basicPay,
        public array $regularTaxable,
        public array $outsideBasicPay,
        public array $withholdingOverrides,
        public array $exemptBenefitsYearToDate,
        public string $calculatorVersion,
        public array $withholdingTable = [],
        /**
         * The yearly tax-free ceiling in force on 31 December, as captured
         * (schema 4); null = the constant the engine carried.
         *
         * @var array{version_id: string, effective_from: string, ceiling: float}|null
         */
        public ?array $benefitCeiling = null,
        private int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /** The pesos of exempt benefits and 13th month pay the year may hold tax-free. */
    public function ceiling(): float
    {
        return BenefitCeilings::ceilingOf($this->benefitCeiling);
    }

    /** The figures the excess is taxed from: the captured withholding table, or the built-in figures. */
    public function schedule(): StatutorySchedule
    {
        $table = $this->withholdingTable[StatutorySchedule::WITHHOLDING_TAX] ?? null;

        return $table === null ? Statutory::builtIn() : Statutory::builtIn()->with(StatutorySchedule::WITHHOLDING_TAX, $table['figures']);
    }

    public function periodStart(): string
    {
        return sprintf('%04d-01-01', $this->year);
    }

    public function periodEnd(): string
    {
        return sprintf('%04d-12-31', $this->year);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion, 'kind' => PayrollRunKind::THIRTEENTH_MONTH,
            'calculator_version' => $this->calculatorVersion, 'year' => $this->year,
            'employee_ids' => $this->employeeIds,
            // The employee row as this schema carried it: schema 4 added the salary segments, schema 5 the whole-year keys (the payroll evidence's 19 and 22).
            'employees' => array_map(fn (EmployeePayData $e): array => PayrollInputs::employeeRowForSchema(PayrollInputs::employeeToArray($e), $this->schemaVersion < 4 ? 18 : ($this->schemaVersion < 5 ? 21 : 22)), $this->employees),
            'basic_pay' => $this->basicPay, 'regular_taxable' => $this->regularTaxable,
            'outside_basic_pay' => $this->outsideBasicPay, 'withholding_overrides' => $this->withholdingOverrides,
            'exempt_benefits_ytd' => $this->exemptBenefitsYearToDate,
            ...($this->schemaVersion >= 3 ? ['withholding_table' => $this->withholdingTable] : []),
            ...($this->schemaVersion >= 4 ? ['benefit_ceiling' => $this->benefitCeiling] : []),
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', PayrollInputs::json($this->toArray()));
    }

    /** Restores each supported schema's exact shape; it never loads live source records. */
    public static function restore(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $schemaVersion = is_array($data) ? ($data['schema_version'] ?? null) : null;
        if (! is_array($data) || ! in_array($schemaVersion, [2, 3, 4, self::SCHEMA_VERSION], true) || ($data['kind'] ?? null) !== PayrollRunKind::THIRTEENTH_MONTH) {
            throw new RuntimeException('Unsupported 13th month evidence schema.');
        }
        if (! is_int($data['year'] ?? null) || ! is_string($data['calculator_version'] ?? null)
            || ! is_array($data['employee_ids'] ?? null) || ! is_array($data['employees'] ?? null)) {
            throw new RuntimeException('Invalid 13th month evidence structure.');
        }

        $ids = [];
        foreach ($data['employee_ids'] as $id) {
            if (! is_string($id)) {
                throw new RuntimeException('Invalid 13th month evidence structure.');
            }
            $ids[] = $id;
        }
        $employees = [];
        foreach ($data['employees'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid 13th month evidence structure.');
            }
            $employees[] = PayrollInputs::employeeFromArray($row);
        }

        $inputs = new self(
            $ids, $data['year'], $employees,
            self::money($data['basic_pay'] ?? null, $ids),
            self::nullableMoney($data['regular_taxable'] ?? null, $ids),
            self::money($data['outside_basic_pay'] ?? null, $ids),
            self::money($data['withholding_overrides'] ?? null, $ids),
            self::money($data['exempt_benefits_ytd'] ?? null, $ids),
            $data['calculator_version'],
            $schemaVersion >= 3 ? PayrollInputs::statutoryTableMap($data['withholding_table'] ?? null, [StatutorySchedule::WITHHOLDING_TAX]) : [],
            $schemaVersion >= 4 ? PayrollInputs::benefitCeilingEntry($data['benefit_ceiling'] ?? null) : null,
            $schemaVersion,
        );
        // Reject missing, coerced or unknown values rather than silently changing saved evidence.
        if (PayrollInputs::json($inputs->toArray()) !== PayrollInputs::json($data)) {
            throw new RuntimeException('13th month evidence does not match its recorded schema.');
        }

        return $inputs;
    }

    /**
     * A per-member peso map: members' ids only, finite, never negative.
     *
     * @param  list<string>  $ids
     * @return array<string, float>
     */
    private static function money(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid 13th month evidence structure.');
        }
        $map = [];
        foreach ($value as $id => $amount) {
            if (! is_string($id) || ! in_array($id, $ids, true) || (! is_int($amount) && ! is_float($amount)) || ! is_finite((float) $amount) || $amount < 0) {
                throw new RuntimeException('Invalid 13th month evidence figure.');
            }
            $map[$id] = (float) $amount;
        }

        return $map;
    }

    /**
     * The same, where a member may carry null (no regular payslip that year).
     *
     * @param  list<string>  $ids
     * @return array<string, float|null>
     */
    private static function nullableMoney(mixed $value, array $ids): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Invalid 13th month evidence structure.');
        }
        $map = [];
        foreach ($value as $id => $amount) {
            if ($amount === null && is_string($id) && in_array($id, $ids, true)) {
                $map[$id] = null;

                continue;
            }
            $map += self::money([$id => $amount], $ids);
        }

        return $map;
    }
}
