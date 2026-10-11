<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Notification;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\IdentityAccess\Application\Commands\ManageAccounts;
use App\Modules\Payroll\Application\Commands\FinalizePayroll;
use App\Platform\Audit\RequiredAudit;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayrollReviewFixture;

/** Real commits and database-rejected inserts must preserve the strict audit boundary. */

/** @return array{actor: Access, role: Role, run: PayrollRun} */
function commitAuditFixture(): array
{
    test()->travelTo(new DateTimeImmutable('2026-09-17T03:00:00Z'));
    $role = Role::create(['name' => 'Commit test', 'all_data' => true, 'is_system' => false]);
    foreach (['USERS', 'PAYROLL'] as $module) {
        RoleModuleGrant::create(['role_id' => $role->id, 'module' => $module]);
    }
    $employee = EmployeeProfile::create(['employee_code' => 'COMMIT-001', 'first_name' => 'Synthetic', 'last_name' => 'Commit',
        'date_hired' => '2024-01-01', 'employment_status' => 'Regular', 'is_active' => true, 'monthly_rate' => 30000]);
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id, 'is_active' => true]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);
    $run = PayrollRun::create(['code' => 'PR-COMMIT', 'name' => 'Synthetic', 'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'status' => 'DRAFT']);
    PayrollRunMember::create(['run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-09-01', 'period_end' => '2026-09-15', 'earning_leave_pay' => 0, 'overtime_acknowledged' => true]);

    return ['actor' => app(Access::class), 'role' => $role, 'run' => $run];
}

it('rolls back account and payroll writes on a real audit database failure and allows a clean retry', function () {
    ['actor' => $actor, 'role' => $role, 'run' => $run] = commitAuditFixture();
    DB::unprepared("CREATE TRIGGER reject_test_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic audit storage failure'");
    try {
        expect(fn () => app(ManageAccounts::class)->create($actor, 'commit@example.test', 'Synthetic-Password#2026', $role->id, ''))
            ->toThrow(RuntimeException::class, 'audit record could not be recorded');
        expect(fn () => app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))
            ->toThrow(RuntimeException::class, 'audit record could not be recorded');
        expect(User::query()->where('email', 'commit@example.test')->exists())->toBeFalse()
            ->and($run->fresh()->status)->toBe('DRAFT')
            ->and(Payslip::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS reject_test_audit');
    }
    app(ManageAccounts::class)->create($actor, 'commit@example.test', 'Synthetic-Password#2026', $role->id, '');
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(1)
        ->and(User::query()->where('email', 'commit@example.test')->exists())->toBeTrue()
        ->and($run->fresh()->status)->toBe('FINALIZED')
        ->and(Payslip::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(2)
        ->and(Notification::query()->where('kind', 'PAYSLIP_POSTED')->count())->toBe(1);
});

it('does not announce a finalization that its outer transaction rolls back', function () {
    ['actor' => $actor, 'run' => $run] = commitAuditFixture();
    expect(fn () => DB::transaction(function () use ($actor, $run): void {
        expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(1)
            ->and(AuditLog::query()->count())->toBe(1)
            ->and(Notification::query()->count())->toBe(0);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');
    expect($run->fresh()->status)->toBe('DRAFT')
        ->and(Payslip::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
    expect(app(FinalizePayroll::class)->handle($actor, $run->id, PayrollReviewFixture::token($actor, $run->id)))->toBe(1)
        ->and(Notification::query()->count())->toBe(1);
});

it('refuses a mandatory audit outside a business transaction', function () {
    ['actor' => $actor] = commitAuditFixture();
    expect(DB::transactionLevel())->toBe(0);
    expect(fn () => RequiredAudit::record($actor, 'TEST', 'test', 'id', 'Synthetic'))
        ->toThrow(RuntimeException::class, 'audit record could not be recorded');
    expect(AuditLog::query()->count())->toBe(0);
});
