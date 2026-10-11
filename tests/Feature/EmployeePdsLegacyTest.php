<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Show;
use App\Models\EmployeeEducation;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\Commands\SavePdsRow;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Modules\Workforce\Application\PdsLists;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Support\Access;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Structured else legacy, on every surface: the Employee Records CSV keeps
 * every existing header in name and position and appends the new columns,
 * and its frozen spouse, college and graduate columns — like the 201 sheet —
 * show the typed parts and the latest school of a level once they exist,
 * else the stored legacy text. No form, command or the wizard ever writes a
 * legacy column.
 */

/** The legacy columns no form writes. */
const PDSLEG_COLUMNS = [
    'address', 'citizenship', 'spouse_name', 'spouse_employment', 'college', 'college_course',
    'college_year_graduated', 'graduate_school', 'graduate_course', 'graduate_year_graduated',
];

function pdslegHr(): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Legacy HR '.Str::random(6), 'all_data' => true, 'is_system' => false])->save();
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id])->save();
    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function pdslegSite(): WorkSite
{
    Position::query()->create(['code' => 'POS-LEG', 'name' => 'Developer']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-LEG1', 'name' => 'Probationary']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-LEG2', 'name' => 'Regular']);

    return WorkSite::query()->create([
        'name' => 'Work From Home', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);
}

/** @param array<string, mixed> $attributes */
function pdslegEmployee(WorkSite $site, array $attributes = []): EmployeeProfile
{
    return EmployeeProfile::query()->create($attributes + [
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
    ]);
}

/** @return array<string, mixed> a record that carries only legacy values */
function pdslegLegacy(): array
{
    return [
        'address' => 'Brgy. Wakas, Tayabas City', 'citizenship' => 'Filipino',
        'spouse_name' => 'Maria S. Dela Cruz', 'spouse_employment' => 'Teacher, DepEd',
        'college' => 'Colegio de la Ciudad de Tayabas', 'college_course' => 'BS Computer Science', 'college_year_graduated' => '2017',
        'graduate_school' => 'Manuel S. Enverga University', 'graduate_course' => 'MIT', 'graduate_year_graduated' => 'N/A',
    ];
}

/** @return array<string, string> the employee's CSV row, keyed by header */
function pdslegCsv(Access $hr, EmployeeProfile $employee): array
{
    $view = app(EmployeeRecords::class)->list($hr, true)->firstWhere('id', $employee->id);
    expect($view)->not->toBeNull();

    return array_combine(EmployeeColumns::row(), EmployeeColumns::cells($view, []));
}

/** The print-only 201 sheet on the record page. */
function pdslegSheet(EmployeeProfile $employee): string
{
    $html = Livewire::test(Show::class, ['employee' => $employee->id])->html();
    $start = (int) strpos($html, '<section class="sheet-201');

    return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
}

/** @return array<string, mixed> the ten legacy columns as stored */
function pdslegStored(string $id): array
{
    return (array) DB::table('employee_profiles')->where('id', $id)->first(PDSLEG_COLUMNS);
}

/** @return Collection<int, string> the SQL sent while `$run` runs */
function pdslegQueries(Closure $run): Collection
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

/* |---| The CSV |---| */

