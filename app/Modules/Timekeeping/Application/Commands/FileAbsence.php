<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveBalanceView;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Owns filing across presentation surfaces without accepting an employee id from input. */
final class FileAbsence
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly LeaveCreditRecords $credits,
    ) {}

    public function handle(Access $actor, string $type, string $dayType, string $start, string $end, string $reason): void
    {
        if (! $actor->hasModule(ModuleCode::PORTAL)) {
            throw new RuntimeException('You do not have access to that.');
        }
        if (! $actor->hasEmployeeLink()) {
            throw new RuntimeException("Your account isn't linked to an employee record yet.");
        }
        $employeeId = $actor->ownEmployeeId();
        $type = trim($type);
        if ($type === '' || mb_strlen($type) > 60) {
            throw new RuntimeException('Choose a request type from Leave Settings.');
        }
        $dateError = AbsencePolicy::dateError($start, $end);
        if ($dateError !== null) {
            throw new RuntimeException($dateError);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 2000) {
            throw new RuntimeException('reason: String must contain at most 2000 character(s)');
        }
        if (! $this->employees->isActiveForAbsence($actor)) {
            throw new RuntimeException('Choose an active employee for the request.');
        }
        $supported = [AbsencePolicy::OFFICIAL_BUSINESS => true];
        foreach (LeaveType::query()->pluck('name') as $name) {
            $supported[HrisFormat::normalizeRequestType((string) $name)] = true;
        }
        if (! isset($supported[$type])) {
            throw new RuntimeException('Choose a request type from Leave Settings.');
        }
        $dayType = array_key_exists($dayType, AbsencePolicy::DAY_TYPES) ? $dayType : 'WHOLE_DAY';

        $created = DB::transaction(function () use ($actor, $employeeId, $type, $dayType, $start, $end, $reason): LeaveTravelRequest {
            // Every read before the credit lock must be a CURRENT READ.
            // An ordinary eager load would freeze MariaDB's repeatable-read
            // snapshot before a concurrent credit reduction or deletion commits.
            $rows = LeaveTravelRequest::query()->with([
                'cancellations' => static fn ($query) => $query
                    ->select(['id', 'leave_travel_request_id', 'date'])->lockForUpdate(),
            ])->lockForUpdate()->get(['id', 'code', 'employee_id', 'status', 'start_date', 'end_date']);
            foreach ($rows as $row) {
                if ((string) $row->employee_id !== $employeeId || ! in_array($row->status, ['PENDING', 'APPROVED'], true)) {
                    continue;
                }
                if (LeaveCreditPolicy::hasActiveDate(
                    max($row->start_date->format('Y-m-d'), $start),
                    min($row->end_date->format('Y-m-d'), $end),
                    $row->cancelledDates(),
                )) {
                    throw new RuntimeException("These dates overlap request {$row->code}. Resolve that request before filing another.");
                }
            }

            if (LeaveCreditPolicy::isLeave($type)) {
                $year = LeaveCreditPolicy::yearOf($start);
                // Request rows are already locked against concurrent review.
                // Lock this employee's credits next so a saved reduction or
                // deletion is seen before filing, and cannot race this insert.
                LeaveCredit::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
                    ->where('employee_id', '=', $employeeId)->where('year', $year)
                    ->lockForUpdate()->get(['id']);
                $balances = $this->credits->own($actor, $year);
                $key = LeaveCreditPolicy::resolveKey($type,
                    array_values($balances->map(static fn (LeaveBalanceView $row): string => $row->request_type)->all()));
                $balance = $key === null ? null : $balances->first(static fn (LeaveBalanceView $row): bool => $row->request_type === $key);
                $error = LeaveCreditPolicy::filingError(
                    $balance?->remaining,
                    LeaveCreditPolicy::coveredDays($dayType, $start, $end),
                    $year,
                );
                if ($error !== null) {
                    throw new RuntimeException($error);
                }
            }

            return LeaveTravelRequest::query()->create([
                'code' => CodeSequence::next('LR', $rows->pluck('code')),
                'employee_id' => $employeeId,
                'request_type' => $type,
                'day_type' => $dayType,
                'start_date' => $start,
                'end_date' => $end,
                'reason' => $reason !== '' ? $reason : null,
                'status' => 'PENDING',
            ]);
        });

        AuditService::record($actor, AuditEvent::REQUEST_FILED, 'leave_travel_requests', (string) $created->id,
            "Request {$created->code} filed {$start} \u{2192} {$end}");
        Notifier::moduleHolders(ModuleCode::LEAVES, $actor->userId, 'LEAVE_FILED',
            "Request {$created->code} filed by {$actor->fullName}", HrisFormat::requestDescription($type)." \u{00B7} ".$start, '/leaves');
    }
}
