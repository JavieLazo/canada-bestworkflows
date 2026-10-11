<?php

declare(strict_types=1);

use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeProfile;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\EmploymentHistoryRecorder;
use App\Modules\Workforce\Application\Queries\EmploymentHistoryRecords;
use App\Support\Access;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employment history schema: retry-safe creation and preserved name snapshots.
 */
function ehistMigration(): mixed
{
    return require base_path('database/migrations/2026_10_02_0001_create_employee_employment_histories_table.php');
}

function ehistBackfillMigration(): mixed
{
    return require base_path('database/migrations/2026_10_02_0002_backfill_employee_employment_histories_table.php');
}

function ehistSiteNameMigration(): mixed
{
    return require base_path('database/migrations/2026_10_02_0003_add_work_site_name_to_employee_employment_histories_table.php');
}

function ehistSiteNameEmployee(string $code, ?string $siteId): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Site',
        'last_name' => 'History',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'work_site_id' => $siteId,
        'monthly_rate' => 30000,
    ])->save();

    return $employee;
}

it('creates the employment history table retry-safely', function () {
    $migration = ehistMigration();

    $migration->down();
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('employee_employment_histories'))->toBeTrue()
        ->and(Schema::hasIndex('employee_employment_histories', 'eehist_employee_created_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('employee_employment_histories', ['employee_id']))->toBeTrue();
});

it('stores employment snapshots and cascades with the employee', function () {
    ehistMigration()->up();

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-HIST',
        'first_name' => 'History',
        'last_name' => 'Employee',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
    ]);

    $row = new EmployeeEmploymentHistory;
    $row->fill([
        'employee_id' => $employee->id,
        'event_type' => 'HIRED',
        'effective_date' => '2024-01-01',
        'changed_fields' => 'position,monthly_rate',
        'position' => 'Driver',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
        'monthly_rate' => 30000,
        'daily_rate' => null,
    ]);
    $row->recorded_by = 123;
    $row->recorded_by_name = 'Recorder';
    $row->save();

    expect(EmployeeEmploymentHistory::query()->count())->toBe(1)
        ->and(EmployeeEmploymentHistory::query()->first()?->monthly_rate)->toBe(30000.0);

    $employee->delete();

    expect(EmployeeEmploymentHistory::query()->count())->toBe(0);
});

it('resumes when a previous run created only the table', function () {
    ehistMigration()->down();

    Schema::create('employee_employment_histories', function (Blueprint $t): void {
        $t->engine = 'InnoDB';
        $t->char('id', 26)->primary();
        $t->char('employee_id', 26);
        $t->string('event_type', 40);
        $t->date('effective_date');
        $t->string('changed_fields', 500)->nullable();
        $t->string('position', 120)->nullable();
        $t->string('position_level', 60)->nullable();
        $t->string('employment_status', 60)->nullable();
        $t->char('work_site_id', 26)->nullable();
        $t->date('date_hired')->nullable();
        $t->date('date_regular')->nullable();
        $t->date('date_resigned')->nullable();
        $t->boolean('is_active')->default(true);
        $t->decimal('monthly_rate', 12, 2)->default(0);
        $t->decimal('daily_rate', 12, 2)->nullable();
        $t->unsignedBigInteger('recorded_by')->nullable();
        $t->string('recorded_by_name', 200)->nullable();
        $t->dateTime('created_at', 3);
    });

    ehistMigration()->up();

    expect(Schema::hasIndex('employee_employment_histories', 'eehist_employee_created_idx'))->toBeTrue()
        ->and(Schema::hasForeignKey('employee_employment_histories', ['employee_id']))->toBeTrue();
});

it('leaves employee details usable before the history migration has run', function () {
    $migration = ehistMigration();
    $migration->down();

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-NO-HIST',
        'first_name' => 'Pending',
        'last_name' => 'Migration',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
    ]);
    $actor = Access::forGuest();

    expect(app(EmploymentHistoryRecords::class)->forEmployee($actor, $employee->id))->toBeEmpty()
        ->and(app(EmploymentHistoryRecords::class)->start($actor, $employee->id))->toBeNull();
    app(EmploymentHistoryRecorder::class)->hired($actor, $employee);

    expect(Schema::hasTable('employee_employment_histories'))->toBeFalse();

    $migration->up();
});

it('backfills current employee details as baseline history once the table exists', function () {
    ehistMigration()->up();
    $backfill = ehistBackfillMigration();

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-REGULAR',
        'first_name' => 'Regularized',
        'last_name' => 'Employee',
        'position' => 'Driver',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'date_regular' => '2024-07-01',
        'monthly_rate' => 35000,
        'daily_rate' => 1500,
    ]);

    $backfill->up();
    $backfill->up();

    $history = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole();

    expect($history->event_type)->toBe('BASELINE')
        ->and($history->effective_date->format('Y-m-d'))->toBe('2024-07-01')
        ->and($history->employment_status)->toBe('Regular')
        ->and($history->date_regular?->format('Y-m-d'))->toBe('2024-07-01')
        ->and($history->monthly_rate)->toBe(35000.0)
        ->and($history->daily_rate)->toBe(1500.0)
        ->and($history->recorded_by)->toBeNull()
        ->and($history->recorded_by_name)->toBe('System backfill');
});

