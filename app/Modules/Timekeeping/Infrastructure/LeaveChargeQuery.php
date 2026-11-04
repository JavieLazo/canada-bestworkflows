<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Infrastructure;

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\ModuleCode;
use App\Models\LeaveAttendanceCharge;
use App\Models\LeaveCredit;
use App\Modules\Timekeeping\Application\ConversionTables;
use App\Modules\Timekeeping\Application\LeaveBalances;
use App\Modules\Timekeeping\Application\LeaveCharges;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Application\LeavePolicyStore;
use App\Modules\Timekeeping\Application\Queries\LeaveTypeCreditRecords;
use App\Modules\Timekeeping\Contracts\LeaveChargePoster;
use App\Modules\Timekeeping\Contracts\LeaveChargeSource;
use App\Modules\Timekeeping\Domain\Credits\AttendanceChargePlan;
use App\Modules\Timekeeping\Domain\Credits\LeaveQuantity;
use App\Support\Access;
use App\Support\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Answers payroll's charge questions from Timekeeping's own persistence:
 * the approved policy in force, the conversion table in force, the leave
 * types' credit rules and each employee's balances (LeaveChargeSource), and
 * posts a finalized payslip's charges (LeaveChargePoster). The read is
 * gated like the attendance facts: whoever may run a payroll over these
 * employees.
 */
final class LeaveChargeQuery implements LeaveChargePoster, LeaveChargeSource
{
    public function forPayroll(Access $actor, array $employeeIds, string $periodStart, string $periodEnd): array
    {
        abort_unless($actor->hasModule(ModuleCode::PAYROLL), 403);
        if (! $actor->mayReadAllData(ModuleCode::PAYROLL)) {
            abort_unless($actor->hasEmployeeLink() && array_diff($employeeIds, [$actor->ownEmployeeId()]) === [], 403);
        }
        if ($employeeIds === [] || ! LeavePolicyStore::ready() || ! LeaveTypeCreditRecords::ready()) {
            return [];
        }
        $rules = LeaveTypeCreditRecords::rules();
        $conversion = ConversionTables::entry($periodEnd);
        $startYear = (int) substr($periodStart, 0, 4);
        $out = [];
        foreach (LeavePolicyStore::forEmployees($employeeIds, $periodEnd) as $employeeId => $row) {
            $policy = LeavePolicyStore::policy($row);
            if (! $policy->chargesEnabled()) {
                continue;
            }
            // The row the balance lives in: the period's year, or a later
            // one once the earning job carried that year's balance forward.
            $year = LeaveCharges::liveYear($employeeId, $startYear);
            // The policy's orders, kept to the types whose CURRENT credit
            // rules still allow that charge: a type HR un-ticked since the
            // policy was approved is no longer charged.
            $late = array_values(array_filter($policy->chargeOrder('late'), static fn (string $token): bool => (bool) ($rules[$token]['rules']->chargeLate ?? false)));
            $absence = array_values(array_filter($policy->chargeOrder('absence'), static fn (string $token): bool => (bool) ($rules[$token]['rules']->chargeAbsence ?? false)));
            $tokens = array_values(array_unique([...$late, ...$absence]));
            if ($tokens === []) {
                continue;
            }
            // What may be charged is what is AVAILABLE: the balance less the
            // days unpaid cash-outs have claimed (LeaveBalances).
            $balances = [];
            foreach ($tokens as $token) {
                $balances[$token] = [
                    'name' => $rules[$token]['name'] ?? $token,
                    'available' => LeaveBalances::available($employeeId, $year, $token),
                    'below_zero_limit' => LeaveQuantity::normalize($rules[$token]['rules']->belowZeroLimit ?? 0.0),
                ];
            }
            $charged = ['late' => [], 'absence' => []];
            if (LeaveCharges::ready()) {
                $rows = LeaveAttendanceCharge::query()->where('employee_id', $employeeId)
                    ->where('date', '>=', $periodStart)->where('date', '<=', $periodEnd)->orderBy('date')->get(['date', 'kind']);
                foreach ($rows as $charge) {
                    $charged[$charge->kind === AttendanceChargePlan::LATE ? 'late' : 'absence'][] = $charge->date->format('Y-m-d');
                }
            }
            $out[$employeeId] = [
                'policy_id' => $row->id,
                'charges' => ['enabled' => true, 'combine' => $policy->combine(), 'late' => $late, 'absence' => $absence],
                'conversion' => $conversion === null ? null : ['version_id' => $conversion['version_id'], 'rows' => $conversion['rows'], 'hours_per_day' => $conversion['hours_per_day'], 'decimals' => $conversion['decimals']],
                'balances' => $balances,
                'year' => $year,
                'charged' => $charged,
            ];
        }

        return $out;
    }

