<?php

declare(strict_types=1);

use App\Domain\Payroll\PayRules;
use App\Domain\Payroll\StatutorySchedule;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\PayrollFormula;
use App\Models\PayrollIncentiveType;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\PayrollStatutoryTable;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\IdentityAccess\Application\Commands\ManageRoles;
use App\Modules\Payroll\Application\Commands\AddStatutoryTableVersion;
use App\Modules\Payroll\Application\Commands\DeletePayroll;
use App\Modules\Payroll\Application\Commands\ExtendStatutoryCoverage;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Modules\Payroll\Application\Commands\SaveFormulas;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\Commands\SavePayRulesVersion;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Timekeeping\Application\Commands\DecideOvertime;
use App\Modules\Timekeeping\Application\Commands\DecidePendingOvertime;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayrollReviewFixture;

/** Approved business writes and their audit must survive or roll back together. */

/** @return array{actor: Access, role: Role, target: User, employee: EmployeeProfile, run: PayrollRun} */
function requiredAuditFixture(): array
{
    test()->travelTo(new DateTimeImmutable('2026-09-17T03:00:00Z'));
    $adminRole = Role::create(['name' => 'Audit administrator', 'all_data' => true, 'is_system' => false]);
    foreach (['PAYROLL', 'USERS', 'RECORDS', 'EDTR'] as $module) {
        RoleModuleGrant::create(['role_id' => $adminRole->id, 'module' => $module]);
    }
    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    test()->actingAs($admin);
    app()->forgetInstance(Access::class);
    $role = Role::create(['name' => 'Audit target', 'all_data' => false, 'is_system' => false]);
    RoleModuleGrant::create(['role_id' => $role->id, 'module' => 'PORTAL']);
    $target = User::factory()->create(['role_id' => $role->id, 'is_active' => false]);
    $employee = EmployeeProfile::create(['employee_code' => 'AUD-001', 'first_name' => 'Synthetic', 'last_name' => 'Audit', 'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
    $run = PayrollRun::create(['code' => 'PR-AUDIT', 'name' => 'Saved audit draft', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'status' => 'DRAFT']);
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'earning_leave_pay' => 0, 'overtime_acknowledged' => true]);

    return ['actor' => app(Access::class), 'role' => $role, 'target' => $target, 'employee' => $employee, 'run' => $run];
}

/** A shift and a Thursday worked to 19:30, Manila: 150 minutes of overtime to decide. */
function requiredAuditOvertimeDay(EmployeeProfile $employee): void
{
    Shift::create(['employee_id' => $employee->id, 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'mode' => 'FIXED',
        'grace_minutes' => 5, 'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'requires_break_punch' => false, 'work_days' => [1, 2, 3, 4, 5]]);
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => '19:30'] as $type => $at) {
        AttendanceLog::create(['employee_id' => $employee->id, 'source' => 'MANUAL', 'log_type' => $type,
            'logged_at' => ManilaClock::instant("2026-09-10T{$at}:00")->utc()]);
    }
}

/** @return array<string, list<object>> */
function requiredAuditRows(): array
{
    $rows = [];
    foreach (['users', 'roles', 'role_module_grants', 'payroll_runs', 'payroll_run_members', 'payroll_run_member_incentives', 'payslips', 'payslip_incentives', 'payroll_formulas', 'payroll_statutory_tables', 'payroll_finalization_snapshots', 'overtime_approvals', 'audit_logs', 'notifications'] as $table) {
        $rows[$table] = array_values(DB::table($table)->orderBy('id')->get()->all());
    }

    return $rows;
}

