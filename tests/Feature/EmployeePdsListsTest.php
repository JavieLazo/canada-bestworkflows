<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Models\EmployeeEducation;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\DeleteEmployee;
use App\Modules\Workforce\Application\Commands\DeletePdsRow;
use App\Modules\Workforce\Application\Commands\SavePdsRow;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\Queries\PdsRows;
use App\Modules\Workforce\Contracts\Views\PdsRowView;
use App\Support\Access;
use App\Support\EmployeeName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The five Personal Data Sheet lists (schools, eligibilities, previous jobs,
 * trainings, voluntary work) are written only by Employee Records all-data
 * holders, one row at a time through SavePdsRow / DeletePdsRow, each row
 * re-resolved under its employee, and read only behind the record's own
 * visibility rule (PdsRows). Work Experience starts with the current job,
 * read from Employee Details and never stored; Educational Background shows
 * every level, with the legacy College and Graduate lines until a school of
 * that level is stored — those columns are never written.
 */

/** @param list<string> $modules */
function pdslistRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Lists '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function pdslistAccess(Role $role, ?EmployeeProfile $employee = null): Access
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return Access::forActor($user->refresh());
}

function pdslistHr(): Access
{
    return pdslistAccess(pdslistRole([ModuleCode::EMPLOYEES], true));
}

/** @param array<string, mixed> $legacy columns set by hand, as an older release stored them */
function pdslistEmployee(array $legacy = []): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Accountant II', 'position_level' => 'Senior',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-03', 'monthly_rate' => 25000, 'work_site_id' => $site->id,
    ]);
    $employee->forceFill($legacy)->save();

    return $employee->refresh();
}

/** @return array<string, string> a valid dialog draft for one list */
function pdslistRow(string $list): array
{
    return match ($list) {
        'education' => ['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'course' => 'BS Accountancy', 'attended_from' => '2008', 'attended_to' => '2012', 'units_earned' => '', 'year_graduated' => '2012', 'honors' => 'Cum laude'],
        'eligibility' => ['name' => 'Career Service Professional', 'rating' => '85.40', 'exam_date' => '2013-08-04', 'exam_place' => 'Lucena City', 'license_no' => '', 'license_valid_until' => ''],
        'experience' => ['position_title' => 'Junior Accountant', 'company' => 'ABC Trading Corporation', 'date_from' => '2013-01-07', 'date_to' => '2016-05-31', 'monthly_salary' => '18000.50', 'pay_grade' => '11-2', 'appointment_status' => 'Permanent', 'government_service' => 'N'],
        'training' => ['title' => 'Basic Tax Compliance for Payroll Officers', 'date_from' => '2023-03-06', 'date_to' => '2023-03-08', 'hours' => '24', 'ld_type' => 'Technical', 'sponsor' => 'Bureau of Internal Revenue'],
        'voluntary' => ['organization' => 'Philippine Red Cross, Tayabas Chapter', 'organization_address' => 'Tayabas City, Quezon', 'date_from' => '2015-06-01', 'date_to' => '', 'hours' => '120', 'position' => 'Volunteer'],
        default => throw new InvalidArgumentException("No sample row for [{$list}]."),
    };
}

/** The field a row is named by. */
function pdslistNameKey(string $list): string
{
    return ['education' => 'school', 'eligibility' => 'name', 'experience' => 'position_title', 'training' => 'title', 'voluntary' => 'organization'][$list];
}

/** @param array<string, string> $changes */
function pdslistAdd(Access $actor, EmployeeProfile $employee, string $list, array $changes = []): ?string
{
    return app(SavePdsRow::class)->handle($actor, $employee->id, $list, null, $changes + pdslistRow($list));
}

function pdslistCount(string $list, ?EmployeeProfile $employee = null): int
{
    $model = PdsLists::spec($list)['model'];
    $query = $model::query();

    return ($employee === null ? $query : $query->where('employee_id', $employee->id))->count();
}

/** @return array<int, PdsRowView> */
function pdslistPage(Access $actor, EmployeeProfile $employee, string $list, int $page = 1, int $perPage = 20): array
{
    return app(PdsRows::class)->page($actor, $employee->id, $list, $page, $perPage)['rows']->items();
}

/** @param array<int, PdsRowView> $rows
 * @return list<mixed> */
function pdslistPluck(array $rows, string $key): array
{
    return array_values(array_map(static fn (PdsRowView $row): mixed => $row->getAttribute($key), $rows));
}

/** Stores `$count` valid rows of one list for the employee in one insert. */
function pdslistSeed(EmployeeProfile $employee, string $list, int $count): void
{
    $model = PdsLists::spec($list)['model'];
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['id' => (string) Str::ulid(), 'employee_id' => $employee->id, 'created_at' => now()->utc()->format('Y-m-d H:i:s.v')]
            + PdsLists::columns($list, pdslistRow($list));
    }
    $model::query()->insert($rows);
}

