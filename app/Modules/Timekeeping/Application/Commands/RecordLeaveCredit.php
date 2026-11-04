<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveEarningPosting;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Records HR's manual opening, accrual, carry-forward or correction and
 * changes the annual allocation atomically. Carry-forward is the amount HR
 * enters for the preceding year, never an automatic rollover calculation.
 * A dialog's locked ULID is an idempotency key: an identical retry succeeds
 * once, and reusing it for different data is refused.
 */
final class RecordLeaveCredit
{
    /** The kinds HR records by hand. @var array<string, string> */
    public const array KINDS = [
        'OPENING' => 'Opening balance',
        'ACCRUAL' => 'Manual accrual',
        'CARRY_FORWARD' => 'Carry forward',
        'ADJUSTMENT' => 'Adjustment',
    ];

    /** Every kind the ledger shows, the posted ones included. @var array<string, string> */
    public const array LABELS = [
        'BASELINE' => 'Existing allocation',
        'OPENING' => 'Opening balance',
        'ACCRUAL' => 'Accrual',
        'CARRY_FORWARD' => 'Carry forward',
        'ADJUSTMENT' => 'Adjustment',
        'CHARGE' => 'Attendance charge',
        'LAPSE' => 'Lapsed at year end',
        'CASH_OUT' => 'Cashed out',
    ];

    public function __construct(
        private readonly OperationalDirectory $employees,
        private readonly LeaveCreditRecords $records,
    ) {}

    public function handle(Access $actor, string $employeeId, int $year, string $requestType, string $kind, mixed $days, string $note, string $entryId): string
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
        if (! isset(self::KINDS[$kind])) {
            throw new RuntimeException('Choose a ledger entry type.');
        }
        if (! Str::isUlid($entryId)) {
            throw new RuntimeException('Reopen the ledger entry dialog and try again.');
        }
        $amount = $this->days($days, $kind);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new RuntimeException('Enter a note of 1 to 500 characters for this ledger entry.');
        }
        $name = EmployeeName::display($employee);

        $created = DB::transaction(function () use ($actor, $employeeId, $year, $requestType, $kind, $amount, $note, $entryId, $name): bool {
            LeaveCreditWriter::lock();
            $credit = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)
                ->where('request_type', $requestType)->lockForUpdate()->first();
            $existing = LeaveCreditEntry::query()->whereKey($entryId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->employee_id !== $employeeId || $existing->year !== $year
                    || $existing->request_type !== $requestType || $existing->kind !== $kind
                    || $existing->days !== $amount || $existing->note !== $note
                    || $existing->recorded_by !== (int) $actor->userId) {
                    throw new RuntimeException('This ledger entry was already saved with different details. Reopen the dialog.');
                }

                return false;
            }

            $types = $this->records->creditableTypes($actor);
            if (! isset($types[$requestType]) && $credit === null) {
                throw new RuntimeException('That leave type no longer exists.');
            }
            if ($kind === 'OPENING' && ($credit !== null || LeaveCreditWriter::hasHistory($employeeId, $year, $requestType))) {
                throw new RuntimeException('An allocation already exists for this leave type and year. Record an adjustment instead.');
            }
            // The earning job carries a closed year's whole balance forward
            // by itself (PostLeaveEarnings::closeYear); a second, typed carry
            // would count the same credits twice.
            if ($kind === 'CARRY_FORWARD' && LeavePolicyStore::ready()
                && LeaveEarningPosting::query()->where('employee_id', $employeeId)->where('period', ($year - 1).'-YE')->where('request_type', $requestType)->exists()) {
                throw new RuntimeException(($year - 1)."'s balance was already carried into {$year} automatically. Record an adjustment instead.");
            }
            $total = ($credit->credits ?? 0.0) + $amount;
            if ($total < 0 || $total > LeaveCreditPolicy::MAX_CREDITS) {
                throw new RuntimeException('This entry would put the annual allocation outside 0 to '.LeaveCreditPolicy::format(LeaveCreditPolicy::MAX_CREDITS).' days.');
            }
            if ($credit !== null) {
                LeaveCreditWriter::baseline($credit);
            }
            $credit ??= new LeaveCredit(['employee_id' => $employeeId, 'year' => $year, 'request_type' => $requestType]);
            $credit->credits = $total;
            if (! $credit->save()) {
                throw new RuntimeException('The leave allocation could not be saved.');
            }
            LeaveCreditWriter::append($actor, $employeeId, $year, $requestType, $kind, $amount, $note, $entryId);

            $description = $name.' · '.$year.' · '.($types[$requestType] ?? HrisFormat::requestDescription($requestType))
                .' · '.self::KINDS[$kind].' '.LeaveCreditPolicy::format($amount)
                .($kind === 'CARRY_FORWARD' ? ' from '.($year - 1) : '').' · '.$note;
            DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::LEAVE_CREDITS_SET, 'leave_credits', $employeeId, $description));

            return true;
        });

        return $created ? "Ledger entry recorded for {$name}." : "This ledger entry for {$name} was already recorded.";
    }

    private function days(mixed $value, string $kind): float
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_numeric($value)) {
            throw new RuntimeException(LeaveCreditPolicy::STEP_ERROR);
        }
        $amount = (float) $value;
        $error = LeaveCreditPolicy::creditError(abs($amount));
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        if ($amount === 0.0 || ($kind !== 'ADJUSTMENT' && $amount < 0)) {
            throw new RuntimeException($kind === 'ADJUSTMENT' ? 'Enter a nonzero adjustment in days.' : 'Enter a positive number of days.');
        }

        return $amount;
    }
}
