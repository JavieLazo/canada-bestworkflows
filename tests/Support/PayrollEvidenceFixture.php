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

    /**
     * A current capture as evidence of a schema before 17 would carry it:
     * without the overtime categories (schema 17) and the pay-rule
     * segments (schema 18), which those schemas never recorded. A test
     * that lowers `schema_version` on a current `toArray()` calls this, or
     * the strict restore refuses a key the schema never wrote.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public static function beforeSchema17(array $inputs): array
    {
        return self::asSchema($inputs, 16);
    }

    /**
     * A current capture shaped as the given schema carried it: the overtime
     * categories go below 17, the pay-rule segments below 18, the salary
     * segments below 19, the contribution month and posted shares below 20,
     * the tax-free ceiling and benefit assignments below 21, the tax year and
     * the whole-year keys below 22, a leave's half below 23, and a cash
     * conversion's purpose and salary and the paying type's day value below
     * 24.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public static function asSchema(array $inputs, int $schema): array
    {
        if ($schema < 17) {
            foreach ($inputs['attendance'] ?? [] as $id => $facts) {
                unset($inputs['attendance'][$id]['overtime_categories']);
            }
            foreach ($inputs['settlements'] ?? [] as $id => $rows) {
                foreach ($rows as $i => $row) {
                    unset($inputs['settlements'][$id][$i]['actual']['overtime_categories']);
                }
            }
        }
        if ($schema < 18) {
            foreach ($inputs['pay_rules'] ?? [] as $id => $entry) {
                unset($inputs['pay_rules'][$id]['segments']);
            }
        }
        if ($schema < 19) {
            foreach ($inputs['employees'] ?? [] as $i => $employee) {
                unset($inputs['employees'][$i]['salary_rates']);
            }
            foreach ($inputs['settlements'] ?? [] as $id => $rows) {
                foreach ($rows as $i => $row) {
                    unset($inputs['settlements'][$id][$i]['employee']['salary_rates']);
                }
            }
        }
        if ($schema < 20) {
            unset($inputs['contribution_month'], $inputs['contribution_posted']);
        }
        if ($schema < 21) {
            unset($inputs['benefit_ceiling'], $inputs['benefit_assignments']);
        }
        if ($schema < 23) {
            foreach ($inputs['attendance'] ?? [] as $id => $facts) {
                foreach ($facts['leaves'] ?? [] as $i => $leave) {
                    unset($inputs['attendance'][$id]['leaves'][$i]['half']);
                }
            }
            foreach ($inputs['settlements'] ?? [] as $id => $rows) {
                foreach ($rows as $i => $row) {
                    foreach ($row['actual']['leaves'] ?? [] as $j => $leave) {
                        unset($inputs['settlements'][$id][$i]['actual']['leaves'][$j]['half']);
                    }
                    foreach ($row['presumed']['leaves'] ?? [] as $j => $leave) {
                        unset($inputs['settlements'][$id][$i]['presumed']['leaves'][$j]['half']);
                    }
                }
            }
        }
        if ($schema < 24) {
            foreach ($inputs['leave_cash_outs'] ?? [] as $id => $list) {
                foreach ($list as $i => $entry) {
                    unset($inputs['leave_cash_outs'][$id][$i]['purpose'], $inputs['leave_cash_outs'][$id][$i]['salary']);
                }
            }
            if (is_array($inputs['cash_out_type'] ?? null)) {
                unset($inputs['cash_out_type']['day_value'], $inputs['cash_out_type']['factor']);
            }
        }
        if ($schema < 22) {
            unset($inputs['tax_year']);
            foreach ($inputs['employees'] ?? [] as $i => $employee) {
                unset($inputs['employees'][$i]['whole_year_tax'], $inputs['employees'][$i]['tax_spread']);
            }
            foreach ($inputs['settlements'] ?? [] as $id => $rows) {
                foreach ($rows as $i => $row) {
                    unset($inputs['settlements'][$id][$i]['employee']['whole_year_tax'], $inputs['settlements'][$id][$i]['employee']['tax_spread']);
                }
            }
        }

        return $inputs;
    }
}
