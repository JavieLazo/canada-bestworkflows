<?php

declare(strict_types=1);

use App\Livewire\Payroll\RunWorkspace;
use App\Models\EmployeeProfile;
use App\Models\LeaveTravelRequest;
use App\Models\PayrollRun;
use App\Models\PayrollRunMember;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ScheduleFixture;

/**
 * DIVERGENCE 6 reaches the POSTED payslip, and its column survives a retry.
 *
 * The arithmetic itself is pinned in tests/Unit/PayrollLeavePayTest.php against
 * the pure engine. What this file holds is the wiring the engine cannot prove:
 * a real approved leave row in the database becomes `paid_leave_days` and
 * `leave_pay` on a FINALIZED payslip — which only works if the column exists
 * AND `Payslip::$fillable` and `$casts` both name it, because `fill()` drops an
 * unfillable key in silence.
 */
/** An all-data payroll administrator. Helpers are file-prefixed: one global namespace. */
function paidLeaveActor(): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Payroll '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();

    $grant = new RoleModuleGrant;
    $grant->forceFill(['role_id' => $role->getKey(), 'module' => 'PAYROLL'])->save();

    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => null]);

    test()->actingAs($user);

    return $user;
}

it('adds the paid_leave_days column only once, so a killed cron run resumes', function () {
    $migration = require base_path('database/migrations/2026_09_20_0001_add_paid_leave_days_to_payslips_table.php');

    expect(Schema::hasColumn('payslips', 'paid_leave_days'))->toBeTrue();

    // Re-running a migration the host already applied is the normal case on a
    // shared-hosting cron that can be killed between two statements.
    $migration->up();

    expect(Schema::hasColumn('payslips', 'paid_leave_days'))->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('payslips', 'paid_leave_days'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('payslips', 'paid_leave_days'))->toBeTrue();
});

it('stores the paid leave day count and its pay on a finalized payslip', function () {
    // August 2026 has 21 Mon-Fri days, so daily is 30000/21 = 1428.5714...
    // Monday 3 August is a scheduled day taken as whole-day paid leave, and
    // there are no punches at all in the cut-off.
    test()->travelTo(new DateTimeImmutable('2026-08-20T12:00:00+08:00'));

    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'PLD-001', 'first_name' => 'Rosa', 'last_name' => 'Villanueva',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01',
        'monthly_rate' => 30000, 'is_active' => true,
    ])->save();
    ScheduleFixture::standard($employee);

    LeaveTravelRequest::create([
        'code' => 'PLD-LV-1',
        'employee_id' => $employee->id,
        'request_type' => 'LEAVE',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-03',
        'reason' => 'Sick',
        'status' => 'APPROVED',
        'is_paid' => true,
    ]);

    paidLeaveActor();

    $run = new PayrollRun;
    $run->forceFill([
        'code' => 'PLD-RUN-1', 'name' => 'August 2026 — paid leave',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT',
    ])->save();

    PayrollRunMember::create([
        'run_id' => $run->id, 'employee_id' => $employee->id, 'position' => 0,
        'period_start' => '2026-08-01', 'period_end' => '2026-08-15',
    ]);

    Livewire::test(RunWorkspace::class, ['id' => $run->id])->call('finalize');

    $slip = Payslip::query()->where('run_id', $run->id)->firstOrFail();

    expect($run->refresh()->status)->toBe('FINALIZED')
        // The day left work_days and landed on the Leave line, with the pesos.
        ->and($slip->paid_leave_days)->toBe(1.0)
        ->and($slip->work_days)->toBe(0.0)
        ->and($slip->leave_pay)->toBe(1428.57)
        ->and($slip->work_days_pay)->toBe(0.0)
        ->and($slip->gross_pay)->toBe(1428.57);
});
