<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Support\EmployeeSheet;
use App\Models\EmployeeChild;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Contracts\Views\EmployeeProfileView;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The spouse, the parents, the children and the Other info lists are saved
 * through the record's sections into rows of their own; the legacy spouse
 * columns are never written and are shown wherever no spouse part is typed.
 * A list keeps line N on row N: rows are updated in place, and only the
 * rows past the last line are deleted, by primary key.
 */

/** @param list<string> $modules */
function pdsfamRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Family '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function pdsfamUser(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return $user->refresh();
}

/** @param array<string, mixed> $attributes */
function pdsfamEmployee(array $attributes = []): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    return EmployeeProfile::query()->create($attributes + [
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
    ]);
}

function pdsfamHr(): Access
{
    return Access::forActor(pdsfamUser(pdsfamRole([ModuleCode::EMPLOYEES], true)));
}

/** @param array<string, mixed> $changes */
function pdsfamSave(Access $actor, EmployeeProfile $employee, string $section, array $changes = []): ?string
{
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));

    return app(UpdateEmployee::class)->handle($actor, $employee->id, $section, $changes + $form);
}

function pdsfamView(EmployeeProfile $employee): EmployeeProfileView
{
    $view = app(EmployeeRecords::class)->find(pdsfamHr(), $employee->id, true);
    expect($view)->not->toBeNull();

    return $view;
}

/** @return array<string, string> the employee's CSV row, keyed by header */
function pdsfamCsv(EmployeeProfile $employee): array
{
    return array_combine(EmployeeColumns::row(), EmployeeColumns::cells(pdsfamView($employee), []));
}

/** @return Collection<int, string> the SQL sent while `$run` runs */
function pdsfamQueries(Closure $run): Collection
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $run();

        // MySQL/MariaDB quote identifiers with backticks, sqlite with double
        // quotes: one spelling keeps each assertion meaningful on both.
        return collect(DB::getQueryLog())->pluck('query')->map(static fn (mixed $q): string => str_replace('`', '"', (string) $q));
    } finally {
        DB::disableQueryLog();
    }
}

/** @return list<string> one Other info list as stored, in order */
function pdsfamItems(EmployeeProfile $employee, string $kind): array
{
    return array_values(EmployeeOtherItem::query()->where('employee_id', $employee->id)->where('kind', $kind)
        ->orderBy('position')->pluck('text')->all());
}

it('saves the spouse in parts and never writes the legacy spouse columns', function () {
    $employee = pdsfamEmployee(['spouse_name' => 'Maria S. Dela Cruz', 'spouse_employment' => 'Teacher, DepEd']);
    $before = (array) DB::table('employee_profiles')->where('id', $employee->id)->first(['spouse_name', 'spouse_employment']);

    $queries = pdsfamQueries(fn () => pdsfamSave(pdsfamHr(), $employee, 'spouse', [
        'spouse_surname' => 'Dela Cruz', 'spouse_first_name' => 'Maria', 'spouse_middle_name' => 'Santos',
        'spouse_occupation' => 'Teacher', 'spouse_employer' => 'DepEd', 'spouse_business_address' => 'Tayabas City',
        'spouse_telephone' => '(042) 793-1234',
    ]));

    $details = EmployeePdsDetail::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($details->spouse_surname)->toBe('Dela Cruz')
        ->and($details->spouse_first_name)->toBe('Maria')
        ->and($details->spouse_telephone)->toBe('(042) 793-1234')
        ->and((array) DB::table('employee_profiles')->where('id', $employee->id)->first(['spouse_name', 'spouse_employment']))->toBe($before)
        ->and($queries->filter(static fn (string $q): bool => preg_match('/^(insert|update)/i', $q) === 1
            && (str_contains($q, '"spouse_name"') || str_contains($q, '"spouse_employment"'))))->toBeEmpty();
});

