<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Payroll\Index as PayrollIndex;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Project;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Organization\Application\Queries\HolidayRecords;
use App\Modules\Organization\Contracts\Views\HolidayView;
use App\Support\Access;
use App\Support\CodeSort;
use Database\Seeders\PortSeeder;
use Database\Seeders\ReferenceListSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;

/**
 * The conversion from `PREFIX-NNN` to plain numbers — the migration
 * 2026_09_19_0002 and the guarantees it has to hold on the way.
 *
 * It rewrites live production data from a cron that fires every minute on a
 * host with no shell, so the three things pinned here are the three that would
 * hurt: it must be safe to run twice, it must refuse rather than collide, and
 * it must leave what it does not understand alone. The fourth — that the mint
 * keeps working while a table is half-converted — is in CodeSequenceTest.
 */
function plainIdsProject(string $code, string $name): Project
{
    $project = new Project;
    $project->forceFill([
        'code' => $code,
        'name' => $name,
        'status' => 'ACTIVE',
        'start_date' => '2026-01-01',
    ])->save();

    return $project;
}

/**
 * A converted table's row. Holidays stand in for "any of the eleven" in the
 * cases below: projects and tasks CANNOT play that part — Project Management
 * keeps `PRJ-001`/`TSK-014` and the migration never touches those two tables.
 */
function plainIdsHoliday(string $code, string $name): Holiday
{
    $holiday = new Holiday;
    $holiday->forceFill([
        'code' => $code,
        'name' => $name,
        'date' => '2026-12-30',
        'schedule' => 'ANNUAL',
        'type' => 'REGULAR',
        'percent' => 200,
    ])->save();

    return $holiday;
}

function plainIdsEmployee(string $code): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Garcia '.$code,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

