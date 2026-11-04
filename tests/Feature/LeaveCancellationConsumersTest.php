<?php

declare(strict_types=1);

use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Enums\ModuleCode;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Models\LeaveRequestCancellation;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\PayrollFinalizationSnapshot;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Application\PayrollInputs;
use App\Modules\Payroll\Application\PayrollReviewRequired;
use App\Modules\Timekeeping\Application\Commands\CancelAbsence;
use App\Modules\Timekeeping\Application\Commands\FileAbsence;
use App\Modules\Timekeeping\Application\Queries\LeaveCreditRecords;
use App\Modules\Timekeeping\Application\Queries\LeaveLedgerRecords;
use App\Modules\Timekeeping\Application\Queries\TimekeepingReports;
use App\Modules\Timekeeping\Application\ScheduleGuard;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Platform\Reports\ReportCriteria;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PayrollEvidenceFixture;
use Tests\Support\PayrollReviewFixture;

/** Credits, filing, reports, attendance and captured payroll facts all subtract exactly the same cancelled dates. */

/** @return array{Access, EmployeeProfile} */
function cancellationConsumerActor(): array
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'CC-'.Str::random(8), 'first_name' => 'Cancellation', 'last_name' => 'Consumer',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true,
        'daily_rate' => 1000, 'monthly_rate' => 0,
    ])->save();
    $role = new Role;
    $role->forceFill(['name' => 'Consumer '.Str::random(8), 'all_data' => true, 'is_system' => false])->save();
    foreach ([ModuleCode::LEAVES, ModuleCode::PORTAL, ModuleCode::EDTR, ModuleCode::PAYROLL, ModuleCode::REPORTS] as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
    $type = new LeaveType;
    $type->forceFill(['code' => 'CC-VL', 'name' => 'Vacation Leave'])->save();

    return [app(Access::class), $employee];
}

function cancellationConsumerCredit(EmployeeProfile $employee, int $year = 2026): LeaveCredit
{
    $row = new LeaveCredit;
    $row->forceFill(['employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE', 'year' => $year, 'credits' => 10])->save();

    return $row;
}

/** @param array<string, mixed> $extra */
function cancellationConsumerRequest(EmployeeProfile $employee, array $extra = []): LeaveTravelRequest
{
    $row = new LeaveTravelRequest;
    $row->forceFill(array_replace([
        'code' => 'CC-'.Str::random(8), 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'start_date' => '2026-10-12', 'end_date' => '2026-10-14', 'day_type' => 'WHOLE_DAY',
        'status' => 'APPROVED', 'is_paid' => true,
    ], $extra))->save();

    return $row;
}

function cancellationConsumerCancel(Access $actor, LeaveTravelRequest $request, string $date): void
{
    $row = new LeaveRequestCancellation;
    $row->forceFill([
        'leave_travel_request_id' => $request->id, 'date' => $date,
        'cancelled_by' => $actor->userId, 'cancelled_at' => ManilaClock::instant('2026-10-10T10:00:00+08:00')->utc(),
        'reason' => 'Private cancellation reason',
    ])->save();
}

it('subtracts only cancelled calendar dates from paid credits and future bookings', function (string $dayType, float $used, float $taken, float $booked) {
    $this->travelTo(new DateTimeImmutable('2026-10-10T10:00:00+08:00'));
    [$actor, $employee] = cancellationConsumerActor();
    $credit = cancellationConsumerCredit($employee);
    $request = cancellationConsumerRequest($employee, ['start_date' => '2026-10-09', 'end_date' => '2026-10-13', 'day_type' => $dayType]);
    foreach (['2026-10-11', '2026-10-13'] as $date) {
        cancellationConsumerCancel($actor, $request, $date);
    }
    $balance = app(LeaveCreditRecords::class)->balances($actor, $employee->id, 2026)[0];
    $ledger = app(LeaveLedgerRecords::class)->summary($actor, $employee->id, 2026)[0];

    expect([$balance->used, $balance->remaining])->toBe([$used, 10.0 - $used])
        ->and([$ledger->paid_taken, $ledger->booked_paid])->toBe([$taken, $booked])
        ->and($ledger->available)->toBe($balance->remaining)
        ->and($credit->fresh()->credits)->toBe(10.0)
        ->and(LeaveCreditEntry::query()->count())->toBe(0)
        ->and($request->fresh()->start_date->format('Y-m-d'))->toBe('2026-10-09');
})->with([
    'whole days including a cancelled Sunday' => ['WHOLE_DAY', 3.0, 2.0, 1.0],
    'half days including a cancelled Sunday' => ['HALF_DAY', 1.5, 1.0, 0.5],
]);