it('reads the legacy spouse until a part is typed, on the sheet, the CSV and the note', function () {
    $employee = pdsfamEmployee(['spouse_name' => 'Maria S. Dela Cruz', 'spouse_employment' => 'Teacher, DepEd']);
    $view = pdsfamView($employee);

    expect(EmployeeFields::legacySpouse($view))->toBe('Maria S. Dela Cruz · Teacher, DepEd')
        ->and(EmployeeFields::spouseNameText($view))->toBe('Maria S. Dela Cruz')
        ->and(EmployeeSheet::spouseName($view))->toBe('Maria S. Dela Cruz')
        ->and(EmployeeSheet::spouseEmployment($view))->toBe('Teacher, DepEd')
        ->and(pdsfamCsv($employee)["Spouse's Name"])->toBe('Maria S. Dela Cruz')
        ->and(pdsfamCsv($employee)["Spouse's Employment"])->toBe('Teacher, DepEd');

    pdsfamSave(pdsfamHr(), $employee, 'spouse', [
        'spouse_surname' => 'Dela Cruz', 'spouse_first_name' => 'Maria', 'spouse_middle_name' => 'Santos',
        'spouse_name_extension' => '', 'spouse_occupation' => 'Principal', 'spouse_employer' => 'DepEd Quezon',
    ]);
    $view = pdsfamView($employee);

    expect(EmployeeFields::legacySpouse($view))->toBe('')
        ->and(EmployeeSheet::spouseName($view))->toBe('Maria Santos Dela Cruz')
        ->and(EmployeeSheet::spouseEmployment($view))->toBe('Principal, DepEd Quezon')
        ->and(pdsfamCsv($employee)["Spouse's Name"])->toBe('Maria Santos Dela Cruz')
        ->and(pdsfamCsv($employee)['Spouse Surname'])->toBe('Dela Cruz')
        ->and($employee->refresh()->spouse_name)->toBe('Maria S. Dela Cruz');
});

it('saves the father and the mother\'s maiden name and reads each as one line', function () {
    $employee = pdsfamEmployee();

    pdsfamSave(pdsfamHr(), $employee, 'parents', [
        'father_surname' => 'Dela Cruz', 'father_first_name' => 'Pedro', 'father_middle_name' => 'Ramos', 'father_name_extension' => 'Sr.',
        'mother_surname' => 'Perez', 'mother_first_name' => 'Lourdes', 'mother_middle_name' => 'Castillo',
    ]);
    $view = pdsfamView($employee);

    expect(EmployeeFields::fatherText($view))->toBe('Dela Cruz, Pedro Ramos Sr.')
        ->and(EmployeeFields::motherText($view))->toBe('Perez, Lourdes Castillo')
        ->and(EmployeeFields::fatherText(EmployeeProfile::query()->findOrFail($employee->id)))->toBe('Dela Cruz, Pedro Ramos Sr.')
        ->and(EmployeeFields::nameLine('', '', ''))->toBe('');
});

it('saves each child\'s birthdate and refuses a malformed one', function (string $born, string $message) {
    $employee = pdsfamEmployee();
    pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => [['name' => 'Ana', 'birth_date' => '2019-05-04']]]);

    expect(EmployeeChild::query()->where('employee_id', $employee->id)->sole()->birth_date?->format('Y-m-d'))->toBe('2019-05-04')
        ->and(EmployeeFields::childRows(pdsfamView($employee)))->toBe([['name' => 'Ana', 'birth_date' => '2019-05-04']])
        ->and(fn () => pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => [['name' => 'Ana', 'birth_date' => $born]]]))
        ->toThrow(RuntimeException::class, $message)
        ->and(EmployeeChild::query()->where('employee_id', $employee->id)->sole()->birth_date?->format('Y-m-d'))->toBe('2019-05-04');
})->with([
    'not a calendar day' => ['2019-02-30', "A child's birthdate must be a real calendar date."],
    'a word' => ['tomorrow', "A child's birthdate must be a real calendar date."],
    'in the future' => [fn (): string => CarbonImmutable::parse(EmployeeFields::todayKey())->addDay()->format('Y-m-d'), "A child's birthdate cannot be in the future."],
]);

