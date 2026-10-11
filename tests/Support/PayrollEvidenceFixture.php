<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AttendanceLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollFormula;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Support\Access;

/** Synthetic facts shared by snapshot, commit and concurrency tests; never imports live data. */
final class PayrollEvidenceFixture
{
    /** @return array{actor: Access, user: User, role: Role, employee: EmployeeProfile, run: PayrollRun, member: PayrollRunMember} */
    public static function make(): array
    {
        $role = Role::create(['name' => 'Evidence reviewer', 'all_data' => true, 'is_system' => false]);
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PAYROLL']);
        $employee = EmployeeProfile::create(['employee_code' => 'EVID-001', 'first_name' => 'Synthetic', 'last_name' => 'Evidence',
            'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'monthly_rate' => 30000, 'is_active' => true]);
        $user = User::factory()->create(['name' => 'Evidence reviewer', 'email' => 'evidence@example.test',
            'role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
        test()->actingAs($user);
        app()->forgetInstance(Access::class);
        $run = PayrollRun::create(['code' => 'PR-EVID', 'name' => 'Evidence payroll', 'status' => 'DRAFT',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15']);
        $member = PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'earning_leave_pay' => 0, 'overtime_acknowledged' => true]);

        return ['actor' => app(Access::class), 'user' => $user, 'role' => $role, 'employee' => $employee, 'run' => $run, 'member' => $member];
    }

    public static function sourceFacts(EmployeeProfile $employee): void
    {
        Shift::create(['employee_id' => $employee->id, 'start_time' => '08:00:00', 'end_time' => '17:00:00',
            'mode' => 'FIXED', 'grace_minutes' => 5, 'break_start' => '12:00:00', 'break_end' => '13:00:00',
            'break_minutes' => 60, 'requires_break_punch' => false, 'work_days' => [1, 2, 3, 4, 5]]);
        foreach (['TIME_IN' => '2026-09-01 00:00:00.123', 'TIME_OUT' => '2026-09-01 09:00:00.456'] as $type => $at) {
            AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'CORRECTION', 'log_type' => $type,
                'logged_at' => $at, 'latitude' => 14.5, 'longitude' => 121.0, 'anomaly_note' => 'Private punch metadata']);
        }
        LeaveTravelRequest::create(['code' => 'LV-EVID', 'employee_id' => $employee->id, 'request_type' => 'BUSINESS_TRAVEL',
            'day_type' => 'WHOLE_DAY', 'start_date' => '2026-09-02', 'end_date' => '2026-09-02',
            'status' => 'APPROVED', 'is_paid' => null, 'reason' => 'Private leave reason']);
        Holiday::create(['code' => 'H-EVID', 'name' => 'Synthetic holiday', 'date' => '2026-09-03',
            'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 100]);
        PayrollFormula::create(['code' => 'F-EVID', 'key' => 'OT', 'name' => 'Synthetic overtime', 'expression' => 'Display only',
            'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 4]]]);
    }
}