it('removes unpaid bookings without adding paid credits', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-10T10:00:00+08:00'));
    [$actor, $employee] = cancellationConsumerActor();
    cancellationConsumerCredit($employee);
    $request = cancellationConsumerRequest($employee, ['is_paid' => false]);
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    $balance = app(LeaveCreditRecords::class)->balances($actor, $employee->id, 2026)[0];
    $ledger = app(LeaveLedgerRecords::class)->summary($actor, $employee->id, 2026)[0];

    expect([$balance->used, $balance->remaining])->toBe([0.0, 10.0])
        ->and([$ledger->booked_paid, $ledger->booked_unpaid])->toBe([0.0, 2.0])
        ->and(LeaveCreditEntry::query()->count())->toBe(0);
});

it('restores cancellation usage to the original start year across New Year', function () {
    $this->travelTo(new DateTimeImmutable('2026-12-31T10:00:00+08:00'));
    [$actor, $employee] = cancellationConsumerActor();
    cancellationConsumerCredit($employee);
    cancellationConsumerCredit($employee, 2027);
    $request = cancellationConsumerRequest($employee, ['start_date' => '2026-12-31', 'end_date' => '2027-01-02', 'day_type' => 'HALF_DAY']);
    cancellationConsumerCancel($actor, $request, '2027-01-01');
    $previous = app(LeaveCreditRecords::class)->balances($actor, $employee->id, 2026)[0];
    $current = app(LeaveCreditRecords::class)->balances($actor, $employee->id, 2027)[0];
    $ledger = app(LeaveLedgerRecords::class)->summary($actor, $employee->id, 2026)[0];

    expect([$previous->used, $previous->remaining])->toBe([1.0, 9.0])
        ->and([$current->used, $current->remaining])->toBe([0.0, 10.0])
        ->and([$ledger->paid_taken, $ledger->booked_paid])->toBe([0.5, 0.5]);
});

it('allows replacement filing on cancelled dates while refusing any remaining overlap', function () {
    [$actor, $employee] = cancellationConsumerActor();
    cancellationConsumerCredit($employee);
    $request = cancellationConsumerRequest($employee);
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-10-13', '2026-10-13', 'Replacement');

    expect(LeaveTravelRequest::query()->where('status', 'PENDING')->count())->toBe(1);
    expect(fn () => app(FileAbsence::class)->handle($actor, 'VACATION_LEAVE', 'WHOLE_DAY', '2026-10-14', '2026-10-15', 'Overlap'))
        ->toThrow(RuntimeException::class, 'overlap request');
    expect(LeaveTravelRequest::query()->count())->toBe(2);
});

it('reports remaining calendar usage while retaining the original dates and cancelled pay decision', function () {
    [$actor, $employee] = cancellationConsumerActor();
    $request = cancellationConsumerRequest($employee, ['day_type' => 'HALF_DAY']);
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    $full = cancellationConsumerRequest($employee, ['start_date' => '2026-10-15', 'end_date' => '2026-10-15', 'status' => 'CANCELLED']);
    cancellationConsumerCancel($actor, $full, '2026-10-15');
    $report = app(TimekeepingReports::class)->generate($actor, ReportCriteria::from([
        'type' => 'leave-reports', 'from' => '2026-10-12', 'to' => '2026-10-15', 'employeeIds' => [$employee->id],
    ]));

    expect(array_slice($report->rows[0], 4))->toBe(['2026-10-12', '2026-10-14', 'Half day', '1', 'APPROVED', 'Paid', '2026-10-13'])
        ->and(array_slice($report->rows[1], 7))->toBe(['0', 'CANCELLED', 'Paid', '2026-10-15']);
});