it('takes up to thirty children and refuses the thirty-first', function () {
    $employee = pdsfamEmployee();
    $children = array_map(static fn (int $i): array => ['name' => "Child {$i}", 'birth_date' => ''], range(1, 30));

    pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => $children]);

    expect(EmployeeChild::query()->where('employee_id', $employee->id)->count())->toBe(30)
        ->and(fn () => pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => [...$children, ['name' => 'Child 31', 'birth_date' => '']]]))
        ->toThrow(RuntimeException::class, 'At most 30 children can be listed.')
        ->and(EmployeeChild::query()->where('employee_id', $employee->id)->count())->toBe(30);
});

it('replaces the Other info lines in order, reusing each row for its line', function () {
    $employee = pdsfamEmployee();
    pdsfamSave(pdsfamHr(), $employee, 'skills', ['skills' => ['Carpentry', 'Chess', 'Guitar']]);
    $ids = EmployeeOtherItem::query()->where('employee_id', $employee->id)->orderBy('position')->pluck('id')->all();

    $queries = pdsfamQueries(fn () => pdsfamSave(pdsfamHr(), $employee, 'skills', ['skills' => ['Carpentry', 'Painting', '  ']]));
    $rows = EmployeeOtherItem::query()->where('employee_id', $employee->id)->orderBy('position')->get();

    expect($rows->pluck('id')->all())->toBe([$ids[0], $ids[1]])
        ->and($rows->pluck('text')->all())->toBe(['Carpentry', 'Painting'])
        ->and(EmployeeFields::itemLines(pdsfamView($employee), 'skills'))->toBe(['Carpentry', 'Painting'])
        ->and($queries->filter(static fn (string $q): bool => str_starts_with($q, 'delete from "employee_other_items"'))->values()->all())
        ->toBe(['delete from "employee_other_items" where "id" = ?']);

    pdsfamSave(pdsfamHr(), $employee, 'memberships', ['memberships' => ['Philippine Computer Society']]);

    expect(pdsfamItems($employee, 'SKILL'))->toBe(['Carpentry', 'Painting'])
        ->and(pdsfamItems($employee, 'MEMBERSHIP'))->toBe(['Philippine Computer Society'])
        ->and(EmployeeFields::readValue(pdsfamView($employee), EmployeeFields::field('distinctions') ?? []))->toBe('—');
});

it('refuses a line over 255 characters and more than thirty lines, writing nothing', function (array $lines, string $message) {
    $employee = pdsfamEmployee();

    expect(fn () => pdsfamSave(pdsfamHr(), $employee, 'distinctions', ['distinctions' => $lines]))->toThrow(RuntimeException::class, $message)
        ->and(EmployeeOtherItem::query()->count())->toBe(0);
})->with([
    'an overlong line' => [['Best in Math', str_repeat('x', 256)], 'Line 2 must be at most 255 characters.'],
    'thirty-one lines' => [array_map(static fn (int $i): string => "Award {$i}", range(1, 31)), 'At most 30 lines can be listed.'],
]);

it('removes a stored line that sits past a gap in the positions', function () {
    $employee = pdsfamEmployee();
    EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => 'SKILL', 'position' => 0, 'text' => 'Old first']);
    $far = EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => 'SKILL', 'position' => 5, 'text' => 'Old sixth']);

    pdsfamSave(pdsfamHr(), $employee, 'skills', ['skills' => ['One', 'Two', 'Three']]);

    expect(EmployeeOtherItem::query()->where('employee_id', $employee->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2])
        ->and(EmployeeOtherItem::query()->whereKey($far->id)->exists())->toBeFalse()
        ->and(EmployeeFields::itemLines(EmployeeProfile::query()->findOrFail($employee->id), 'skills'))->toBe(['One', 'Two', 'Three']);
});