/** The HTTP status `$run` aborted with, or null when it did not abort. */
function pdslistStatus(Closure $run): ?int
{
    try {
        $run();
    } catch (HttpException $e) {
        return $e->getStatusCode();
    }

    return null;
}

/**
 * Runs `$run` while one list's table does not exist — the release extracts
 * the files before the cron migrates — and puts it back with its own
 * migration. Table probes are answered once per request
 * (EmployeeDetailRows::hasTable), so the memo is flushed after each DDL. On
 * MySQL/MariaDB a DROP commits the open transaction, so `$run`'s rows get a
 * transaction of their own, rolled back when it ends, and the table is
 * recreated under a fresh one for RefreshDatabase to roll back.
 */
function pdslistWithoutTable(string $list, Closure $run): void
{
    $files = [
        'education' => '2026_10_07_0004_create_employee_education_table',
        'eligibility' => '2026_10_07_0005_create_employee_eligibilities_table',
        'experience' => '2026_10_07_0006_create_employee_work_experiences_table',
        'training' => '2026_10_07_0007_create_employee_trainings_table',
        'voluntary' => '2026_10_07_0008_create_employee_voluntary_works_table',
    ];
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    Schema::drop(PdsLists::spec($list)['table']);
    Once::flush();
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $run();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        (require base_path("database/migrations/{$files[$list]}.php"))->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
}

dataset('pdslist lists', PdsLists::KEYS);

it('adds, edits and deletes a row on each list through the commands', function (string $list) {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    $spec = PdsLists::spec($list);
    $key = pdslistNameKey($list);

    expect(pdslistAdd($hr, $employee, $list))->toBe($spec['one']);
    $row = $spec['model']::query()->where('employee_id', $employee->id)->sole();
    $view = app(PdsRows::class)->find($hr, $employee->id, $list, $row->id);
    $columns = PdsLists::columns($list, pdslistRow($list));

    expect($view)->toBeInstanceOf(PdsRowView::class)
        ->and($view?->kind)->toBe('row')
        ->and($view?->id)->toBe($row->id);
    foreach ($columns as $column => $value) {
        expect($view?->getAttribute($column))->toBe($value, "{$list}.{$column}");
    }

    $edited = [$key => 'Edited '.$list] + pdslistRow($list);
    expect(app(SavePdsRow::class)->handle($hr, $employee->id, $list, $row->id, $edited))->toBe($spec['one'])
        ->and(pdslistCount($list, $employee))->toBe(1)
        ->and($row->fresh()?->getAttribute($key))->toBe('Edited '.$list)
        ->and(app(DeletePdsRow::class)->handle($hr, $employee->id, $list, $row->id))->toBe(PdsLists::label($list, $edited))
        ->and(pdslistCount($list, $employee))->toBe(0)
        ->and(app(DeletePdsRow::class)->handle($hr, $employee->id, $list, $row->id))->toBeNull();
})->with('pdslist lists');

