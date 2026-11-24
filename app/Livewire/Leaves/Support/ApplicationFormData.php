<?php

declare(strict_types=1);

namespace App\Livewire\Leaves\Support;

use App\Livewire\Portal\Support\LeaveFormText;
use App\Livewire\Portal\Support\PortalLabels;
use App\Modules\Organization\Application\Queries\StructureRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveFormRecords;
use App\Modules\Timekeeping\Application\Queries\LeavePayRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveSignatoryRecords;
use App\Modules\Timekeeping\Contracts\Views\LeaveSignatoryView;
use App\Modules\Timekeeping\Contracts\Views\LeaveTravelRequestView;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use App\Support\HrisFormat;
use App\Support\LocalClock;

/**
 * What the Application for Leave shows, for the two screens that draw it: My
 * Portal, for the session's own employee, and Leave Management, for the
 * employee of the request under review. Presentation only: the owners'
 * queries decide what the actor may read.
 *
 * THE SALARY FOLLOWS THE RECORD'S OWN RULE. It is read through
 * EmployeeRecords::find, so a leave manager who cannot open that employee's
 * record through Employee Records or Payroll sees a blank salary, however
 * much of the form they read.
 */
final class ApplicationFormData
{
    private function __construct() {}

    /**
     * The form's lines for an account with no employee record.
     *
     * @return array{office: string, last: string, first: string, middle: string, position: string, salary: string, filed: string, receipt: string, head: array{name: string, designation: string}|null}
     */
    public static function blank(): array
    {
        return ['office' => '', 'last' => '', 'first' => '', 'middle' => '', 'position' => '', 'salary' => '', 'filed' => PortalLabels::fmtDate(PortalLabels::todayKey()), 'receipt' => '', 'head' => null];
    }

    /**
     * Sections 1 to 5 and the head of office, blank where the record has
     * nothing. A daily-paid employee's rate says so, because the form's
     * salary line is otherwise read as monthly.
     *
     * @return array{office: string, last: string, first: string, middle: string, position: string, salary: string, filed: string, receipt: string, head: array{name: string, designation: string}|null}
     */
    public static function applicant(Access $actor, string $employeeId): array
    {
        $records = app(EmployeeRecords::class);
        $identity = $records->applicantIdentity($actor, $employeeId);
        if ($identity === null) {
            return self::blank();
        }
        $record = $records->find($actor, $employeeId);

        return [
            'office' => implode(' · ', array_filter([(string) $identity['department_name'], (string) $identity['unit_name']])),
            'last' => trim($identity['last_name'].' '.$identity['suffix']),
            'first' => $identity['first_name'],
            'middle' => (string) $identity['middle_name'],
            'position' => (string) $identity['position'],
            'salary' => self::salary((float) ($record?->getAttribute('monthly_rate') ?? 0.0), (float) ($record?->getAttribute('daily_rate') ?? 0.0)),
            'filed' => PortalLabels::fmtDate(PortalLabels::todayKey()),
            'receipt' => '',
            'head' => app(StructureRecords::class)->officeHead($actor, $identity['unit_id'], $identity['department_id']),
        ];
    }

    /**
     * A filed application, whole, for its read-only form. AN APPROVED ONE IS
     * A RECORD: it states what was stored when it was approved
     * (LeaveFormRecords), whatever has changed since. Anything else reads as
     * the records stand.
     *
     * @param  list<array{value: string, label: string}>  $options  the leave types the employee may use now
     * @param  list<string>  $leaveTypeNames
     * @return array{filed: array<string, mixed>, applicant: array<string, mixed>, officers: array<string, LeaveSignatoryView>}
     */
    public static function filedForm(Access $actor, LeaveTravelRequestView $request, array $options, array $leaveTypeNames): array
    {
        $approved = app(LeaveFormRecords::class)->approved($actor, $request);
        $filed = self::filed($request, $approved ?? app(LeavePayRecords::class)->filed($actor, $request), $approved['types'] ?? $options, $leaveTypeNames);
        $applicant = $approved === null ? self::applicant($actor, $request->employee_id) : self::recorded($actor, $request->employee_id, $approved['applicant']);

        return [
            'filed' => $filed,
            'applicant' => [...$applicant, 'filed' => $filed['filed'], 'receipt' => $filed['filed']],
            'officers' => $approved['officers'] ?? app(LeaveSignatoryRecords::class)->all($actor),
        ];
    }

