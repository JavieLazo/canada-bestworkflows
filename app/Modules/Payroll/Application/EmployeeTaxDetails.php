<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application;

use App\Models\PayrollEmployeeTaxDetail;
use App\Modules\Payroll\Domain\Canada\TaxDetails;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's tax details as payroll reads them: the saved row, or the
 * starting position for an employee who has none.
 *
 * A system read with no actor, for a payroll's capture and for the commands
 * that have already authorized their caller; a screen reads through
 * `Queries\EmployeeTaxDetailRecords`, which checks the actor.
 */
final class EmployeeTaxDetails
{
    /** Whether the table exists yet: files reach a host before its migration runs. */
    public static function ready(): bool
    {
        return once(static fn (): bool => Schema::hasTable('payroll_employee_tax_details'));
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, TaxDetails> every id asked for, in the order given
     */
    public static function forEmployees(array $employeeIds): array
    {
        $rows = self::ready() && $employeeIds !== []
            ? PayrollEmployeeTaxDetail::query()->whereIn('employee_id', $employeeIds)->get()->keyBy('employee_id')
            : collect();

        $out = [];
        foreach ($employeeIds as $id) {
            $row = $rows->get($id);
            $out[$id] = $row === null ? TaxDetails::starting() : self::fromRow($row);
        }

        return $out;
    }

    public static function forEmployee(string $employeeId): TaxDetails
    {
        return self::forEmployees([$employeeId])[$employeeId];
    }

    public static function fromRow(PayrollEmployeeTaxDetail $row): TaxDetails
    {
        return new TaxDetails(
            $row->province,
            $row->federal_claim,
            $row->provincial_claim,
            $row->additional_tax,
            $row->deduct_pension,
            $row->deduct_insurance,
        );
    }
}