it('refuses every list write to a caller without Employee Records all-data, writing nothing', function (string $who) {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    foreach (PdsLists::KEYS as $list) {
        pdslistAdd($hr, $employee, $list);
    }
    $actor = match ($who) {
        'own' => pdslistAccess(pdslistRole([ModuleCode::EMPLOYEES], false), $employee),
        'payroll' => pdslistAccess(pdslistRole([ModuleCode::PAYROLL], true)),
        'employees' => pdslistAccess(pdslistRole([ModuleCode::EMPLOYEES], false)),
        default => throw new InvalidArgumentException("No actor [{$who}]."),
    };
    $audits = DB::table('audit_logs')->count();

    foreach (PdsLists::KEYS as $list) {
        $id = (string) PdsLists::spec($list)['model']::query()->where('employee_id', $employee->id)->value('id');
        expect(pdslistStatus(fn () => pdslistAdd($actor, $employee, $list)))->toBe(403, $list)
            ->and(pdslistStatus(fn () => app(SavePdsRow::class)->handle($actor, $employee->id, $list, $id, [pdslistNameKey($list) => 'Tampered'] + pdslistRow($list))))->toBe(403)
            ->and(pdslistStatus(fn () => app(DeletePdsRow::class)->handle($actor, $employee->id, $list, $id)))->toBe(403)
            ->and(pdslistCount($list, $employee))->toBe(1)
            ->and(PdsLists::spec($list)['model']::query()->whereKey($id)->value(pdslistNameKey($list)))->toBe(pdslistRow($list)[pdslistNameKey($list)]);
    }
    expect(DB::table('audit_logs')->count())->toBe($audits);
})->with([
    'an employee on their own record' => 'own',
    'an all-data Payroll holder without Employee Records' => 'payroll',
    'an Employee Records holder without all-data' => 'employees',
]);

it('finds nothing for a row id of another employee, so nothing is changed or deleted', function (string $list) {
    $hr = pdslistHr();
    $mine = pdslistEmployee();
    $theirs = pdslistEmployee();
    pdslistAdd($hr, $theirs, $list);
    $model = PdsLists::spec($list)['model'];
    $row = $model::query()->where('employee_id', $theirs->id)->sole();
    $key = pdslistNameKey($list);

    expect(fn () => app(SavePdsRow::class)->handle($hr, $mine->id, $list, $row->id, [$key => 'Moved'] + pdslistRow($list)))
        ->toThrow(RuntimeException::class, 'That '.PdsLists::spec($list)['one'].' was removed. Reload the record.')
        ->and(app(DeletePdsRow::class)->handle($hr, $mine->id, $list, $row->id))->toBeNull()
        ->and(app(PdsRows::class)->find($hr, $mine->id, $list, $row->id))->toBeNull()
        ->and($model::query()->whereKey($row->id)->value($key))->toBe(pdslistRow($list)[$key])
        ->and(pdslistCount($list, $mine))->toBe(0);
})->with('pdslist lists');

it('validates each list\'s fields with their messages', function (string $list, array $changes, string $message) {
    $hr = pdslistHr();
    $employee = pdslistEmployee();

    expect(fn () => pdslistAdd($hr, $employee, $list, $changes))->toThrow(RuntimeException::class, $message)
        ->and(implode("\n", PdsLists::errors($list, $changes + pdslistRow($list))))->toContain($message)
        ->and(pdslistCount($list))->toBe(0);
})->with([
    'a school with no name' => ['education', ['school' => '  '], 'Name of School is required.'],
    'a level off the list' => ['education', ['level' => 'DOCTORATE'], 'Level must be one of the listed options.'],
    'a two-digit year' => ['education', ['attended_from' => '15'], 'Attended From must be a four-digit year, like 2015.'],
    'a year before 1900' => ['education', ['year_graduated' => '1899', 'attended_from' => ''], 'Year Graduated must be a four-digit year, like 2015.'],
    'attended to before attended from' => ['education', ['attended_to' => '2007'], 'Attended to cannot be before Attended from.'],
    'graduated before attended from' => ['education', ['year_graduated' => '2006', 'attended_to' => '2008'], 'Year graduated cannot be before Attended from.'],
    'a previous job ending before it began' => ['experience', ['date_to' => '2012-12-31'], 'To cannot be before From.'],
    'a previous job with no start' => ['experience', ['date_from' => ''], 'From is required.'],
    'a salary with a thousands comma' => ['experience', ['monthly_salary' => '1,000.555'], 'Monthly Salary must be a positive number.'],
    'a negative salary' => ['experience', ['monthly_salary' => '-5'], 'Monthly Salary must be a positive number.'],
    'a salary with three decimals' => ['experience', ['monthly_salary' => '1000.555'], 'Monthly Salary must be a plain amount with at most two decimals, like 25000.50.'],
    'a Gov\'t service off the list' => ['experience', ['government_service' => 'Yes'], 'Gov\'t Service must be one of the listed options.'],
    'hours over the column' => ['training', ['hours' => '10000'], 'Hours must be at most'],
    'a training ending before it began' => ['training', ['date_to' => '2023-03-01'], 'To cannot be before From.'],
    'a date of exam that is no calendar day' => ['eligibility', ['exam_date' => '2013-02-30'], 'Date of Exam / Conferment must be a real calendar date.'],
    'a licence valid until before the exam' => ['eligibility', ['license_valid_until' => '2012-01-01'], 'Valid until cannot be before the date of exam.'],
    'an eligibility name over its column' => ['eligibility', ['name' => str_repeat('x', 256)], 'Eligibility must be at most 255 characters.'],
    'a nature of work over its column' => ['voluntary', ['position' => str_repeat('x', 151)], 'Position / Nature of Work must be at most 150 characters.'],
    'voluntary work ending before it began' => ['voluntary', ['date_to' => '2014-01-01'], 'To cannot be before From.'],
    'a tampered array for a required text' => ['training', ['title' => ['x']], 'Title is required.'],
]);

