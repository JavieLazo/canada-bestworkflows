<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Application\Commands;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Domain\Notifications\Notifier;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Modules\Timekeeping\Application\LeaveApplicationDetails;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveHalves;
use App\Modules\Timekeeping\Application\LeavePay;
use App\Modules\Timekeeping\Application\LeaveRules;
use App\Modules\Timekeeping\Domain\Absence\AbsencePolicy;
use App\Modules\Timekeeping\Domain\Absence\LeaveApplication;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Workforce\Contracts\OperationalDirectory;
use App\Support\Access;
use App\Support\AuditService;
use App\Support\CodeSequence;
use App\Support\EmployeeName;
use App\Support\HrisFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns filing across presentation surfaces without accepting an employee id
 * from input. A SHORT BALANCE NEVER REFUSES A FILING: the request is filed,
 * and the sentence returned tells whoever filed it which days the credits —
 * or the leave type's set days — do not cover and will be without pay if it
 * is approved, and which would be paid by taking the credits below zero
 * where the type's limit allows it (LeavePay, the reading My Portal's hint
 * and the approval share). The refusals are the leave type's and the leave
 * rule's (LeaveRules::filing): a type the employee may not use is not filed
 * at all, nor for a date before they became eligible (LeaveEligibilities),
 * and a type with a waiting period is not filed for a date before the
 * employee acquired it.
 *
 * A LEAVE ALSO CARRIES THE APPLICATION'S DETAILS (LeaveApplication): the
 * details of leave its type asks for — required where it asks, with the
 * text a choice says to specify — and whether commutation is requested
 * (not requested unless said). Both are recorded only; official business
 * carries neither. The illness a sick leave names is kept on the request
 * alone, never in the audit sentence or a notice.
 */
final class FileAbsence
{
    public function __construct(
        private readonly EmployeeDirectory $employees,
        private readonly OperationalDirectory $directory,
        private readonly LeavePay $pay,
    ) {}

    /**
     * Self-service: the actor's own employee, from My Portal.
     *
     * @return string|null what the request will not be paid, or null when it is paid whole
     */
    public function handle(Access $actor, string $type, string $dayType, string $start, string $end, string $reason, ?string $half = null, ?string $detail = null, ?string $detailNote = null, ?string $commutation = null): ?string
    {
        if (! $actor->hasModule(ModuleCode::PORTAL)) {
            throw new RuntimeException('You do not have access to that.');
        }
        if (! $actor->hasEmployeeLink()) {
            throw new RuntimeException("Your account isn't linked to an employee record yet.");
        }
        if (! $this->employees->isActiveForAbsence($actor)) {
            throw new RuntimeException('Choose an active employee for the request.');
        }

        return $this->file($actor, $actor->ownEmployeeId(), $type, $dayType, $start, $end, $reason, $half, [$detail, $detailNote, $commutation], null);
    }

    /**
     * On an employee's behalf, from Leave Management: a leave manager with
     * all-data LEAVES files for any ACTIVE employee but their own (their
     * own goes through My Portal), any dates — past ones too, as HR
     * back-files a request brought in on paper. The same checks and overlap
     * rule as a self-service filing; the audit and the notice name who
     * filed for whom.
     *
     * @return string|null what the request will not be paid, or null when it is paid whole
     */
    public function forEmployee(Access $actor, string $employeeId, string $type, string $dayType, string $start, string $end, string $reason, ?string $half = null, ?string $detail = null, ?string $detailNote = null, ?string $commutation = null): ?string
    {
        if (! $actor->mayReadAllData(ModuleCode::LEAVES)) {
            throw new RuntimeException('Your role cannot manage organization-wide data.');
        }
        if ($actor->employeeId !== null && $employeeId === $actor->employeeId) {
            throw new RuntimeException('File your own leave from My Portal.');
        }
        $employee = $this->directory->find($actor, $employeeId);
        if ($employee === null || ! $employee->is_active) {
            throw new RuntimeException('Choose an active employee for the request.');
        }

        return $this->file($actor, $employeeId, $type, $dayType, $start, $end, $reason, $half, [$detail, $detailNote, $commutation], EmployeeName::display($employee));
    }