it('keeps child N on row N, updating it in place and deleting extras by id', function () {
    $employee = pdsfamEmployee();
    pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => [
        ['name' => 'Ana', 'birth_date' => '2015-01-02'], ['name' => 'Ben', 'birth_date' => ''], ['name' => 'Cara', 'birth_date' => ''],
    ]]);
    $ids = EmployeeChild::query()->where('employee_id', $employee->id)->orderBy('position')->pluck('id')->all();

    $queries = pdsfamQueries(fn () => pdsfamSave(pdsfamHr(), $employee, 'children', ['children' => [
        ['name' => 'Ana', 'birth_date' => '2015-01-02'], ['name' => 'Benjamin', 'birth_date' => '2017-06-30'],
    ]]));
    $rows = EmployeeChild::query()->where('employee_id', $employee->id)->orderBy('position')->get();

    expect($rows->pluck('id')->all())->toBe([$ids[0], $ids[1]])
        ->and($rows->pluck('name')->all())->toBe(['Ana', 'Benjamin'])
        ->and($rows[1]->birth_date?->format('Y-m-d'))->toBe('2017-06-30')
        ->and(EmployeeChild::query()->whereKey($ids[2])->exists())->toBeFalse()
        ->and($queries->contains(static fn (string $q): bool => str_contains($q, 'delete from "employee_children" where "employee_id"')))->toBeFalse()
        ->and($queries->contains(static fn (string $q): bool => str_starts_with($q, 'insert into "employee_children"')))->toBeFalse();
});

it('refuses direct spouse, parents, children, Other info and declaration writes by an employee', function () {
    $employee = pdsfamEmployee();
    $self = Access::forActor(pdsfamUser(pdsfamRole([ModuleCode::PROFILE], false), $employee));
    $changes = [
        'spouse' => ['spouse_surname' => 'Dela Cruz'],
        'parents' => ['father_surname' => 'Dela Cruz'],
        'children' => ['children' => [['name' => 'Ana', 'birth_date' => '']]],
        'skills' => ['skills' => ['Chess']],
        'distinctions' => ['distinctions' => ['Best in Math']],
        'memberships' => ['memberships' => ['PCS']],
        'declarations' => ['decl_34a' => 'no'],
    ];

    foreach ($changes as $section => $change) {
        expect(fn () => pdsfamSave($self, $employee, $section, $change))->toThrow(HttpException::class);
    }

    expect(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeChild::query()->count())->toBe(0)
        ->and(EmployeeOtherItem::query()->count())->toBe(0)
        ->and(collect(EmployeeFields::sections())->where('hr', true)->pluck('id')->all())
        ->toBe(['spouse', 'parents', 'children', 'skills', 'distinctions', 'memberships', 'declarations']);
});

it('refuses a family save that carries a value while the family columns are missing, writing no column', function () {
    // Every column the family-and-declarations migration adds — the state
    // before it runs. Re-running it then appends them in their own order, so
    // the table ends as it began on engines where the drop is not rolled back.
    $fillable = (new EmployeePdsDetail)->getFillable();
    $columns = array_slice($fillable, (int) array_search('philsys_pcn', $fillable, true) + 1);
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    Schema::table('employee_pds_details', static function (Blueprint $table) use ($columns): void {
        $table->dropColumn($columns);
    });
    Once::flush();
    // On MySQL/MariaDB the DDL commits the open transaction: this test's rows
    // get one of their own, rolled back before the columns come back.
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $employee = pdsfamEmployee();
        $hr = pdsfamHr();

        expect(fn () => pdsfamSave($hr, $employee, 'spouse', ['spouse_surname' => 'Dela Cruz']))->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY)
            ->and(EmployeePdsDetail::query()->count())->toBe(0)
            ->and(pdsfamSave($hr, $employee, 'spouse'))->toBe('Spouse')
            ->and(EmployeePdsDetail::query()->count())->toBe(0);
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        (require base_path('database/migrations/2026_10_07_0003_add_family_and_declarations_to_employee_pds_details_table.php'))->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});
