<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\PayrollDeduction;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRecurringLine;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\RecurringLineLedger;
use App\Modules\Payroll\Application\RecurringLines;
use App\Modules\Payroll\Domain\Recurring\RecurringLine;
use App\Modules\Workforce\Contracts\EmployeePayContexts;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds or changes one employee's recurring line (RecurringLine): a typed
 * Payroll Deductions row — never a computed or percentage one, which price
 * themselves — or an Incentives & Bonuses type the catalog does not
 * schedule on its own, with its amount per payroll, which payrolls, its
 * dates and a deduction's total to pay off.
 *
 * An employee has at most one live line per catalog row at a time (dates
 * that overlap a line not yet paid off are refused), because a payroll
 * carries one amount per row. A line already paid on a payslip keeps its
 * employee, catalog and kind, and its total never drops below what it has
 * paid: the ledger is the posted payslips. Every draft payroll it reaches
 * proposes it on its next preview and asks for a fresh review; finalized
 * payrolls keep what they paid.
 *
 * Pay data: an all-data PAYROLL write, audited with RequiredAudit on the
 * same transaction (the catalog row and the note, never the amounts, as
 * EMPLOYEE_UPDATED never names them).
 */
final class SaveRecurringLine
{
    public const int NOTE_MAX = 160;

    /**
     * @param  array<string, mixed>  $figures  RecurringLine's shape: kind, amount, total, frequency, starts_on, ends_on
     */
    public function handle(Access $access, ?string $lineId, string $employeeId, string $typeId, array $figures, string $note): string
    {
        if (! $access->mayReadAllData(ModuleCode::PAYROLL)) {
            throw new RuntimeException('Your role cannot manage employee pay settings.');
        }
        if (! RecurringLines::ready()) {
            throw new RuntimeException('Loans and allowances can be set up once this installation\'s upgrade finishes.');
        }
        $employee = app(EmployeePayContexts::class)->get($access, [$employeeId])[0] ?? null;
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        $error = RecurringLine::error($figures);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $rule = RecurringLine::fromArray($figures);
        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new RuntimeException('The note must be at most 160 characters.');
        }
        $typeName = self::catalogName($rule->kind, $typeId);
        $name = EmployeeName::display($employee);

        return DB::transaction(static function () use ($access, $lineId, $employee, $name, $typeId, $typeName, $rule, $note): string {
            // The employee's lines, locked: two saves cannot both find no overlap.
            $lines = PayrollRecurringLine::query()->where('employee_id', $employee->id)->lockForUpdate()->get();
            $row = null;
            if ($lineId !== null) {
                $row = $lines->firstWhere('id', $lineId);
                if ($row === null) {
                    throw new RuntimeException('That loan or allowance no longer exists.');
                }
            }
            $paid = app(RecurringLineLedger::class)->paidBefore($access, array_values($lines->map(static fn (PayrollRecurringLine $line): string => (string) $line->id)->all()));
            if ($row !== null && RecurringLines::posted($row->id)) {
                if ($row->kind !== $rule->kind || ($row->deduction_id ?? $row->incentive_type_id) !== $typeId) {
                    throw new RuntimeException('A line already paid on a payslip keeps what it charges. End it, and add a new line for the other one.');
                }
                if ($rule->total !== null && Money::round($rule->total) < ($paid[$row->id] ?? 0.0)) {
                    throw new RuntimeException('The total loan payable cannot be less than the '.PayrollData::peso($paid[$row->id] ?? 0.0).' already paid.');
                }
                if ($rule->total === null && $row->total !== null) {
                    throw new RuntimeException('A line already paid on a payslip keeps its total. Change the total, or end the line.');
                }
            }
            foreach ($lines as $other) {
                if ($other->id === $row?->id || $other->kind !== $rule->kind || ($other->deduction_id ?? $other->incentive_type_id) !== $typeId) {
                    continue;
                }
                $otherRule = RecurringLines::rule($other);
                if ($otherRule->paidOff($paid[$other->id] ?? 0.0)) {
                    continue;
                }
                $overlap = ($rule->endsOn === null || $otherRule->startsOn <= $rule->endsOn) && ($otherRule->endsOn === null || $rule->startsOn <= $otherRule->endsOn);
                if ($overlap) {
                    throw new RuntimeException("{$name} already has a recurring {$typeName} in those dates. End that line first, or change it.");
                }
            }

            $isNew = $row === null;
            $row ??= new PayrollRecurringLine;
            $row->fill([
                'employee_id' => $employee->id,
                'kind' => $rule->kind,
                'deduction_id' => $rule->kind === RecurringLine::KIND_DEDUCTION ? $typeId : null,
                'incentive_type_id' => $rule->kind === RecurringLine::KIND_INCENTIVE ? $typeId : null,
                'amount' => $rule->amount,
                'total' => $rule->total,
                'frequency' => $rule->frequency,
                'starts_on' => $rule->startsOn,
                'ends_on' => $rule->endsOn,
                'note' => $note === '' ? null : $note,
            ]);
            if ($isNew) {
                $row->created_by = $access->userId === null ? null : (int) $access->userId;
                $row->created_by_name = mb_substr($access->fullName, 0, 200);
            }
            $row->save();

            $kind = $rule->kind === RecurringLine::KIND_DEDUCTION ? 'deduction' : 'incentive';
            RequiredAudit::record($access, AuditEvent::RECURRING_LINE_SAVED, 'payroll_recurring_lines', $row->id,
                "{$name} — recurring {$kind} {$typeName} ".($isNew ? 'added' : 'changed').($note === '' ? '' : " ({$note})"));

            return $isNew ? "Recurring {$typeName} added for {$name}." : "Recurring {$typeName} saved for {$name}.";
        });
    }

    /** The catalog row's name, refusing a row a recurring line cannot charge. */
    private static function catalogName(string $kind, string $typeId): string
    {
        if ($kind === RecurringLine::KIND_DEDUCTION) {
            $row = PayrollDeduction::query()->find($typeId);
            if ($row === null) {
                throw new RuntimeException('Choose the deduction it charges.');
            }
            if ($row->calculation !== null || (PayrollData::percentDeductionsReady() && $row->getAttribute('percent') !== null)) {
                throw new RuntimeException("{$row->name} is worked out by payroll itself. Choose a deduction that is typed on each payroll.");
            }

            return (string) $row->name;
        }
        $type = PayrollIncentiveType::query()->find($typeId);
        if ($type === null) {
            throw new RuntimeException('Choose the incentive it pays.');
        }
        if (PayrollData::benefitRuleOf($type)->isScheduled()) {
            throw new RuntimeException("{$type->name} is already proposed by the catalog for everyone who qualifies. Choose a type that is typed on each payroll.");
        }

        return (string) $type->name;
    }
}