it('strips the prefix and the padding, keeping the number itself', function () {
    plainIdsEmployee('COP-00042');
    Holiday::query()->create(['code' => 'HOL-007', 'name' => 'Rizal Day', 'date' => '2026-12-30',
        'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200]);
    Position::query()->create(['code' => 'POS-003', 'name' => 'Driver']);

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    // 42, not 1: the number a payslip already quotes is the number that stays.
    expect(EmployeeProfile::query()->value('employee_code'))->toBe('42')
        ->and(Holiday::query()->value('code'))->toBe('7')
        ->and(Position::query()->value('code'))->toBe('3');
});

it('is safe to run twice, because the release cron fires every minute', function () {
    plainIdsEmployee('COP-00008');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();
    $migration->up();
    $migration->up();

    expect(EmployeeProfile::query()->value('employee_code'))->toBe('8')
        ->and(EmployeeProfile::query()->count())->toBe(1);
});

it('puts the prefix and the padding back on rollback', function () {
    plainIdsEmployee('COP-00042');
    Holiday::query()->create(['code' => 'HOL-007', 'name' => 'Rizal Day', 'date' => '2026-12-30',
        'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 200]);

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();
    $migration->down();

    // Each column's own width, which is why the widths are in the allowlist.
    expect(EmployeeProfile::query()->value('employee_code'))->toBe('COP-00042')
        ->and(Holiday::query()->value('code'))->toBe('HOL-007');
});

it('refuses a table where two codes would become the same number, and changes nothing', function () {
    // The pad-width pair: both of these are forty-two.
    plainIdsEmployee('COP-00042');
    plainIdsEmployee('COP-42');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');

    expect(static fn () => $migration->up())->toThrow(RuntimeException::class);

    expect(EmployeeProfile::query()->orderBy('employee_code')->pluck('employee_code')->all())
        ->toBe(['COP-00042', 'COP-42']);
});

it('refuses when the number is already taken by a row outside the prefix', function () {
    plainIdsEmployee('COP-00007');
    plainIdsEmployee('7');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');

    expect(static fn () => $migration->up())->toThrow(RuntimeException::class);

    expect(EmployeeProfile::query()->where('employee_code', 'COP-00007')->exists())->toBeTrue();
});

it('leaves a code it cannot read verbatim, and converts the rest of the table', function () {
    plainIdsHoliday('HOL-004', 'Rizal Day');
    plainIdsHoliday('HOL-LEGACY', 'Imported');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    expect(Holiday::query()->where('name', 'Rizal Day')->value('code'))->toBe('4')
        // An import with no number in it has nothing to keep; it is reported
        // through Log::error rather than coerced into something invented.
        ->and(Holiday::query()->where('name', 'Imported')->value('code'))->toBe('HOL-LEGACY');
});

it('leaves Project Management ids alone, prefix and padding intact', function () {
    // THE ONE EXCLUSION. projects.code and project_tasks.code are deliberately
    // not in the migration's allowlist — they stay PRJ-001 and TSK-014 while
    // the other eleven columns lose their prefix. A future "let's finish the
    // job" edit to TARGETS fails right here.
    plainIdsProject('PRJ-004', 'Website Revamp');
    $employee = plainIdsEmployee('COP-00009');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    expect(Project::query()->where('name', 'Website Revamp')->value('code'))->toBe('PRJ-004')
        // …while the row beside it in the same run did convert, so this is not
        // passing because the migration did nothing at all.
        ->and($employee->fresh()?->employee_code)->toBe('9');

    // And rollback leaves them alone too — down() re-prefixes only what up()
    // could have written, and it never wrote to these tables.
    $migration->down();

    expect(Project::query()->where('name', 'Website Revamp')->value('code'))->toBe('PRJ-004');
});

it('touches only the eleven minted columns, never the other *_code fields', function () {
    // employee_profiles carries five hand-entered code columns, and the demo
    // data puts the literal COP in one of them.
    $employee = plainIdsEmployee('COP-00003');
    $employee->forceFill([
        'agency_code' => 'COP', 'bank_code' => 'BPI-001',
        'pay_code' => 'PAY-002', 'section_code' => 'SEC-003', 'tax_code' => 'TAX-004',
    ])->save();

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    $fresh = $employee->fresh();

    expect($fresh->employee_code)->toBe('3')
        ->and($fresh->agency_code)->toBe('COP')
        ->and($fresh->bank_code)->toBe('BPI-001')
        ->and($fresh->pay_code)->toBe('PAY-002')
        ->and($fresh->section_code)->toBe('SEC-003')
        ->and($fresh->tax_code)->toBe('TAX-004');
});

it('converts every one of the eleven columns it names, and leaves no prefix behind', function () {
    plainIdsEmployee('COP-00001');
    PayrollRun::query()->create(['code' => 'PR-002', 'name' => 'August 2026', 'period_start' => '2026-08-01',
        'period_end' => '2026-08-15', 'status' => 'DRAFT']);
    plainIdsHoliday('HOL-003', 'Rizal Day');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    expect(EmployeeProfile::query()->value('employee_code'))->toBe('1')
        ->and(PayrollRun::query()->value('code'))->toBe('2')
        ->and(Holiday::query()->value('code'))->toBe('3');

    // The allowlist, spelled out again here on purpose: if a twelfth coded
    // table is ever added, this list and the migration's have to be compared
    // by a human, and a test that names them is where that happens. Note the
    // employees column is `employee_code`, not `code` — the one difference the
    // migration's tuples exist to carry. `projects.code` and
    // `project_tasks.code` are ABSENT on purpose; the exclusion has its own
    // test above.
    $columns = [
        ['employee_profiles', 'employee_code'], ['payroll_runs', 'code'],
        ['leave_travel_requests', 'code'], ['holidays', 'code'],
        ['shift_types', 'code'], ['payroll_formulas', 'code'], ['positions', 'code'],
        ['leave_types', 'code'], ['payroll_deductions', 'code'], ['document_types', 'code'],
        ['employment_statuses', 'code'],
    ];

    foreach ($columns as [$table, $column]) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column} is missing");
        expect(DB::table($table)->where($column, 'like', '%-%')->count())
            ->toBe(0, "{$table}.{$column} still holds a prefixed id");
    }

    $targets = array_map(
        static fn (array $target): array => [$target[0], $target[1]],
        (array) (new ReflectionClassConstant($migration, 'TARGETS'))->getValue(),
    );
    expect($targets)->toBe($columns);

    // Coded tables created after the conversion, compared here on purpose:
    // `org_nodes.code` (departments and units, 2026_10_07_0011) has minted
    // bare numbers from its first row, so it has no prefix to strip and must
    // stay OUT of the migration's list.
    foreach ([['org_nodes', 'code']] as $bornBare) {
        expect(Schema::hasColumn($bornBare[0], $bornBare[1]))->toBeTrue("{$bornBare[0]}.{$bornBare[1]} is missing")
            ->and(in_array($bornBare, $targets, true))->toBeFalse("{$bornBare[0]}.{$bornBare[1]} must not be converted");
    }
});

/*
|--------------------------------------------------------------------------
| The order those numbers come back in
|--------------------------------------------------------------------------
|
| Padded ids sort correctly as text, which is exactly why removing the pad is
| dangerous: a suite of padded ids cannot tell the two orders apart. Both
| halves are pinned here — the SQL one (SqlText::orderNumeric) and the PHP one
| (CodeSort) — with ids whose text and numeric orders DISAGREE.
*/

/**
 * An all-data actor holding the modules a converted table's read needs.
 *
 * @param  list<string>  $modules
 */
function plainIdsActor(array $modules): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Ids '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey()]);
    test()->actingAs($user);

    return Access::forActor($user);
}

/**
 * Holiday codes in the order SQL returns them for the `id` column — the
 * paginated read that goes through `QueryPage::sort(..., ['id'])` and so
 * through `SqlText::orderNumeric`.
 *
 * @return list<string>
 */
function holidayCodesInIdOrder(Access $actor): array
{
    return array_values(app(HolidayRecords::class)->page($actor, 'ALL', '', 'id', 'asc', 1, 50)
        ->getCollection()->map(static fn (HolidayView $row): string => $row->code)->all());
}

it('orders a SQL-paginated list by the number, not by the text', function () {
    $actor = plainIdsActor([ModuleCode::RECORDS]);

    foreach (['2', '10', '1', '11', '9'] as $code) {
        plainIdsHoliday($code, 'Holiday '.$code);
    }

    $codes = holidayCodesInIdOrder($actor);

    // As text this reads 1, 10, 11, 2, 9 — the order bare ids must never come
    // back in.
    expect($codes)->toBe(['1', '2', '9', '10', '11']);
});

it('files an id it could not convert after every number, in SQL as in PHP', function () {
    $actor = plainIdsActor([ModuleCode::RECORDS]);

    plainIdsHoliday('2', 'Two');
    plainIdsHoliday('10', 'Ten');
    plainIdsHoliday('HOL-LEGACY', 'Imported');

    $codes = holidayCodesInIdOrder($actor);

    // 'HOL-LEGACY' + 0 is 0 in SQL, so a numeric sort alone would file it in
    // front of 2 — while CodeSort files it last. The two have to agree.
    expect($codes)->toBe(['2', '10', 'HOL-LEGACY']);

    $php = ['HOL-LEGACY', '10', '2'];
    usort($php, CodeSort::compare(...));
    expect($php)->toBe($codes);
});

it('orders a PHP-sorted list by the number too', function () {
    plainIdsActor([ModuleCode::PAYROLL]);

    foreach (['2', '10', '1'] as $code) {
        PayrollRun::query()->create(['code' => $code, 'name' => 'Payroll '.$code,
            'period_start' => '2026-08-01', 'period_end' => '2026-08-15', 'status' => 'DRAFT']);
    }

    $rows = Livewire::test(PayrollIndex::class)->call('sortBy', 'id')->viewData('rows')->items();

    expect(array_map(static fn ($run): string => $run->code, $rows))->toBe(['1', '2', '10']);
});

it('re-seeds a database the conversion has not reached without duplicating it', function () {
    // ProductionSeeder is re-runnable on the live host and matches reference
    // rows by id. Against a restored backup that still holds POS-001, a seeder
    // looking only for '1' would insert a second copy of every catalogue.
    Position::query()->create(['code' => 'POS-001', 'name' => 'Driver']);
    Position::query()->create(['code' => 'POS-002', 'name' => 'Guard']);

    $this->seed(ReferenceListSeeder::class);

    expect(Position::query()->where('code', 'POS-001')->count())->toBe(1)
        ->and(Position::query()->where('name', 'Driver')->count())->toBe(1)
        // …and the rows it added for the first time carry the bare shape.
        ->and(Position::query()->where('code', 'like', '%-%')->count())->toBe(2);
});

it('seeds bare ids into a converted database', function () {
    $this->seed(ReferenceListSeeder::class);

    expect(Position::query()->where('code', 'like', '%-%')->count())->toBe(0)
        ->and(Position::query()->where('code', '1')->exists())->toBeTrue();

    // A second run is the production case, and must change nothing.
    $before = Position::query()->count();
    $this->seed(ReferenceListSeeder::class);

    expect(Position::query()->count())->toBe($before);
});

it('names every id it will not convert in the log, including ones it never read', function () {
    $log = Log::spy();

    // Read and declined: it carries this column's prefix.
    plainIdsHoliday('HOL-EXP', 'Exported');
    // Never read at all: the LIKE filter excludes both of these, and they are
    // the ones an operator has no other way to find on a host with no shell.
    plainIdsHoliday('OLD-3', 'Imported');
    plainIdsHoliday('N/A', 'Unnumbered');
    plainIdsHoliday('HOL-004', 'Website');

    $migration = require base_path('database/migrations/2026_09_19_0002_convert_codes_to_plain_numbers_tables.php');
    $migration->up();

    expect(Holiday::query()->where('name', 'Website')->value('code'))->toBe('4');

    $log->shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'holidays.code')
            && str_contains($message, 'HOL-EXP')
            && str_contains($message, 'OLD-3')
            && str_contains($message, 'N/A'))
        ->once();
});

it('resolves the demo employees whichever id shape the database holds', function () {
    // The quiet failure this guards: with no match, employeeIdsByIndex()
    // returns [] and ShiftSeeder, AttendanceSeeder, LeaveSeeder and
    // ProjectSeeder all seed nothing at all, without an error.
    plainIdsEmployee('COP-00001');
    plainIdsEmployee('2');

    $seeder = new class extends PortSeeder
    {
        /** @return array<int, string> */
        public function ids(): array
        {
            return $this->employeeIdsByIndex();
        }
    };

    expect(array_keys($seeder->ids()))->toBe([0, 1]);
});