it('caps a list field at its own column, never at a profile field of the same key', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();

    // `position` is capped at 120 on the profile; Voluntary work's column takes 150.
    expect(pdslistAdd($hr, $employee, 'voluntary', ['position' => str_repeat('p', 150)]))->toBe('voluntary work')
        ->and(pdslistAdd($hr, $employee, 'eligibility', ['name' => str_repeat('n', 255)]))->toBe('eligibility');
});

it('caps each list at one hundred rows per employee', function (string $list) {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    pdslistSeed($employee, $list, PdsLists::MAX_ROWS);
    $first = (string) PdsLists::spec($list)['model']::query()->where('employee_id', $employee->id)->value('id');

    expect(fn () => pdslistAdd($hr, $employee, $list))->toThrow(RuntimeException::class, PdsLists::capMessage($list))
        ->and(pdslistCount($list, $employee))->toBe(PdsLists::MAX_ROWS)
        ->and(app(SavePdsRow::class)->handle($hr, $employee->id, $list, $first, pdslistRow($list)))->toBe(PdsLists::spec($list)['one'])
        ->and(pdslistAdd($hr, pdslistEmployee(), $list))->toBe(PdsLists::spec($list)['one']);
})->with('pdslist lists');

it('orders eligibility, training and voluntary work latest first, with undated rows last', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    foreach (['2019-05-01' => 'Older', '' => 'Undated', '2022-03-01' => 'Newer'] as $date => $name) {
        pdslistAdd($hr, $employee, 'eligibility', ['name' => $name, 'exam_date' => (string) $date]);
        pdslistAdd($hr, $employee, 'voluntary', ['organization' => $name, 'date_from' => (string) $date]);
    }
    pdslistAdd($hr, $employee, 'training', ['title' => 'Older', 'date_from' => '2020-01-10', 'date_to' => '2020-01-12']);
    pdslistAdd($hr, $employee, 'training', ['title' => 'Newer', 'date_from' => '2023-05-01', 'date_to' => '2023-05-02']);

    expect(pdslistPluck(pdslistPage($hr, $employee, 'eligibility'), 'name'))->toBe(['Newer', 'Older', 'Undated'])
        ->and(pdslistPluck(pdslistPage($hr, $employee, 'voluntary'), 'organization'))->toBe(['Newer', 'Older', 'Undated'])
        ->and(pdslistPluck(pdslistPage($hr, $employee, 'training'), 'title'))->toBe(['Newer', 'Older'])
        ->and(pdslistPluck(pdslistPage($hr, $employee, 'voluntary'), 'date_from'))->toBe(['2022-03-01', '2019-05-01', null]);
});