it('keeps every existing CSV header in name and position and appends the new columns at the end', function () {
    expect(EmployeeColumns::row())->toBe([
        'ID',
        'Employee ID', 'First Name', 'Middle Name', 'Last Name', 'Suffix', 'Contact Number', 'Telephone Number', 'Email',
        'Birthday', 'Place of Birth', 'Address', 'Gender', 'Civil Status', 'Citizenship', 'Religion', 'Height', 'Weight',
        'Blood Type', 'Emergency Contact Name', 'Emergency Contact Relationship', 'Emergency Contact Number',
        'Emergency Contact Address', 'Position', 'Position Level', 'Employment Status', 'Assigned Worksite', 'Date Joined',
        'Date Regularized', 'Date Resigned', 'Active employee', 'Rate Per Month', 'Rate Per Day', "Spouse's Name",
        "Spouse's Employment", 'Children', 'College', 'College Course', 'College Year Graduated', 'Graduate Studies',
        'Graduate Course/Masteral', 'Graduate Year Graduated', 'SSS Number', 'PhilHealth Number', 'Pag-IBIG Number', 'TIN',
        'Bank and Branch', 'Account Number', 'Bank Code (SWIFT)', 'Bank Type',
        // Appended, in sections() order.
        'Civil Status Specify', 'Citizenship Type', 'Dual Citizenship By', 'Dual Citizenship Country',
        'Residential House/Block/Lot No.', 'Residential Street', 'Residential Subdivision/Village', 'Residential Barangay',
        'Residential City/Municipality', 'Residential Province', 'Residential ZIP code',
        'Permanent Address Same as Residential',
        'Permanent House/Block/Lot No.', 'Permanent Street', 'Permanent Subdivision/Village', 'Permanent Barangay',
        'Permanent City/Municipality', 'Permanent Province', 'Permanent ZIP code',
        'UMID ID No.', 'PhilSys Card No.',
        'Spouse Surname', 'Spouse First Name', 'Spouse Name Extension', 'Spouse Middle Name', 'Spouse Occupation',
        'Spouse Employer / Business Name', 'Spouse Business Address', 'Spouse Telephone No.',
        'Father Surname', 'Father First Name', 'Father Name Extension', 'Father Middle Name',
        'Mother Maiden Surname', 'Mother Maiden First Name', 'Mother Maiden Middle Name',
        'Q34a Third Degree', 'Q34a Details', 'Q34b Fourth Degree', 'Q34b Details',
        'Q35a Administrative Offense', 'Q35a Details', 'Q35b Criminally Charged', 'Q35b Details', 'Q35b Date Filed',
        'Q35b Status of Case', 'Q36 Convicted', 'Q36 Details', 'Q37 Separated from Service', 'Q37 Details',
        'Q38a Election Candidate', 'Q38a Details', 'Q38b Resigned to Campaign', 'Q38b Details',
        'Q39 Immigrant or Permanent Resident', 'Q39 Country', 'Q40a Indigenous Group', 'Q40a Specify',
        'Q40b Person with Disability', 'Q40b ID No.', 'Q40c Solo Parent', 'Q40c ID No.',
    ])->and(EmployeeColumns::row())->toHaveCount(112);
});

it('exports a declaration nobody answered as blank, never as Not answered', function () {
    $hr = pdslegHr();
    $employee = pdslegEmployee(pdslegSite());
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));
    app(UpdateEmployee::class)->handle($hr, $employee->id, 'declarations', ['decl_34a' => 'yes', 'decl_34b' => 'no'] + $form);
    $row = pdslegCsv($hr, $employee);

    expect($row['Q34a Third Degree'])->toBe('Yes')
        ->and($row['Q34b Fourth Degree'])->toBe('No')
        ->and($row['Q35a Administrative Offense'])->toBe('')
        ->and($row)->not->toContain('Not answered');
});

it('exports the legacy spouse, college and graduate values while nothing structured replaces them', function () {
    $hr = pdslegHr();
    $employee = pdslegEmployee(pdslegSite(), pdslegLegacy());

    expect(pdslegCsv($hr, $employee))->toMatchArray([
        "Spouse's Name" => 'Maria S. Dela Cruz', "Spouse's Employment" => 'Teacher, DepEd',
        'College' => 'Colegio de la Ciudad de Tayabas', 'College Course' => 'BS Computer Science', 'College Year Graduated' => '2017',
        'Graduate Studies' => 'Manuel S. Enverga University', 'Graduate Course/Masteral' => 'MIT', 'Graduate Year Graduated' => 'N/A',
        'Address' => 'Brgy. Wakas, Tayabas City', 'Citizenship' => 'Filipino',
    ]);
});