it('rolls back each approved command when its audit insert fails', function (string $operation) {
    ['actor' => $actor, 'role' => $role, 'target' => $target, 'employee' => $employee, 'run' => $run] = requiredAuditFixture();
    if (str_starts_with($operation, 'decide-')) {
        requiredAuditOvertimeDay($employee);
    }
    $before = requiredAuditRows();
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic audit failure');
    });
    $change = fn () => match ($operation) {
        'create-account' => app(ManageAccounts::class)->create($actor, 'audit@example.test', 'Synthetic-Password#2026', $role->id, ''),
        'update-account' => app(ManageAccounts::class)->update($actor, (string) $target->id, $target->email, $role->id, $employee->id, true),
        'delete-account' => app(ManageAccounts::class)->delete($actor, (string) $target->id),
        'create-role' => app(ManageRoles::class)->save($actor, null, 'New role', true),
        'update-role' => app(ManageRoles::class)->save($actor, $role->id, 'Changed role', true),
        'grant' => app(ManageRoles::class)->toggleGrant($actor, $role->id, 'LEAVES', true),
        'revoke' => app(ManageRoles::class)->toggleGrant($actor, $role->id, 'PORTAL', false),
        'create-draft' => app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'New empty draft', '2026-09-01', '2026-09-15', [], [], [], [])),
        'save-draft' => app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Changed draft', '2026-09-01', '2026-09-15', [$employee->id], [], [$employee->id => 100.0], [])),
        // The incentive lines are written inside the same audited transaction.
        'save-incentives' => app(SavePayroll::class)->handle($actor, new PayrollDraft($run->id, 'Draft with incentives', '2026-09-01', '2026-09-15', [$employee->id], [], [], [],
            [$employee->id => [PayrollIncentiveType::create(['code' => '1', 'name' => 'Commission', 'is_taxable' => true])->id => 1000]])),
        'delete-draft' => app(DeletePayroll::class)->handle($actor, $run->id),
        'finalize' => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)),
        'decide-overtime' => app(DecideOvertime::class)->handle($actor, $employee->id, '2026-09-10', 'APPROVED', '60'),
        'decide-pending-overtime' => app(DecidePendingOvertime::class)->handle($actor, $employee->id, '2026-09-01', '2026-09-15', 'REJECTED'),
        // A table version and a coverage extension are written inside the same audited transaction.
        'add-statutory-version' => app(AddStatutoryTableVersion::class)->handle($actor, StatutorySchedule::SSS, '2027-01-01', '2027-12-31', 'Synthetic', StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]),
        'extend-statutory-coverage' => app(ExtendStatutoryCoverage::class)->handle($actor, (string) PayrollStatutoryTable::query()->where('kind', StatutorySchedule::SSS)->value('id'), '2027-12-31'),
        'save-pay-rules' => app(SavePayRulesVersion::class)->handle($actor, 'COMPANY', '2027-01-01', 'Synthetic', PayRules::preset(PayRules::PRESET_STANDARD)),
        default => throw new LogicException('Unknown test operation'),
    };
    expect($change)->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect(requiredAuditRows())->toEqual($before);
})->with(['create-account', 'update-account', 'delete-account', 'create-role', 'update-role', 'grant', 'revoke', 'create-draft', 'save-draft', 'save-incentives', 'delete-draft', 'finalize', 'decide-overtime', 'decide-pending-overtime', 'add-statutory-version', 'extend-statutory-coverage', 'save-pay-rules']);

it('keeps earlier formula saves while rolling back the row whose audit fails', function () {
    ['actor' => $actor] = requiredAuditFixture();
    $params = [
        'OT' => ['working_hours' => 8, 'percentage' => 1.25, 'max_ot' => 4],
        'NSD' => ['percentage' => 0.1, 'max_nsd' => 8],
    ];
    foreach ($params as $key => $values) {
        $items = [];
        foreach ($values as $name => $value) {
            $items[] = ['key' => $name, 'label' => $name, 'value' => $value];
        }
        PayrollFormula::create(['key' => $key, 'code' => $key === 'OT' ? 'F-001' : 'F-002', 'name' => $key, 'expression' => 'Display only', 'params' => $items]);
    }
    AuditLog::creating(static function (AuditLog $log): void {
        if (str_starts_with($log->details ?? '', 'NSD')) {
            throw new RuntimeException('Synthetic second-row audit failure');
        }
    });
    $result = app(SaveFormulas::class)->handle($actor, ['OT' => ['percentage' => '2'], 'NSD' => ['percentage' => '0.2']]);
    expect($result['error'])->toContain('audit record could not be recorded', 'earlier saved formulas')
        ->and(PayrollFormula::query()->where('key', 'OT')->sole()->params[1]['value'])->toBe(2)
        ->and(PayrollFormula::query()->where('key', 'NSD')->sole()->params[0]['value'])->toBe(0.1)
        ->and(AuditLog::query()->where('action', 'FORMULA_UPDATED')->count())->toBe(1);
});

it('does not reverse or duplicate a repeated intended grant state', function () {
    ['actor' => $actor, 'role' => $role] = requiredAuditFixture();
    foreach ([true, true, false, false] as $desired) {
        app(ManageRoles::class)->toggleGrant($actor, $role->id, 'LEAVES', $desired);
        expect(RoleModuleGrant::query()->where('role_id', $role->id)->where('module', 'LEAVES')->exists())->toBe($desired);
    }
    expect(AuditLog::query()->where('entity', 'role_module_grants')->where('entity_id', $role->id)->count())->toBe(2);
});

it('rolls back a successful strict audit with its enclosing business transaction', function () {
    ['actor' => $actor, 'role' => $role] = requiredAuditFixture();
    $before = requiredAuditRows();
    try {
        DB::transaction(static function () use ($actor, $role): void {
            app(ManageAccounts::class)->create($actor, 'rollback@example.test', 'Synthetic-Password#2026', $role->id, '');
            expect(AuditLog::query()->where('action', 'USER_CREATED')->count())->toBe(1);
            throw new RuntimeException('Abort outer transaction');
        });
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Abort outer transaction');
    }
    expect(requiredAuditRows())->toEqual($before);
});

it('records finalization and its notifications only once on a repeated request', function () {
    ['actor' => $actor, 'employee' => $employee, 'run' => $run] = requiredAuditFixture();
    User::factory()->create(['employee_id' => $employee->id, 'is_active' => true]);
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(1);
    expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id))->toThrow(RuntimeException::class, 'already finalized');
    expect(AuditLog::query()->where('action', 'PAYROLL_FINALIZED')->count())->toBe(1)
        ->and(Notification::query()->where('kind', 'PAYSLIP_POSTED')->count())->toBe(1);
});

it('rejects anonymous actors for mandatory audit records', function () {
    expect(fn () => RequiredAudit::record(Access::forGuest(), 'TEST', 'test', 'id', 'Synthetic'))->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect(AuditLog::query()->count())->toBe(0);
});