it('puts the current job first, from Employee Details, and previous jobs most recent first', function () {
    config(['copems.company_name' => 'Fixture Company']);
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    pdslistAdd($hr, $employee, 'experience', ['position_title' => 'Bookkeeper', 'date_from' => '2010-02-01', 'date_to' => '2012-12-31', 'government_service' => 'Y']);
    pdslistAdd($hr, $employee, 'experience');

    $page = app(PdsRows::class)->page($hr, $employee->id, 'experience', 1, 20);
    $rows = $page['rows']->items();
    $current = $rows[0];

    expect(pdslistPluck($rows, 'kind'))->toBe(['derived', 'row', 'row'])
        ->and(pdslistPluck($rows, 'position_title'))->toBe(['Accountant II', 'Junior Accountant', 'Bookkeeper'])
        ->and($current->id)->toBeNull()
        ->and($current->company)->toBe('Fixture Company')
        ->and($rows[1]->company)->toBe('ABC Trading Corporation')
        ->and($current->date_from)->toBe('2024-01-03')
        ->and($current->date_to)->toBeNull()
        ->and($current->monthly_salary)->toBe(25000.0)
        ->and($current->pay_grade)->toBe('Senior')
        ->and($current->appointment_status)->toBe(EmployeeFields::employmentStatusLabel('Regular'))
        ->and($current->government_service)->toBeFalse()
        ->and($rows[1]->date_from)->toBe('2013-01-07')
        ->and($rows[1]->date_to)->toBe('2016-05-31')
        ->and($rows[1]->monthly_salary)->toBe(18000.5)
        ->and($rows[1]->government_service)->toBeFalse()
        ->and($rows[2]->government_service)->toBeTrue()
        ->and($page['noun'])->toBe('jobs')
        ->and($page['name'])->toBe(EmployeeName::display($employee))
        ->and(pdslistCount('experience', $employee))->toBe(2);

    $employee->forceFill(['date_resigned' => '2026-06-30', 'monthly_rate' => 0])->save();
    $current = pdslistPage($hr, $employee, 'experience')[0];

    expect($current->date_to)->toBe('2026-06-30')
        ->and($current->monthly_salary)->toBeNull()
        ->and(pdslistCount('experience', $employee))->toBe(2);
});

it('lists every education level, with the legacy College and Graduate values until a school of that level is added', function () {
    $hr = pdslistHr();
    $legacy = [
        'college' => 'Colegio de la Ciudad de Tayabas', 'college_course' => 'BS Computer Science', 'college_year_graduated' => '2017',
        'graduate_school' => 'N/A', 'graduate_course' => '', 'graduate_year_graduated' => null,
    ];
    $employee = pdslistEmployee($legacy);
    $stored = fn (): array => (array) DB::table('employee_profiles')->where('id', $employee->id)->first(array_keys($legacy));
    $before = $stored();

    $page = app(PdsRows::class)->page($hr, $employee->id, 'education', 1, 20);
    $rows = $page['rows']->items();

    expect(pdslistPluck($rows, 'kind'))->toBe(['empty', 'empty', 'empty', 'legacy', 'legacy'])
        ->and(pdslistPluck($rows, 'level'))->toBe(EmployeeEducation::LEVELS)
        ->and(pdslistPluck($rows, 'id'))->toBe([null, null, null, null, null])
        ->and($rows[3]->school)->toBe('Colegio de la Ciudad de Tayabas')
        ->and($rows[3]->course)->toBe('BS Computer Science')
        ->and($rows[3]->year_graduated)->toBe('2017')
        ->and($rows[4]->school)->toBe('N/A')
        ->and($rows[4]->course)->toBeNull()
        ->and($page['noun'])->toBe('levels')
        ->and($page['legacy'])->toBe(['COLLEGE' => 'Colegio de la Ciudad de Tayabas · BS Computer Science · 2017', 'GRADUATE' => 'N/A']);

    pdslistAdd($hr, $employee, 'education');
    $page = app(PdsRows::class)->page($hr, $employee->id, 'education', 1, 20);
    $rows = $page['rows']->items();

    expect(pdslistPluck($rows, 'kind'))->toBe(['empty', 'empty', 'empty', 'row', 'legacy'])
        ->and($rows[3]->year_graduated)->toBe(2012)
        ->and($rows[3]->attended_from)->toBe(2008)
        ->and($page['legacy'])->toBe(['GRADUATE' => 'N/A'])
        ->and($page['noun'])->toBe('levels');

    pdslistAdd($hr, $employee, 'education', ['school' => 'Earlier College', 'attended_from' => '2004', 'attended_to' => '2006', 'year_graduated' => '']);
    pdslistAdd($hr, $employee, 'education', ['level' => 'ELEMENTARY', 'school' => 'Tayabas West Central School', 'attended_from' => '', 'attended_to' => '', 'year_graduated' => '']);
    $page = app(PdsRows::class)->page($hr, $employee->id, 'education', 1, 20);

    expect(pdslistPluck($page['rows']->items(), 'school'))->toBe(['Tayabas West Central School', null, null, 'Earlier College', 'Colegio de la Ciudad de Tayabas', 'N/A'])
        ->and($page['noun'])->toBe('rows')
        ->and($stored())->toBe($before);
});