it('exports and prints the structured spouse and the latest College and Graduate school once they exist', function () {
    $hr = pdslegHr();
    $employee = pdslegEmployee(pdslegSite(), pdslegLegacy());
    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));
    app(UpdateEmployee::class)->handle($hr, $employee->id, 'spouse', [
        'spouse_surname' => 'Dela Cruz', 'spouse_first_name' => 'Maria', 'spouse_middle_name' => 'Santos', 'spouse_occupation' => 'Principal',
    ] + $form);
    foreach ([
        ['COLLEGE', 'Southern Luzon State University', 'BS Information Technology', 2011, 2015],
        ['COLLEGE', 'University of the Philippines Los Baños', 'BS Computer Science', 2015, 2019],
        ['GRADUATE', 'Ateneo de Manila University', 'MS Computer Science', 2020, null],
    ] as [$level, $school, $course, $from, $graduated]) {
        EmployeeEducation::query()->create([
            'employee_id' => $employee->id, 'level' => $level, 'school' => $school, 'course' => $course,
            'attended_from' => $from, 'attended_to' => $graduated ?? 2022, 'year_graduated' => $graduated,
        ]);
    }

    expect(pdslegCsv($hr, $employee))->toMatchArray([
        "Spouse's Name" => 'Maria Santos Dela Cruz', "Spouse's Employment" => 'Principal',
        'College' => 'University of the Philippines Los Baños', 'College Course' => 'BS Computer Science', 'College Year Graduated' => '2019',
        'Graduate Studies' => 'Ateneo de Manila University', 'Graduate Course/Masteral' => 'MS Computer Science', 'Graduate Year Graduated' => '',
    ]);

    $sheet = pdslegSheet($employee);

    expect($sheet)->toContain('Maria Santos Dela Cruz')
        ->toContain('University of the Philippines Los Baños')
        ->toContain('Ateneo de Manila University')
        ->not->toContain('Colegio de la Ciudad de Tayabas')
        ->not->toContain('Manuel S. Enverga University')
        ->and(pdslegStored($employee->id))->toBe(pdslegLegacy());
});

/* |---| The 201 sheet |---| */

it('prints the legacy values on the 201 sheet exactly where it printed them before', function () {
    pdslegHr();
    $employee = pdslegEmployee(pdslegSite(), pdslegLegacy());
    $sheet = pdslegSheet($employee);

    foreach (['Maria S. Dela Cruz', 'Teacher, DepEd', 'Colegio de la Ciudad de Tayabas', 'BS Computer Science', '2017', 'Manuel S. Enverga University', 'MIT', 'N/A'] as $value) {
        expect($sheet)->toContain('>'.e($value).'<');
    }

    $family = substr($sheet, (int) strpos($sheet, 'Family background'), 2000);
    $education = substr($sheet, (int) strpos($sheet, 'Educational background'));

    expect(strpos($family, 'Maria S. Dela Cruz'))->toBeLessThan((int) strpos($family, 'Teacher, DepEd'))
        ->and(strpos($education, 'Colegio de la Ciudad de Tayabas'))->toBeLessThan((int) strpos($education, 'Manuel S. Enverga University'));
});

/* |---| Never written |---| */

