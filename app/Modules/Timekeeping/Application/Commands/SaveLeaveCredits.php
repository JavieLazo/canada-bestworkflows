<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Leave Management's "Set credits" dialog — one employee's leave credits for
 * one year, per Leave Settings type (leave_credits, 2026_09_26_0003). No
 * TypeScript counterpart: the reference has two numbers on the employee
 * record that nothing reads or writes down.
 *
 * The input is the dialog's number fields as they are: token => wire value
 * ('12.5', '' for "no credit", '0' for an explicit zero). The command
 * re-validates all of it — every token against the creditable Leave Settings
 * types or a token the employee already holds a credit under (so a stale one
 * can be cleared), every value through LeaveCreditPolicy — because the draft
 * is a wire:model property the client can shape at will. Tokens the caller
 * leaves out are left alone, so a partial map is a partial edit, not a wipe.
 *
 * Gate, resolve, validate, write, audit — the ReviewAbsence and
 * SaveRosterLocations shape. An all-data LEAVES holder may set anyone's
 * credits, their own included: one administrator must be able to run the
 * whole flow alone. The write takes the shared
 * credit sentinel before the employee's rows for the year, so it serializes
 * with RecordLeaveCredit even on a first allocation.
 * Each change also appends its opening or adjustment in the same transaction;
 * existing untracked allocations first receive an honest baseline. A save that
 * changes nothing writes nothing and audits nothing. The audit is best-effort
 * AuditService after commit, the policy every roster and leave write has;
 * widening RequiredAudit here would need its own decision.
 */
final class SaveLeaveCredits
{
    public function __construct(
        private readonly OperationalDirectory $employees,
        private readonly LeaveCreditRecords $records,
    ) {}

    /**
     * @param  array<array-key, mixed>  $credits  token => wire value; typed wide because it is wire input, and anything else is refused
     * @return string the toast sentence
     */
    public function handle(Access $actor, string $employeeId, int $year, array $credits): string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        $employee = $this->employees->find($actor, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('That employee no longer exists.');
        }
        if ($year < LeaveCreditPolicy::YEAR_MIN || $year > LeaveCreditPolicy::YEAR_MAX) {
            throw new RuntimeException(sprintf('Choose a year between %d and %d.', LeaveCreditPolicy::YEAR_MIN, LeaveCreditPolicy::YEAR_MAX));
        }

        $types = $this->records->creditableTypes($actor);
        $changed = DB::transaction(function () use ($actor, $employee, $year, $credits, $types): bool {
            LeaveCreditWriter::lock();
            $rows = LeaveCredit::query()
                ->where('employee_id', $employee->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->get()
                ->keyBy('request_type');
            $wanted = $this->validated($credits, $types, array_values($rows->keys()->all()));

            $changed = false;
            foreach ($wanted as $key => $days) {
                /** @var LeaveCredit|null $row */
                $row = $rows->get($key);
                if ($days === null) {
                    if ($row !== null) {
                        LeaveCreditWriter::baseline($row);
                        if ($row->credits !== 0.0) {
                            LeaveCreditWriter::append($actor, $employee->id, $year, $key, 'ADJUSTMENT', -$row->credits, 'Annual credit grant cleared in Set credits.');
                        }
                        if (! $row->delete()) {
                            throw new RuntimeException('The leave credits could not be cleared.');
                        }
                        $changed = true;
                    }

                    continue;
                }
                // Halves are exact in binary, so the float compare is the
                // DECIMAL(6,2) value read back.
                if ($row !== null && (float) $row->credits === $days) {
                    continue;
                }
                if ($row !== null) {
                    LeaveCreditWriter::baseline($row);
                }
                $hasHistory = LeaveCreditWriter::hasHistory($employee->id, $year, $key);
                $delta = $days - ($row->credits ?? 0.0);
                if (! $hasHistory || $delta !== 0.0) {
                    LeaveCreditWriter::append($actor, $employee->id, $year, $key, $hasHistory ? 'ADJUSTMENT' : 'OPENING', $delta, 'Annual credit grant entered in Set credits.');
                }
                $row ??= new LeaveCredit(['employee_id' => $employee->id, 'year' => $year, 'request_type' => $key]);
                $row->fill(['credits' => $days]);
                if (! $row->save()) {
                    throw new RuntimeException('The leave credits could not be saved.');
                }
                $changed = true;
            }

            return $changed;
        });

        $name = EmployeeName::display($employee);
        if (! $changed) {
            return "No changes to {$name}'s {$year} leave credits.";
        }

        AuditService::record(
            $actor,
            AuditEvent::LEAVE_CREDITS_SET,
            'leave_credits',
            $employee->id,
            $name.' · '.$year.' · '.$this->describe($actor, $employee->id, $year, $types),
        );

        return "{$name}'s {$year} leave credits saved.";
    }

    /**
     * The dialog's map as rows to write: token => days, or null for a blank
     * (delete the row). Throws on the first token that is neither a
     * creditable type nor one already held, and on the first value the
     * policy refuses — before anything is written.
     *
     * @param  array<array-key, mixed>  $credits
     * @param  array<string, string>  $types  token => name
     * @param  list<string>  $held  tokens the employee already holds a credit under this year
     * @return array<string, float|null>
     */
    private function validated(array $credits, array $types, array $held): array
    {
        $wanted = [];
        foreach ($credits as $key => $value) {
            if (! is_string($key) || (! isset($types[$key]) && ! in_array($key, $held, true))) {
                throw new RuntimeException('That leave type no longer exists.');
            }
            $error = LeaveCreditPolicy::creditError($value);
            if ($error !== null) {
                $label = $types[$key] ?? HrisFormat::requestDescription($key);
                throw new RuntimeException("{$label}: {$error}");
            }
            $wanted[$key] = LeaveCreditPolicy::credit($value);
        }

        return $wanted;
    }

    /**
     * The audit sentence's credits, read back after commit so it records
     * what STANDS: "Sick Leave 7.5 · Vacation Leave 15" — the creditable
     * types in Leave Settings (name) order, any stale token after them — or
     * "no leave credits" once no row is left.
     *
     * @param  array<string, string>  $types  token => name
     */
    private function describe(Access $actor, string $employeeId, int $year, array $types): string
    {
        $parts = [];
        foreach ($this->records->balances($actor, $employeeId, $year) as $balance) {
            if ($balance->credited === null) {
                continue;
            }
            $parts[] = ($types[$balance->request_type] ?? $balance->label).' '.LeaveCreditPolicy::format($balance->credited);
        }

        return $parts === [] ? 'no leave credits' : implode(' · ', $parts);
    }
}