it('pages each list on its own page name and clamps an overflowing page', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    pdslistSeed($employee, 'training', 25);
    $rows = app(PdsRows::class);

    $training = $rows->page($hr, $employee->id, 'training', 99, 20)['rows'];
    $education = $rows->page($hr, $employee->id, 'education', 99, 20)['rows'];
    $experience = $rows->page($hr, $employee->id, 'experience', 3, 500)['rows'];

    expect($training->getPageName())->toBe('trainingPage')
        ->and($training->currentPage())->toBe(2)
        ->and($training->total())->toBe(25)
        ->and(count($training->items()))->toBe(5)
        ->and($training->items()[0]->date_from)->toBe('2023-03-06')
        ->and($education->getPageName())->toBe('educationPage')
        ->and($education->currentPage())->toBe(1)
        ->and(count($education->items()))->toBe(5)
        ->and($experience->getPageName())->toBe('experiencePage')
        ->and($experience->perPage())->toBe(100)
        ->and($experience->currentPage())->toBe(1)
        ->and($rows->page($hr, $employee->id, 'eligibility', 1, 20)['rows']->getPageName())->toBe('eligibilityPage')
        ->and($rows->page($hr, $employee->id, 'voluntary', 1, 20)['rows']->getPageName())->toBe('voluntaryPage');
});

it('reads every list as empty while its table is missing, and refuses a write', function (string $list) {
    // Seeded inside: on MySQL/MariaDB the DROP commits whatever was written before it.
    pdslistWithoutTable($list, function () use ($list): void {
        $hr = pdslistHr();
        $employee = pdslistEmployee(['college' => 'Colegio de la Ciudad de Tayabas']);

        expect(PdsLists::ready($list))->toBeFalse()
            ->and(pdslistPluck(pdslistPage($hr, $employee, $list), 'kind'))->toBe(match ($list) {
                'education' => ['empty', 'empty', 'empty', 'legacy', 'empty'],
                'experience' => ['derived'],
                default => [],
            })
            ->and(fn () => pdslistAdd($hr, $employee, $list))->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY)
            ->and(app(PdsRows::class)->find($hr, $employee->id, $list, (string) Str::ulid()))->toBeNull()
            ->and(app(DeletePdsRow::class)->handle($hr, $employee->id, $list, (string) Str::ulid()))->toBeNull();
    });

    $hr = pdslistHr();
    $employee = pdslistEmployee();
    expect(PdsLists::ready($list))->toBeTrue()
        ->and(pdslistAdd($hr, $employee, $list))->toBe(PdsLists::spec($list)['one']);
})->with('pdslist lists');

it('reads no row for a caller who cannot open the record', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    pdslistAdd($hr, $employee, 'training');
    $id = (string) PdsLists::spec('training')['model']::query()->value('id');
    $stranger = pdslistAccess(pdslistRole([ModuleCode::EMPLOYEES], false), pdslistEmployee());
    $self = pdslistAccess(pdslistRole([ModuleCode::EMPLOYEES], false), $employee);
    $payroll = pdslistAccess(pdslistRole([ModuleCode::PAYROLL], true));

    expect(pdslistPage($stranger, $employee, 'training'))->toBe([])
        ->and(pdslistPage($stranger, $employee, 'experience'))->toBe([])
        ->and(app(PdsRows::class)->page($stranger, $employee->id, 'training', 1, 20)['name'])->toBe('')
        ->and(app(PdsRows::class)->find($stranger, $employee->id, 'training', $id))->toBeNull()
        ->and(app(PdsRows::class)->sheet($stranger, $employee->id)['training'])->toBe([])
        ->and(pdslistPluck(pdslistPage($self, $employee, 'training'), 'id'))->toBe([$id])
        ->and(app(PdsRows::class)->find($payroll, $employee->id, 'training', $id)?->id)->toBe($id);
});