it('does not backfill employees that already have history', function () {
    ehistMigration()->up();
    $backfill = ehistBackfillMigration();

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-EXISTS',
        'first_name' => 'Existing',
        'last_name' => 'History',
        'employment_status' => 'Probationary',
        'date_hired' => '2024-01-01',
        'monthly_rate' => 30000,
    ]);

    app(EmploymentHistoryRecorder::class)->hired(Access::forGuest(), $employee);

    $backfill->up();

    expect(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->value('event_type'))->toBe('HIRED');
});

it('records before the name migration and picks up the column on the next write', function () {
    $migration = ehistSiteNameMigration();
    $migration->down();
    $site = new WorkSite;
    $site->forceFill(['name' => str_repeat('S', 160)])->save();
    $employee = ehistSiteNameEmployee('COP-NAME-PENDING', $site->id);
    $recorder = app(EmploymentHistoryRecorder::class);

    $recorder->hired(Access::forGuest(), $employee);

    expect(Schema::hasColumn('employee_employment_histories', 'work_site_name'))->toBeFalse()
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $migration->up();
    $migration->up();
    $first = EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole();

    expect($first->work_site_name)->toBe(str_repeat('S', 160));

    $site->name = 'Renamed worksite';
    $site->save();
    $before = EmploymentHistoryRecorder::snapshot($employee);
    $employee->monthly_rate = 35000;
    $employee->save();
    $recorder->updated(Access::forGuest(), $employee, $before);

    $migration->up();

    expect($first->fresh()?->work_site_name)->toBe(str_repeat('S', 160))
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)
            ->where('event_type', 'UPDATED')->sole()->work_site_name)->toBe('Renamed worksite');
});

it('resumes a partial name backfill without replacing saved names or inventing missing names', function () {
    $migration = ehistSiteNameMigration();
    $migration->down();
    $site = new WorkSite;
    $site->forceFill(['name' => 'Available worksite'])->save();
    $deletedSite = new WorkSite;
    $deletedSite->forceFill(['name' => 'Deleted before name backfill'])->save();

    $saved = ehistSiteNameEmployee('COP-NAME-SAVED', $site->id);
    $pending = ehistSiteNameEmployee('COP-NAME-MISSING', $site->id);
    $missing = ehistSiteNameEmployee('COP-SITE-MISSING', $deletedSite->id);
    $unassigned = ehistSiteNameEmployee('COP-SITE-NONE', null);
    foreach ([$saved, $pending, $missing, $unassigned] as $employee) {
        app(EmploymentHistoryRecorder::class)->hired(Access::forGuest(), $employee);
    }
    $deletedSite->delete();

    // Simulate a cron that added the column and copied one name before stopping.
    Schema::table('employee_employment_histories', function (Blueprint $t): void {
        $t->string('work_site_name', 160)->nullable();
    });
    EmployeeEmploymentHistory::query()->where('employee_id', $saved->id)
        ->update(['work_site_name' => 'Saved before a rename']);

    $migration->up();
    $migration->up();

    expect(EmployeeEmploymentHistory::query()->where('employee_id', $saved->id)->sole()->work_site_name)
        ->toBe('Saved before a rename')
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $pending->id)->sole()->work_site_name)
        ->toBe('Available worksite')
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $missing->id)->sole()->work_site_name)
        ->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $missing->id)->sole()->work_site_id)
        ->toBe($deletedSite->id)
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $unassigned->id)->sole()->work_site_name)
        ->toBeNull()
        ->and(EmployeeEmploymentHistory::query()->count())->toBe(4);
});

it('rolls back name snapshots without deleting history and can run again', function () {
    $migration = ehistSiteNameMigration();
    $migration->up();
    $site = new WorkSite;
    $site->forceFill(['name' => 'Rollback worksite'])->save();
    $employee = ehistSiteNameEmployee('COP-NAME-ROLLBACK', $site->id);
    app(EmploymentHistoryRecorder::class)->hired(Access::forGuest(), $employee);

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('employee_employment_histories', 'work_site_name'))->toBeFalse()
        ->and(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->count())->toBe(1);

    $migration->up();
    $migration->up();

    expect(EmployeeEmploymentHistory::query()->where('employee_id', $employee->id)->sole()->work_site_name)
        ->toBe('Rollback worksite');
});
