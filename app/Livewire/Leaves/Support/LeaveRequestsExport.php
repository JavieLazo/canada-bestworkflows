<?php

declare(strict_types=1);

namespace App\Livewire\Leaves\Support;

use App\Livewire\Leaves\Index;
use App\Livewire\Leaves\LeaveLabels;
use App\Modules\Timekeeping\Application\Queries\AbsenceRecords;
use App\Modules\Timekeeping\Application\Queries\ReferenceData;
use App\Platform\Transfer\CsvExport;
use App\Platform\Transfer\TableExport;
use App\Support\Access;
use App\Support\EmployeeName;
use App\Support\ManilaClock;
use Generator;

/**
 * Leave Management's request table as CSV: the requests the actor may see
 * (the LEAVES scope of AbsenceRecords::list), filtered and sorted as on
 * screen ({@see LeaveRows}), with the columns the table shows — never the
 * reason, which only the review dialog shows. Export only: requests are
 * filed on My Portal and decided one at a time.
 */
final class LeaveRequestsExport implements TableExport
{
    public function export(Access $actor, array $query): CsvExport
    {
        abort_if($actor->userId === null, 403);
        $filters = [
            'q' => $query['q'] ?? '',
            'status' => isset(Index::STATUSES[$query['status'] ?? '']) ? (string) $query['status'] : 'ALL',
            'description' => ($query['description'] ?? '') !== '' ? (string) $query['description'] : 'ALL',
            'dayType' => ($query['dayType'] ?? '') !== '' ? (string) $query['dayType'] : 'ALL',
            'sort' => in_array($query['sort'] ?? '', ['id', 'employee', 'date', 'description', 'dayType'], true) ? (string) $query['sort'] : 'id',
            'dir' => ($query['dir'] ?? '') === 'desc' ? 'desc' : 'asc',
        ];

        $rows = static function () use ($actor, $filters): Generator {
            $typeNames = array_values(app(ReferenceData::class)->leaveTypes($actor)->map(static fn ($type): string => $type->name)->all());
            $requests = app(AbsenceRecords::class)->list($actor);
            $described = [];
            foreach ($requests as $request) {
                $described[$request->id] = LeaveLabels::describe($request->request_type, $typeNames);
            }
            foreach (LeaveRows::arrange($requests, $described, $filters['q'], $filters['status'], $filters['description'], $filters['dayType'], $filters['sort'], $filters['dir']) as $r) {
                $pay = $r->status === 'APPROVED' && $r->request_type !== LeaveLabels::OFFICIAL_BUSINESS
                    ? ($r->is_paid === false ? 'Without pay' : 'With pay')
                    : '';
                yield [
                    $r->code ?? '', EmployeeName::display($r->employee), $r->employee->position ?? '',
                    $r->start_date->format('Y-m-d'), $r->end_date->format('Y-m-d'),
                    $described[$r->id], LeaveLabels::dayTypeLabel($r->day_type), $r->status, $pay,
                    implode(', ', $r->cancelled_dates),
                ];
            }
        };

        return new CsvExport(
            'leave-requests-'.ManilaClock::todayKey().'.csv',
            ['ID', 'Employee', 'Position', 'Start date', 'End date', 'Description', 'Day type', 'Status', 'Pay', 'Cancelled dates'],
            $rows,
        );
    }
}