it('never writes any legacy column from any form, command or the wizard', function () {
    $hr = pdslegHr();
    $site = pdslegSite();
    $employee = pdslegEmployee($site, pdslegLegacy());
    $changes = [
        'personal' => ['telephone' => '(042) 793-0000', 'citizenship_kind' => 'FILIPINO'],
        'addresses' => ['res_street' => 'Rizal St.', 'res_city' => 'Tayabas City'],
        'emergency' => ['emergency_name' => 'Ana Villanueva'],
        'employment' => ['position_level' => 'II'],
        'statutory' => ['umid_no' => 'U-1'],
        'spouse' => ['spouse_surname' => 'Dela Cruz'],
        'parents' => ['father_surname' => 'Villanueva', 'mother_surname' => 'Perez'],
        'children' => ['children' => [['name' => 'Ana', 'birth_date' => '2019-05-04']]],
        'skills' => ['skills' => ['Chess']],
        'distinctions' => ['distinctions' => ['Best in Math']],
        'memberships' => ['memberships' => ['PCS']],
        'declarations' => ['decl_37' => 'yes', 'decl_37_details' => 'Finished contract'],
    ];
    expect(array_keys($changes))->toBe(array_column(EmployeeFields::sections(), 'id'));

    $hire = $changes['spouse'] + $changes['parents'] + $changes['children'] + $changes['skills'] + $changes['declarations'] + [
        'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'phone' => '0917 123 4567', 'email' => 'juan.leg@company.com',
        'birth_date' => '1990-05-01', 'res_street' => '123 Mabini St.', 'sex' => 'Male', 'civil_status' => 'Married',
        'emergency_name' => 'Maria Dela Cruz', 'emergency_relation' => 'Spouse', 'emergency_phone' => '(02) 8123-4567',
        'position_id' => (string) Position::query()->where('name', 'Developer')->value('id'), 'employment_status' => 'Probationary', 'work_site_id' => $site->id, 'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ] + EmployeeFields::blankForm();

    $hired = null;
    $queries = pdslegQueries(function () use ($hr, $employee, $changes, $hire, &$hired): void {
        foreach ($changes as $section => $change) {
            $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));
            app(UpdateEmployee::class)->handle($hr, $employee->id, $section, $change + $form);
        }
        // Every list's row is added, a College and a Graduate school among them, then edited.
        $lists = [
            'education' => ['level' => 'COLLEGE', 'school' => 'Manuel S. Enverga University', 'course' => 'BS Accountancy', 'year_graduated' => '2016'],
            'eligibility' => ['name' => 'Career Service Professional'],
            'experience' => ['position_title' => 'Junior Accountant', 'company' => 'ABC Trading', 'date_from' => '2013-01-07', 'date_to' => '2016-05-31'],
            'training' => ['title' => 'Payroll Basics', 'date_from' => '2023-03-06', 'date_to' => '2023-03-08'],
            'voluntary' => ['organization' => 'Philippine Red Cross'],
        ];
        foreach ($lists as $list => $row) {
            app(SavePdsRow::class)->handle($hr, $employee->id, $list, null, $row + PdsLists::blankRow($list));
        }
        app(SavePdsRow::class)->handle($hr, $employee->id, 'education', null, ['level' => 'GRADUATE', 'school' => 'UP Diliman'] + PdsLists::blankRow('education'));
        $school = (string) EmployeeEducation::query()->where('employee_id', $employee->id)->where('level', 'COLLEGE')->value('id');
        app(SavePdsRow::class)->handle($hr, $employee->id, 'education', $school, ['year_graduated' => '2017'] + $lists['education'] + PdsLists::blankRow('education'));
        $hired = app(CreateEmployee::class)->handle($hr, $hire);
    });

    $writes = $queries->filter(static fn (string $q): bool => preg_match('/^(insert|update)/i', $q) === 1);
    foreach (PDSLEG_COLUMNS as $column) {
        expect($writes->filter(static fn (string $q): bool => str_contains($q, '"'.$column.'"'))->all())->toBe([]);
    }

    expect(pdslegStored($employee->id))->toBe(pdslegLegacy())
        ->and(EmployeeEducation::query()->where('employee_id', $employee->id)->orderBy('level')->pluck('year_graduated', 'level')->all())->toBe(['COLLEGE' => 2017, 'GRADUATE' => null])
        ->and($hired)->not->toBeNull()
        ->and(pdslegStored((string) $hired?->id))->toBe(array_fill_keys(PDSLEG_COLUMNS, null))
        ->and(EmployeeFields::itemLines($hired, 'skills'))->toBe(['Chess'])
        ->and(EmployeeFields::declarationText($hired, 'decl_37')['text'])->toBe("Yes\nDetails: Finished contract");
});