it('uses the same detached cancellation dates for DTR payroll and the own portal', function () {
    [$actor, $employee] = cancellationConsumerActor();
    $request = cancellationConsumerRequest($employee);
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    $source = app(AttendanceSource::class);
    $facts = [
        $source->forDtr($actor, $employee->id, '2026-10-12', '2026-10-14'),
        $source->forPayroll($actor, [$employee->id], '2026-10-12', '2026-10-14')[$employee->id],
        $source->forOwnPortal($actor, '2026-10-12', '2026-10-14'),
    ];
    foreach ($facts as $input) {
        expect($input->leaves[0]->cancelledDates)->toBe(['2026-10-13']);
        $days = DtrCalculator::compute($input->logs, $input->shift ?? DomainShift::default(), $input->leaves, '2026-10-12', '2026-10-14');
        expect($days[0]->excused)->toBeTrue()->and($days[1]->excused)->toBeFalse()->and($days[2]->excused)->toBeTrue();
    }
});

it('captures cancellation dates in payroll fingerprints and restores evidence without live reads', function () {
    [$actor, $employee] = cancellationConsumerActor();
    $request = cancellationConsumerRequest($employee);
    $before = PayrollData::calculateDrafts([$employee->id], '2026-10-12', '2026-10-14', actor: $actor)['inputs'];
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    $after = PayrollData::calculateDrafts([$employee->id], '2026-10-12', '2026-10-14', actor: $actor)['inputs'];
    $serialized = PayrollInputs::json($after->toArray());
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $restored = PayrollInputs::restore($serialized);
        expect(DB::getQueryLog())->toBe([])
            ->and($restored->fingerprint())->toBe($after->fingerprint())
            ->and($restored->fingerprint())->not->toBe($before->fingerprint())
            ->and($restored->attendance[$employee->id]->leaves[0]->cancelledDates)->toBe(['2026-10-13'])
            ->and($serialized)->not->toContain('Private cancellation reason', 'cancelled_by', 'cancelled_at');
    } finally {
        DB::disableQueryLog();
    }
});

it('preserves schema-six evidence bytes and fingerprints without inferring live cancellations', function () {
    [$actor, $employee] = cancellationConsumerActor();
    cancellationConsumerRequest($employee);
    $inputs = PayrollData::calculateDrafts([$employee->id], '2026-10-12', '2026-10-14', actor: $actor)['inputs'];
    $legacy = $inputs->toArray();
    $legacy['schema_version'] = 6;
    $legacy = PayrollEvidenceFixture::beforeSchema17($legacy);
    unset($legacy['attendance'][$employee->id]['leaves'][0]['cancelled_dates']);
    // Schema 6 evidence carries no Contribution & Tax Table versions either (schema 8).
    unset($legacy['statutory_tables'], $legacy['pay_rules'], $legacy['benefit_rules'], $legacy['deduction_rules'], $legacy['recurring_lines'], $legacy['skipped_recurring_lines'], $legacy['attendance_through'], $legacy['settlements'], $legacy['leave_charges'], $legacy['leave_cash_outs'], $legacy['cash_out_type'], $legacy['gov_policies'], $legacy['adjustments']);
    $json = PayrollInputs::json($legacy);
    $restored = PayrollInputs::restore($json);

    expect($restored->attendance[$employee->id]->leaves[0]->cancelledDates)->toBe([])
        ->and(PayrollInputs::json($restored->toArray()))->toBe($json)
        ->and($restored->fingerprint())->toBe(hash('sha256', $json));
    $invalid = $inputs->toArray();
    unset($invalid['attendance'][$employee->id]['leaves'][0]['cancelled_dates']);
    expect(fn () => PayrollInputs::restore(PayrollInputs::json($invalid)))->toThrow(RuntimeException::class, 'recorded schema');
});