    /**
     * The applicant's lines as stored on approval. The salary still follows
     * the record's own rule: storing it never lets anyone new read it.
     *
     * @param  array{office: string, last: string, first: string, middle: string, position: string, monthly_rate: float, daily_rate: float, head: array{name: string, designation: string}|null}  $stored
     * @return array{office: string, last: string, first: string, middle: string, position: string, salary: string, filed: string, receipt: string, head: array{name: string, designation: string}|null}
     */
    private static function recorded(Access $actor, string $employeeId, array $stored): array
    {
        $mayReadSalary = app(EmployeeRecords::class)->find($actor, $employeeId) !== null;

        return [
            'office' => $stored['office'],
            'last' => $stored['last'],
            'first' => $stored['first'],
            'middle' => $stored['middle'],
            'position' => $stored['position'],
            'salary' => $mayReadSalary ? self::salary($stored['monthly_rate'], $stored['daily_rate']) : '',
            'filed' => '',
            'receipt' => '',
            'head' => $stored['head'],
        ];
    }

    /** The salary line: monthly where set, else daily, which says so because the line reads as monthly. */
    private static function salary(float $monthly, float $daily): string
    {
        return match (true) {
            $monthly > 0.0 => PortalLabels::money($monthly),
            $daily > 0.0 => PortalLabels::money($daily).' a day',
            default => '',
        };
    }

    /**
     * A filed application as its read-only form shows it. It is received the
     * moment it is filed online, so the two dates are the same. 6.A keeps the
     * type filed even if the employee may no longer use it, or HR removed it.
     *
     * @param  array{needs: float|null, with_pay: float|null, without_pay: float|null, certification: list<array{name: string, unit: string, earned: float, less: float, balance: float}>}  $standing
     * @param  list<array{value: string, label: string}>  $options  the leave types the employee may use
     * @param  list<string>  $leaveTypeNames
     * @return array<string, mixed>
     */
    public static function filed(LeaveTravelRequestView $request, array $standing, array $options, array $leaveTypeNames): array
    {
        $count = static fn (?float $days): ?string => $days === null ? null : PortalLabels::dayCount($days);
        $types = array_values(array_filter($options, static fn (array $o): bool => $o['value'] !== PortalLabels::OFFICIAL_BUSINESS));
        // An older request stores `SICK` where the line's key is `SICK_LEAVE`: it marks that line.
        $picked = $request->request_type;
        if ($picked !== PortalLabels::OFFICIAL_BUSINESS) {
            $line = array_values(array_filter($types, static fn (array $o): bool => HrisFormat::requestTypeMatches($o['value'], $picked)))[0] ?? null;
            if ($line === null) {
                $types[] = ['value' => $picked, 'label' => PortalLabels::describe($picked, $leaveTypeNames)];
            } else {
                $picked = $line['value'];
            }
        }

        return [
            'code' => $request->code,
            'status' => HrisFormat::requestStatus($request->status),
            'filed' => $request->created_at->setTimezone(LocalClock::zone())->format('M d, Y'),
            'types' => LeaveFormText::arrange($types, 'label'),
            'type' => $picked,
            'detail' => $request->leave_detail ?? null,
            'note' => $request->leave_detail_note ?? null,
            'commutation' => $request->commutation ?? null,
            'start' => $request->start_date->format('M d, Y'),
            'end' => $request->end_date->format('M d, Y'),
            'day_type' => PortalLabels::dayTypeLabel($request->day_type, $request->half ?? null),
            'reason' => $request->reason,
            'days' => $count($standing['needs']),
            'with_pay' => $count($standing['with_pay']),
            'without_pay' => $count($standing['without_pay']),
            'others' => $request->status === 'APPROVED' ? ($request->approval_note ?? null) : null,
            'recommendation' => $request->recommendation ?? null,
            'recommendation_reason' => $request->recommendation_reason ?? null,
            'disapproved' => $request->status === 'REJECTED' ? ($request->disapproval_reason ?? null) : null,
            'certification' => LeaveFormText::arrange($standing['certification'], 'name'),
        ];
    }
}