    public function plan(array $entry, array $days): array
    {
        $balances = [];
        foreach ($entry['balances'] as $token => $balance) {
            $balances[(string) $token] = ['available' => (float) $balance['available'], 'below_zero_limit' => (float) $balance['below_zero_limit']];
        }
        $conversion = $entry['conversion'] === null ? null : ['rows' => $entry['conversion']['rows'], 'hours_per_day' => (float) $entry['conversion']['hours_per_day'], 'decimals' => (int) $entry['conversion']['decimals']];

        return AttendanceChargePlan::plan($entry['charges'], $conversion, $balances, $days, $entry['charged']);
    }

    public function post(Access $actor, string $employeeId, string $payslipId, string $runCode, array $lines, array $entry): void
    {
        if ($lines === []) {
            return;
        }
        if (! LeaveCharges::ready()) {
            throw new RuntimeException('Leave credits cannot be charged until this installation\'s upgrade finishes.');
        }
        LeaveCreditWriter::lock();
        $year = (int) $entry['year'];
        $names = [];
        foreach ($entry['balances'] as $token => $balance) {
            $names[(string) $token] = (string) ($balance['name'] ?? $token);
        }
        $totals = [];
        $lateDays = 0.0;
        $absenceDays = 0.0;
        foreach ($lines as $line) {
            $covered = [];
            foreach ($line['covered'] as $token => $days) {
                if ($days <= 0) {
                    continue;
                }
                $credit = LeaveCredit::query()->where('employee_id', $employeeId)->where('year', $year)->where('request_type', $token)->lockForUpdate()->first();
                if ($credit !== null) {
                    LeaveCreditWriter::baseline($credit);
                }
                $credit ??= new LeaveCredit(['employee_id' => $employeeId, 'year' => $year, 'request_type' => $token]);
                $credit->credits = LeaveQuantity::normalize(($credit->credits ?? 0.0) - $days);
                if (! $credit->save()) {
                    throw new RuntimeException('The leave allocation could not be charged.');
                }
                $entryId = (string) Str::ulid();
                $what = $line['kind'] === AttendanceChargePlan::LATE
                    ? 'Lateness and undertime of '.$line['minutes'].' min on '.$line['date']
                    : 'Absence on '.$line['date'];
                LeaveCreditWriter::append($actor, $employeeId, $year, (string) $token, 'CHARGE', -$days, "{$what} · payroll {$runCode}", $entryId);
                $covered[] = ['type' => (string) $token, 'days' => $days, 'entry_id' => $entryId];
                $totals[$token] = LeaveQuantity::normalize(($totals[$token] ?? 0.0) + $days);
                if ($line['kind'] === AttendanceChargePlan::LATE) {
                    $lateDays += $days;
                } else {
                    $absenceDays += $days;
                }
            }
            // The row exists even when nothing covered the day, so the day is
            // never looked at again; a duplicate (employee, date, kind) fails
            // the finalization.
            LeaveAttendanceCharge::create([
                'employee_id' => $employeeId, 'payslip_id' => $payslipId, 'date' => $line['date'], 'kind' => $line['kind'],
                'minutes' => $line['minutes'], 'days' => $line['days'], 'covered' => $covered, 'uncovered_days' => $line['uncovered_days'],
            ]);
        }
        if ($totals === []) {
            return;
        }
        $parts = [];
        foreach ($totals as $token => $days) {
            $parts[] = ($names[$token] ?? $token).' −'.LeaveQuantity::format($days);
        }
        $sentence = "Payroll {$runCode}: ".implode(' · ', $parts).' charged to leave credits ('
            .LeaveQuantity::format(LeaveQuantity::normalize($lateDays)).' for lateness and undertime, '
            .LeaveQuantity::format(LeaveQuantity::normalize($absenceDays)).' for absences)';
        DB::afterCommit(static fn () => AuditService::record($actor, AuditEvent::LEAVE_CHARGED, 'leave_credits', $employeeId, $sentence));
    }
}