    /**
     * The filing itself: `$forName` is the employee's name when a manager files on their behalf, null for self-service.
     *
     * @param  array{0: string|null, 1: string|null, 2: string|null}  $application  the details of leave picked, the text it specifies, and the commutation
     */
    private function file(Access $actor, string $employeeId, string $type, string $dayType, string $start, string $end, string $reason, ?string $half, array $application, ?string $forName): ?string
    {
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
        $supported = [AbsencePolicy::OFFICIAL_BUSINESS => true];
        foreach (LeaveType::query()->pluck('name') as $name) {
            $supported[HrisFormat::normalizeRequestType((string) $name)] = true;
        }
        if (! isset($supported[$type])) {
            throw new RuntimeException('Choose a request type from Leave Settings.');
        }
        $dayType = array_key_exists($dayType, AbsencePolicy::DAY_TYPES) ? $dayType : 'WHOLE_DAY';
        // Which half is off, only on a half day and only once the column exists (divergence 21).
        $half = $dayType === 'HALF_DAY' && LeaveHalves::ready() ? LeaveHalves::of($half) : null;
        // The details of leave the type asks for and the commutation: a
        // leave only, and only once the columns exist.
        $kept = [];
        if (LeaveCreditPolicy::isLeave($type) && LeaveApplicationDetails::ready()) {
            [$detail, $detailNote, $detailError] = LeaveApplication::details(LeaveApplicationDetails::section($type), $application[0], $application[1]);
            if ($detailError !== null) {
                throw new RuntimeException($detailError);
            }
            $commutation = LeaveApplication::commutation($application[2]);
            if ($commutation === null) {
                throw new RuntimeException('Choose whether commutation is requested.');
            }
            $kept = ['leave_detail' => $detail, 'leave_detail_note' => $detailNote, 'commutation' => $commutation];
        }

        $notice = null;
        $created = DB::transaction(function () use ($actor, $employeeId, $type, $dayType, $start, $end, $reason, $half, $kept, $forName, &$notice): LeaveTravelRequest {
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
                // The year the request would draw on: its start year, moved
                // past every year the earning job has closed (LeaveBalances).
                $year = LeaveBalances::liveYear($employeeId, $start);
                // Request rows are already locked against concurrent review.
                // Lock this employee's credits next so the notice reads the
                // credits a concurrent save leaves, not the ones before it.
                LeaveCredit::query()->toEmployee($actor, 'employee_id', ModuleCode::LEAVES)
                    ->where('employee_id', '=', $employeeId)->where('year', $year)
                    ->lockForUpdate()->get(['id']);
                // A type the employee may not use is refused, and so is a
                // type that waits before the day the employee acquired it.
                // A short balance refuses nothing: the
                // request is filed, and whoever filed it is told which days
                // would be without pay (LeavePay).
                $plan = $this->pay->plan($employeeId, $type, $dayType, $start, $end);
                if ($plan['refusal'] !== null) {
                    throw new RuntimeException($plan['refusal']);
                }
                $sentences = array_filter([LeavePay::notice($plan, own: $forName === null), LeavePay::belowZero($plan, own: $forName === null)]);
                $notice = $sentences === [] ? null : implode(' ', $sentences);
            }

            return LeaveTravelRequest::query()->create([
                'code' => CodeSequence::next('LR', $rows->pluck('code')),
                'employee_id' => $employeeId,
                'request_type' => $type,
                'day_type' => $dayType,
                ...(LeaveHalves::ready() ? ['half' => $half] : []),
                'start_date' => $start,
                'end_date' => $end,
                'reason' => $reason !== '' ? $reason : null,
                ...$kept,
                'status' => 'PENDING',
            ]);
        });

        $who = $forName === null ? '' : " for {$forName}";
        AuditService::record($actor, AuditEvent::REQUEST_FILED, 'leave_travel_requests', (string) $created->id,
            "Request {$created->code} filed{$who} {$start} \u{2192} {$end}");
        Notifier::moduleHolders(ModuleCode::LEAVES, $actor->userId, 'LEAVE_FILED',
            "Request {$created->code} filed{$who} by {$actor->fullName}", HrisFormat::requestDescription($type)." \u{00B7} ".$start, '/leaves');

        return $notice;
    }
}