it('protects the real unexcused DTR after a cancelled leave date becomes historical', function () {
    $this->travelTo(new DateTimeImmutable('2026-10-14T10:00:00+08:00'));
    [$actor, $employee] = cancellationConsumerActor();
    $request = cancellationConsumerRequest($employee);
    cancellationConsumerCancel($actor, $request, '2026-10-13');
    DB::transaction(function () use ($employee): void {
        $guard = app(ScheduleGuard::class);
        $before = $guard->before($employee->id, '2026-10-15');
        $records = json_decode($before->records, true, 512, JSON_THROW_ON_ERROR);
        expect($before->leaves[0]->cancelledDates)->toBe(['2026-10-13'])
            ->and(array_column($records['rows'], 'status'))->toBe(['EXCUSED', 'ABSENT', 'EXCUSED']);
        $guard->verify($before);
    });
});

it('refuses a saved payroll review after partial or full cancellation even when typed leave pay stays unchanged', function (array $dates, bool $manual, string $status, float $computedPay) {
    $this->travelTo(new DateTimeImmutable('2026-10-10T10:00:00+08:00'));
    [$actor, $employee] = cancellationConsumerActor();
    $request = cancellationConsumerRequest($employee);
    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'CC-PAY', 'name' => 'Cancellation payroll', 'status' => 'DRAFT',
        'period_start' => '2026-10-12', 'period_end' => '2026-10-14',
    ])->save();
    $member = new PayrollRunMember;
    $member->forceFill([
        'run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-10-12', 'period_end' => '2026-10-14',
        'earning_leave_pay' => $manual ? 3000.0 : null,
    ])->save();
    $earnings = $manual ? [$employee->id => ['leave_pay' => 3000.0]] : [];
    $before = PayrollData::calculateDrafts([$employee->id], '2026-10-12', '2026-10-14', draftEarnings: $earnings, actor: $actor)['drafts'][0];
    $receipt = PayrollReviewFixture::token($actor, $run->id);

    app(CancelAbsence::class)->handle($actor, $request->id, $dates, 'Approved plans changed');
    $after = PayrollData::calculateDrafts([$employee->id], '2026-10-12', '2026-10-14', draftEarnings: $earnings, actor: $actor)['drafts'][0];
    $auditCount = AuditLog::query()->count();
    $notificationCount = Notification::query()->count();

    expect($before->leave_pay)->toBe(3000.0)
        ->and($after->leave_pay)->toBe($manual ? 3000.0 : $computedPay)
        ->and($request->fresh()->status)->toBe($status)
        ->and(LeaveRequestCancellation::query()->count())->toBe(count($dates));
    if ($manual) {
        expect([$after->gross_pay, $after->net_pay])->toBe([$before->gross_pay, $before->net_pay]);
    }
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, $receipt))->toThrow(PayrollReviewRequired::class);
    expect($run->fresh()->status)->toBe('DRAFT')
        ->and(PayrollFinalizationSnapshot::query()->count())->toBe(0)
        ->and(Payslip::query()->where('run_id', $run->id)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($auditCount)
        ->and(Notification::query()->count())->toBe($notificationCount)
        ->and(PayrollReviewFixture::token($actor, $run->id))->not->toBe($receipt);
})->with([
    'partial automatic leave pay' => [['2026-10-13'], false, 'APPROVED', 2000.0],
    'full automatic leave pay' => [['2026-10-12', '2026-10-13', '2026-10-14'], false, 'CANCELLED', 0.0],
    'partial typed leave pay stays unchanged' => [['2026-10-13'], true, 'APPROVED', 2000.0],
    'full typed leave pay stays unchanged' => [['2026-10-12', '2026-10-13', '2026-10-14'], true, 'CANCELLED', 0.0],
]);