it('assembles every list whole for the print, with the Other info lines', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee(['graduate_school' => 'UP Diliman']);
    pdslistSeed($employee, 'eligibility', 25);
    pdslistAdd($hr, $employee, 'experience');
    EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => EmployeeOtherItem::SKILL, 'position' => 0, 'text' => 'Bookkeeping']);

    $sheet = app(PdsRows::class)->sheet($hr, $employee->id);

    expect(count($sheet['eligibility']))->toBe(25)
        ->and(pdslistPluck($sheet['experience'], 'kind'))->toBe(['derived', 'row'])
        ->and(pdslistPluck($sheet['education'], 'kind'))->toBe(['empty', 'empty', 'empty', 'empty', 'legacy'])
        ->and($sheet['training'])->toBe([])
        ->and($sheet['other'])->toBe(['skills' => ['Bookkeeping'], 'distinctions' => [], 'memberships' => []]);
});

it('audits a list change as an employee update naming the list, without values', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    $before = DB::table('audit_logs')->max('id') ?? 0;

    pdslistAdd($hr, $employee, 'education', ['school' => 'Secret Academy of Tayabas', 'honors' => 'Valedictorian']);
    $id = (string) EmployeeEducation::query()->value('id');
    app(DeletePdsRow::class)->handle($hr, $employee->id, 'education', $id);
    $logs = DB::table('audit_logs')->where('id', '>', $before)->orderBy('id')->get();
    $expected = EmployeeName::display($employee).' — educational background updated';

    expect($logs->pluck('action')->all())->toBe(['EMPLOYEE_UPDATED', 'EMPLOYEE_UPDATED'])
        ->and($logs->pluck('entity')->all())->toBe(['employee_profiles', 'employee_profiles'])
        ->and($logs->pluck('details')->all())->toBe([$expected, $expected])
        ->and((string) json_encode($logs))->not->toContain('Secret Academy')
        ->and((string) json_encode($logs))->not->toContain('Valedictorian');
});

it('removes every list row with the employee', function () {
    $hr = pdslistHr();
    $employee = pdslistEmployee();
    foreach (PdsLists::KEYS as $list) {
        pdslistAdd($hr, $employee, $list);
    }
    EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => EmployeeOtherItem::MEMBERSHIP, 'position' => 0, 'text' => 'PICPA']);
    $employee->forceFill(['is_active' => false])->save();

    expect(app(DeleteEmployee::class)->handle($hr, $employee->id))->not->toBeNull();
    foreach (PdsLists::KEYS as $list) {
        expect(pdslistCount($list))->toBe(0, $list);
    }
    expect(EmployeeOtherItem::query()->count())->toBe(0);
});

it('names the first failing hire row by its place in the wizard and drops the drafts left blank', function () {
    $blank = PdsLists::blankRow('education', 'SECONDARY');
    $rows = [$blank, pdslistRow('education'), ['school' => ''] + pdslistRow('education')];

    // The blank first draft is not a row, but it is still drawn first: the
    // failing row is the third one on the step, and is named so.
    expect($blank)->toMatchArray(['level' => 'SECONDARY', 'school' => ''])
        ->and(PdsLists::blankRow('education', 'PHD')['level'])->toBe('ELEMENTARY')
        ->and(PdsLists::rowsError('education', $rows))->toBe('School 3: Name of School is required.')
        ->and(PdsLists::rowErrors('education', $rows))->toBe([2 => ['school' => 'Name of School is required.']])
        ->and(PdsLists::rowErrors('education', [$blank, pdslistRow('education')]))->toBe([])
        ->and(PdsLists::rowsError('education', [$blank, pdslistRow('education')]))->toBeNull()
        ->and(PdsLists::rows('education', [$blank, pdslistRow('education')]))->toBe([PdsLists::columns('education', pdslistRow('education'))])
        ->and(PdsLists::rowsError('training', array_fill(0, PdsLists::MAX_ROWS + 1, pdslistRow('training'))))->toBe('At most 100 trainings can be listed.')
        ->and(PdsLists::subtitle('eligibility', 'Juan Dela Cruz Jr.'))->toBe('Career service, board and bar eligibilities and licences held by Juan Dela Cruz Jr.')
        ->and(PdsLists::subtitle('eligibility', 'Rosa Villanueva'))->toBe('Career service, board and bar eligibilities and licences held by Rosa Villanueva.')
        ->and(PdsLists::label('experience', pdslistRow('experience')))->toBe('Junior Accountant, ABC Trading Corporation');
});
