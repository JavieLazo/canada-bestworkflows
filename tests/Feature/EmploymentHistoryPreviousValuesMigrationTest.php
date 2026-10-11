<?php

declare(strict_types=1);

use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Support\Access;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Previous-value migration preserves old evidence, resumes partial DDL and never invents a backfill. */
function ehPreviousMigration(): mixed
{
    return require base_path('database/migrations/2026_10_02_0004_add_previous_values_to_employee_employment_histories_table.php');
}

function ehPreviousEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create([
        'employee_code' => 'PREVIOUS-VALUES', 'first_name' => 'Previous', 'last_name' => 'Values',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 25000,
    ]);
}

it('adds previous values retry safely without rewriting old snapshots', function () {
    $migration = ehPreviousMigration();
    $migration->down();
    $employee = ehPreviousEmployee();
    $recorder = app(EmploymentHistoryRecorder::class);
    $before = $recorder::snapshot($employee);
    $employee->monthly_rate = 30000;
    $employee->save();
    $recorder->updated(Access::forGuest(), $employee, $before);
    $old = EmployeeEmploymentHistory::query()->sole();

    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('employee_employment_histories', 'previous_values'))->toBeTrue()
        ->and(Schema::hasIndex('employee_employment_histories', 'eehist_created_id_idx'))->toBeTrue()
        ->and($old->fresh()?->previous_values)->toBeNull()
        ->and($old->fresh()?->monthly_rate)->toBe(30000.0);
    $before = $recorder::snapshot($employee);
    $employee->monthly_rate = 35000;
    $employee->save();
    $recorder->updated(Access::forGuest(), $employee, $before);
    $migration->up();
    expect(EmployeeEmploymentHistory::query()->where('id', '!=', $old->id)->sole()->previous_values['monthly_rate'])
        ->toBe('30000.00');
});

it('resumes after only the previous values column has been added', function () {
    $migration = ehPreviousMigration();
    $migration->down();
    Schema::table('employee_employment_histories', function (Blueprint $t): void {
        $t->json('previous_values')->nullable();
    });
    $employee = ehPreviousEmployee();
    app(EmploymentHistoryRecorder::class)->hired(Access::forGuest(), $employee);
    $row = EmployeeEmploymentHistory::query()->sole();
    $row->previous_values = ['position' => 'Preserved captured value'];
    $row->save();

    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('employee_employment_histories', 'eehist_created_id_idx'))->toBeTrue()
        ->and($row->fresh()?->previous_values)->toBe(['position' => 'Preserved captured value']);
});

it('rolls previous values back without removing employment evidence and supports reapplication', function () {
    $migration = ehPreviousMigration();
    $employee = ehPreviousEmployee();
    app(EmploymentHistoryRecorder::class)->hired(Access::forGuest(), $employee);
    $row = EmployeeEmploymentHistory::query()->sole();
    $row->previous_values = ['monthly_rate' => '24000.00'];
    $row->save();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('employee_employment_histories', 'previous_values'))->toBeFalse()
        ->and(Schema::hasIndex('employee_employment_histories', 'eehist_created_id_idx'))->toBeFalse()
        ->and(EmployeeEmploymentHistory::query()->sole()->monthly_rate)->toBe(25000.0);
    $migration->up();
    $migration->up();
    expect(EmployeeEmploymentHistory::query()->sole()->previous_values)->toBeNull();
});
