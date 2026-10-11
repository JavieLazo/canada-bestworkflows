<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeChild;
use App\Models\EmployeeEducation;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeColumns;
use App\Modules\Workforce\Application\EmployeeInput;
use App\Modules\Workforce\Application\Queries\EmployeeRecords;
use App\Modules\Workforce\Domain\Contact\PhoneRule;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The paper 201 file: the record carries every line of the company's
 * sheet — telephone, religion, height, weight and blood type on Personal
 * Details; the Family section's spouse and children (rows of
 * `employee_children`, replaced whole with the section); Educational
 * Background; the bank's name under Government IDs & Bank — on the record
 * page, in the hire wizard and in the CSV; the
 * record page shows the height in metres while the record, the sheet and the
 * CSV keep centimetres; and the
 * management view carries that sheet print-only, so Print prints in place
 * for a visible employee record. My Profile views have no print
 * control or sheet. The paper form's license and
 * passport lines are neither fields nor printed: those are documents on
 * the Documents card.
 */

/** @param list<string> $modules */
function e201Role(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill([
        'name' => '201 '.implode('-', $modules).' '.Str::random(6),
        'all_data' => $allData,
        'is_system' => false,
    ])->save();

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function e201User(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    return $user->refresh();
}

function e201Site(): WorkSite
{
    Position::query()->create(['code' => 'POS-201', 'name' => 'Developer']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-201', 'name' => 'Probationary']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-202', 'name' => 'Regular']);

    return WorkSite::query()->create([
        'name' => 'Work From Home',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 100,
        'geofence_exempt' => true,
    ]);
}

/** A record holding every line of the sheet, with two children. */
function e201Employee(WorkSite $site): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-00201',
        'first_name' => 'Rosa',
        'middle_name' => 'Villar',
        'last_name' => 'Villanueva',
        'position' => 'Developer',
        'employment_status' => 'Regular',
        'date_hired' => '2024-08-01',
        'work_site_id' => $site->id,
        'monthly_rate' => 30000,
        'address' => 'Blk 3 Lot 7, Barangay Poblacion, Tayabas City',
        'phone' => '0917 555 0101',
        'telephone' => '(042) 911 5690',
        'email' => 'rosa.villanueva@company.com',
        'birth_date' => '1996-03-14',
        'place_of_birth' => 'Lucena City',
        'citizenship' => 'Filipino',
        'religion' => 'Catholic',
        'height_cm' => 162.5,
        'weight_kg' => 54,
        'blood_type' => 'O+',
        'sex' => 'Female',
        'civil_status' => 'Married',
        'emergency_name' => 'Ben Villanueva',
        'emergency_relation' => 'Husband',
        'emergency_phone' => '0917 555 0102',
        'emergency_address' => 'Barangay Isabang, Tayabas City',
        'spouse_name' => 'Ben Villanueva',
        'spouse_employment' => 'Teacher, Tayabas West Central School',
        'college' => 'Colegio de la Ciudad de Tayabas',
        'college_course' => 'BS Computer Science',
        'college_year_graduated' => '2017',
        'graduate_school' => 'N/A',
        'graduate_course' => 'N/A',
        'graduate_year_graduated' => 'N/A',
        'sss_no' => '34-9365770-8',
        'philhealth_no' => '08-026952290-2',
        'pagibig_no' => '121389906132',
        'tin' => '683278996',
        'bank_name' => 'GoTyme Bank Corporation - Digital / Online',
        'bank_account_no' => '012488734102',
    ]);

    foreach ([['Ana Villanueva', '2019-05-04'], ['Ben Villanueva Jr.', null]] as $position => [$name, $born]) {
        $child = new EmployeeChild;
        $child->fill(['employee_id' => $employee->id, 'position' => $position, 'name' => $name, 'birth_date' => $born])->save();
    }

    return $employee;
}

/** @return array<string, mixed> every required answer of the hire wizard */
function e201ValidForm(WorkSite $site): array
{
    return [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'phone' => '0917 123 4567',
        'email' => 'juan.201@company.com',
        'birth_date' => '1990-05-01',
        'res_street' => '123 Mabini St.',
        'sex' => 'Male',
        'civil_status' => 'Single',
        'emergency_name' => 'Maria Dela Cruz',
        'emergency_relation' => 'Spouse',
        'emergency_phone' => '(02) 8123-4567',
        'position_id' => (string) Position::query()->where('name', 'Developer')->value('id'),
        'employment_status' => 'Probationary',
        'work_site_id' => $site->id,
        'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ] + EmployeeFields::blankForm();
}

/**
 * The record page's cards: everything before the print-only sheet, which
 * repeats many of the same values in its own spelling.
 */
function e201Cards(string $html): string
{
    $end = strpos($html, '<section class="sheet-201');

    return $end === false ? $html : substr($html, 0, $end);
}

/** The sheet's children block — names only, three lines at least. */
function e201KidsBlock(string $html): string
{
    $start = (int) strpos($html, 'class="kids"');
    $end = (int) strpos($html, '</div>', $start);

    return substr($html, $start, $end - $start);
}

/* |---| The record page and the command behind it |---| */

it('lays the record out as twelve sections, the new ones optional and HR-maintained', function () {
    $sections = EmployeeFields::sections();

    expect(array_column($sections, 'id'))->toBe(['personal', 'addresses', 'emergency', 'employment', 'statutory', 'spouse', 'parents', 'children', 'skills', 'distinctions', 'memberships', 'declarations'])
        ->and(array_column($sections, 'title'))->toBe(['Personal Details', 'Addresses', 'Emergency Contact', 'Employee Details', 'Government IDs & Bank', 'Spouse', 'Parents', 'Children', 'Skills and Hobbies', 'Distinctions', 'Memberships', 'Declarations'])
        // The addresses sit on the right, two by two.
        ->and(EmployeeFields::section('addresses')['column'] ?? null)->toBe('right')
        ->and(EmployeeFields::section('addresses')['columns'] ?? null)->toBe(2)
        // The Personal Data Sheet parts are HR-maintained sections.
        ->and(array_column(array_filter($sections, static fn (array $section): bool => ($section['hr'] ?? false) === true), 'id'))
        ->toBe(['spouse', 'parents', 'children', 'skills', 'distinctions', 'memberships', 'declarations']);

    $personal = array_column(EmployeeFields::section('personal')['fields'] ?? [], 'key');
    expect($personal)->toContain('telephone', 'religion', 'height_cm', 'weight_kg', 'blood_type')
        ->and(array_search('telephone', $personal, true))->toBe((int) array_search('phone', $personal, true) + 1);

    expect(array_column(EmployeeFields::section('spouse')['fields'] ?? [], 'key'))->toBe([
        'spouse_surname', 'spouse_first_name', 'spouse_name_extension', 'spouse_middle_name',
        'spouse_occupation', 'spouse_employer', 'spouse_business_address', 'spouse_telephone',
    ])
        ->and(array_column(EmployeeFields::section('children')['fields'] ?? [], 'key'))->toBe(['children'])
        ->and(array_column(EmployeeFields::section('statutory')['fields'] ?? [], 'key'))->toContain('bank_name')
        // The legacy spouse, college and graduate columns are fallbacks, not fields.
        ->and(EmployeeFields::field('spouse_name'))->toBeNull()
        ->and(EmployeeFields::field('college'))->toBeNull()
        ->and(EmployeeFields::field('graduate_year_graduated'))->toBeNull()
        // A license or passport is a document on the Documents card, never a
        // record field.
        ->and(array_keys(EmployeeFields::TEXT_CAPS))->not->toContain('license_no')
        ->and(array_keys(EmployeeFields::TEXT_CAPS))->not->toContain('passport_no');

    // None of the 201 sheet's own lines nor the Personal Data Sheet parts is
    // required on the hire.
    $new = ['telephone', 'religion', 'height_cm', 'weight_kg', 'blood_type', 'children', 'bank_name', 'skills', 'decl_34a',
        ...array_column(EmployeeFields::section('spouse')['fields'] ?? [], 'key'),
        ...array_column(EmployeeFields::section('parents')['fields'] ?? [], 'key')];
    expect(array_values(array_intersect(EmployeeFields::REQUIRED, $new)))->toBe([]);

    // The form starts the lists empty, the measures blank and every
    // declaration Not answered.
    $blank = EmployeeFields::blankForm();
    expect($blank['children'])->toBe([])
        ->and($blank['skills'])->toBe([])
        ->and($blank['decl_34a'])->toBe('')
        ->and($blank['height_cm'])->toBe('')
        ->and($blank['weight_kg'])->toBe('')
        ->and($blank['monthly_rate'])->toBe('');
});

it('shows every line of the sheet on the record page, the children one per line and the measures with their units', function () {
    $employee = e201Employee(e201Site());
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Telephone Number')
        ->assertSee('(042) 911 5690')
        ->assertSee('Religion')
        ->assertSee('54 kg')
        ->assertSee('Blood Type')
        ->assertDontSee('04 May 2019')
        ->assertSee('Government IDs & Bank')
        ->assertSee('Bank and Branch')
        ->assertSee('GoTyme Bank Corporation - Digital / Online')
        ->assertDontSee('License Number')
        ->assertDontSee('Passport')
        ->assertSee('Print')
        ->assertDontSee('Print 201 File')
        ->assertSee('201 File')
        ->assertSee('Personal Data Sheet');

    // The print-only sheet repeats the family, the schooling and the height
    // in its own spelling on every tab, so the cards are read on their own:
    // the height in metres there, in centimetres on the sheet. The Profile
    // tab carries no Family or Education card.
    $cards = e201Cards($page->html());

    expect($cards)->toContain('1.625 m')
        ->not->toContain('162.5 cm')
        ->not->toContain('wire:key="section-spouse"')
        ->not->toContain('wire:key="section-children"')
        ->not->toContain('Colegio de la Ciudad de Tayabas')
        ->and(e201Sheet($page->html()))->toContain('162.5 cm');

    // The Family tab: the stored spouse in its "Entered before" note (no
    // part is typed), and the children with their dates of birth.
    $family = e201Cards($page->set('tab', 'family')->html());

    expect($family)->toContain('wire:key="section-spouse"')
        ->toContain('wire:key="section-children"')
        ->toContain('Entered before: Ben Villanueva · Teacher, Tayabas West Central School — shown until the spouse is typed in parts.')
        ->toContain('<td class="font-medium">Ana Villanueva</td>')
        ->toContain('<td class="num whitespace-nowrap">04 May 2019</td>')
        ->toContain('<td class="font-medium">Ben Villanueva Jr.</td>')
        ->not->toContain('Colegio de la Ciudad de Tayabas');

    // The Education tab: the list card, which shows the stored college as
    // its level's row until a school of that level is added.
    $education = e201Cards($page->set('tab', 'education')->html());

    expect($education)->toContain('&quot;name&quot;:&quot;employees.pds-list&quot;')
        ->toContain('Colegio de la Ciudad de Tayabas')
        ->not->toContain('Ana Villanueva')
        ->and(e201Sheet($page->html()))->toContain('162.5 cm')->toContain('Ana Villanueva');
});

it('saves the children as rows in order, keeping child N on row N', function () {
    $employee = e201Employee(e201Site());
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    $ids = EmployeeChild::query()->where('employee_id', $employee->id)->orderBy('position')->pluck('id')->all();

    DB::flushQueryLog();
    DB::enableQueryLog();

    // Two rows on the record: remove the first, rename the second and give
    // it a date of birth, add one.
    try {
        $page = Livewire::test(Show::class, ['employee' => $employee->id])
            ->call('startEdit', 'children')
            ->assertSet('tab', 'family')
            ->assertSet('form.children', [
                ['name' => 'Ana Villanueva', 'birth_date' => '2019-05-04'],
                ['name' => 'Ben Villanueva Jr.', 'birth_date' => ''],
            ])
            ->assertSeeHtml('aria-label="Child 1 date of birth"')
            ->call('removeChild', 0)
            ->set('form.children.0.name', 'Benjamin Villanueva Jr.')
            ->set('form.children.0.birth_date', '2021-11-30')
            ->call('addChild')
            ->set('form.children.1.name', 'Carla Villanueva')
            ->call('saveSection', 'children')
            ->assertSet('editingSection', null)
            ->assertSee('Benjamin Villanueva Jr.')
            ->assertSee('30 Nov 2021')
            ->assertSee('Carla Villanueva');
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    // Child N stays on row N, updated in place: the same two ids, and no
    // delete over the employee's range of rows.
    $rows = EmployeeChild::query()->where('employee_id', $employee->id)->orderBy('position')->get();
    expect($rows->map(static fn (EmployeeChild $c): array => [$c->position, $c->name, $c->birth_date?->format('Y-m-d')])->all())
        ->toBe([[0, 'Benjamin Villanueva Jr.', '2021-11-30'], [1, 'Carla Villanueva', null]])
        ->and($rows->pluck('id')->all())->toBe($ids)
        ->and(array_filter($log, static fn (array $entry): bool => preg_match('/delete from "employee_children" where "employee_id"/i', (string) $entry['query']) === 1))->toBe([])
        // The stored spouse is never written by the children's save.
        ->and($employee->refresh()->spouse_name)->toBe('Ben Villanueva');

    $audit = AuditLog::query()->where('action', 'EMPLOYEE_UPDATED')->latest('id')->first();
    expect($audit?->details)->toContain('children');

    // A blank row added and left blank is not a child.
    $page->call('startEdit', 'children')
        ->call('addChild')
        ->call('saveSection', 'children')
        ->assertSet('editingSection', null);
    expect(EmployeeChild::query()->where('employee_id', $employee->id)->count())->toBe(2);
    expect(EmployeeChild::query()->where('employee_id', $employee->id)->orderBy('position')->first()?->birth_date?->format('Y-m-d'))
        ->toBe('2021-11-30');

    // Clearing every row leaves none: the table and the editor both say so.
    $page->call('startEdit', 'children')
        ->call('removeChild', 1)
        ->call('removeChild', 0)
        ->call('saveSection', 'children')
        ->assertSet('editingSection', null)
        ->assertDontSee('Carla Villanueva')
        ->assertSee('No children listed.')
        ->call('startEdit', 'children')
        ->assertSee('No children listed.');
    expect(EmployeeChild::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

it('refuses a malformed child under the field and through the command, leaving the rows as they were', function () {
    $employee = e201Employee(e201Site());
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'children')
        ->call('addChild')
        ->set('form.children.2.birth_date', '2020-01-01')
        ->call('saveSection', 'children')
        ->assertSet('editingSection', 'children')
        ->assertSee("Each child's birthdate needs the child's name beside it.");

    $form = EmployeeFields::toForm(app(EmployeeRecords::class)->find(app(Access::class), $employee->id, details: true));

    foreach ([
        [[['name' => 'Dodo', 'birth_date' => '2020-02-30']], "A child's birthdate must be a real calendar date."],
        [[['name' => 'Dodo', 'birth_date' => '2999-01-01']], "A child's birthdate cannot be in the future."],
        [[['name' => str_repeat('x', 121), 'birth_date' => '']], "A child's name must be at most 120 characters."],
        [array_fill(0, 31, ['name' => 'Many', 'birth_date' => '']), 'At most 30 children can be listed.'],
        ['tampered', 'Children must be a list of names and birthdates.'],
    ] as [$children, $message]) {
        expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, 'children', ['children' => $children] + $form))
            ->toThrow(RuntimeException::class, $message);
    }

    expect(EmployeeChild::query()->where('employee_id', $employee->id)->pluck('name')->all())
        ->toBe(['Ana Villanueva', 'Ben Villanueva Jr.']);
});

it('requires approval for an employee to change their own personal data sections', function () {
    $employee = e201Employee(e201Site());
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], false), $employee));

    $form = EmployeeFields::toForm(app(EmployeeRecords::class)->find(app(Access::class), $employee->id, details: true));

    foreach (['personal', 'spouse', 'children', 'statutory'] as $section) {
        expect(fn () => app(UpdateEmployee::class)->handle(app(Access::class), $employee->id, $section, $form))
            ->toThrow(HttpException::class);
    }

    expect($employee->refresh()->telephone)->toBe('(042) 911 5690')
        ->and(EmployeeChild::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('names the shape problem of a new line on its own step', function (string $key, mixed $value, int $step, ?string $message) {
    $errors = EmployeeInput::stepErrors([$key => $value] + EmployeeFields::blankForm(), $step);

    expect($errors[$key] ?? null)->toBe($message);
})->with([
    'a telephone made of letters' => ['telephone', 'landline', 0, PhoneRule::MESSAGE],
    'a landline with an area code' => ['telephone', '(042) 911 5690', 0, null],
    'a negative height' => ['height_cm', '-1', 0, 'Height must be in metres, like 1.68.'],
    'a height with five decimals' => ['height_cm', '1.62555', 0, 'Height must be in metres, like 1.68.'],
    'a height over its 3-metre cap' => ['height_cm', '3.01', 0, 'Height must be at most 3 metres — 168 cm is typed 1.68.'],
    'a height typed in centimetres' => ['height_cm', '168', 0, 'Height must be at most 3 metres — 168 cm is typed 1.68.'],
    'a height a centimetre value with a half rounds back to' => ['height_cm', '1.625', 0, null],
    'a weight over its cap' => ['weight_kg', '501', 0, 'Weight must be at most 500.'],
    'a blank weight' => ['weight_kg', '', 0, null],
    'a blood type off the list' => ['blood_type', 'C+', 0, 'Blood Type must be one of the listed options.'],
    'a religion over its cap' => ['religion', str_repeat('x', 65), 0, 'Religion must be at most 64 characters.'],
    'a spouse surname over its cap' => ['spouse_surname', str_repeat('x', 65), 4, 'Surname must be at most 64 characters.'],
    'a child with a birthdate and no name' => ['children', [['name' => '', 'birth_date' => '2020-01-01']], 4, "Each child's birthdate needs the child's name beside it."],
    'a child row left blank' => ['children', [['name' => '', 'birth_date' => '']], 4, null],
    'a college school over its cap' => ['rows_education', [['level' => 'COLLEGE', 'school' => str_repeat('x', 151)]], 5, 'School 1: Name of school must be at most 150 characters.'],
    'a year graduated left blank, which the sheet prints as N/A' => ['rows_education', [['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'year_graduated' => '']], 5, null],
    'a year graduated typed as words' => ['rows_education', [['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'year_graduated' => 'N/A']], 5, 'School 1: Year graduated must be a four-digit year, like 2015.'],
    'a bank name over its cap' => ['bank_name', str_repeat('x', 151), 12, 'Bank and Branch must be at most 150 characters.'],
]);

it('hires through the thirteen-step wizard with the sheet\'s lines, the height in metres, the children as rows and a college as a school row', function () {
    $site = e201Site();
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog')
        ->assertSee('Step 1 of 13')
        ->assertSee('Telephone Number')
        ->assertSee('Blood Type');

    foreach (e201ValidForm($site) as $key => $value) {
        $wizard->set("form.{$key}", $value);
    }

    $wizard->set('form.telephone', '(042) 911 5690')
        ->set('form.height_cm', '1.78')
        ->set('form.weight_kg', '70.5')
        ->set('form.blood_type', 'AB-')
        ->call('goNext')->assertSet('step', 1)
        ->assertSee('Addresses')
        ->call('goNext')->assertSet('step', 2)
        ->assertSee('Emergency Contact')
        ->call('goNext')->assertSet('step', 3)
        ->assertSee('Employee Details')
        ->call('goNext')->assertSet('step', 4)
        ->assertSee('Family')
        ->assertSee('No children listed.')
        ->call('addChild')
        ->assertSeeHtml('aria-label="Child 1 name"')
        ->assertSeeHtml('aria-label="Child 1 date of birth"')
        ->set('form.children.0.name', 'Lucas Dela Cruz')
        ->set('form.children.0.birth_date', '2019-02-14')
        ->call('addChild')
        ->set('form.children.1.name', 'Mia Dela Cruz')
        ->call('goNext')->assertSet('step', 5)
        ->assertSee('Educational Background')
        ->assertDontSeeHtml('wire:model.blur="form.college"')
        ->call('addRow', 'education')
        ->set('form.rows_education.0.level', 'COLLEGE')
        ->set('form.rows_education.0.school', 'Colegio de la Ciudad de Tayabas')
        ->set('form.rows_education.0.course', 'BSCS')
        ->set('form.rows_education.0.year_graduated', '2025');

    // Eligibility to Declarations may all be passed as they stand.
    for ($step = 5; $step < 12; $step++) {
        $wizard->call('goNext')->assertSet('step', $step + 1);
    }

    $wizard->assertSee('Government IDs & Bank')
        ->assertDontSee('Passport')
        ->set('form.bank_name', 'GoTyme Bank')
        ->call('create')
        ->assertDispatched('employee-created');

    $hired = EmployeeProfile::query()->where('email', 'juan.201@company.com')->firstOrFail();

    expect($hired->telephone)->toBe('(042) 911 5690')
        ->and($hired->height_cm)->toBe(178.0)
        ->and($hired->weight_kg)->toBe(70.5)
        ->and($hired->blood_type)->toBe('AB-')
        ->and($hired->bank_name)->toBe('GoTyme Bank')
        ->and($hired->religion)->toBeNull()
        // The college is a school row; the legacy college columns stay empty.
        ->and($hired->college)->toBeNull()
        ->and($hired->college_course)->toBeNull()
        ->and($hired->college_year_graduated)->toBeNull()
        ->and($hired->spouse_name)->toBeNull();

    expect(EmployeeEducation::query()->where('employee_id', $hired->id)->get()->map->only(['level', 'school', 'course', 'year_graduated'])->all())
        ->toBe([['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'course' => 'BSCS', 'year_graduated' => 2025]]);

    expect(EmployeeChild::query()->where('employee_id', $hired->id)->orderBy('position')->get()
        ->map(static fn (EmployeeChild $c): array => [$c->name, $c->birth_date?->format('Y-m-d')])->all())
        ->toBe([['Lucas Dela Cruz', '2019-02-14'], ['Mia Dela Cruz', null]]);

    // The command hands back a view that already carries the children.
    $view = app(CreateEmployee::class)->handle(app(Access::class), ['email' => 'second.201@company.com', 'children' => [['name' => 'Solo', 'birth_date' => '']]] + e201ValidForm($site));
    expect($view->children)->toBe([['name' => 'Solo', 'birth_date' => null]])
        ->and(EmployeeChild::query()->where('employee_id', $view->id)->count())->toBe(1);
});

it('leaves the wizard on the Family step when a child is malformed', function () {
    $site = e201Site();
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach (e201ValidForm($site) as $key => $value) {
        $wizard->set("form.{$key}", $value);
    }

    $wizard->set('step', 4)
        ->call('addChild')
        ->set('form.children.0.birth_date', '2020-01-01')
        ->call('goNext')
        ->assertSet('step', 4)
        ->assertSee("Each child's birthdate needs the child's name beside it.")
        ->set('step', 12)
        ->call('create')
        ->assertNotDispatched('employee-created')
        ->assertSet('step', 4);

    expect(EmployeeProfile::query()->count())->toBe(0);
});

it('deletes the children with the record', function () {
    $employee = e201Employee(e201Site());
    expect(EmployeeChild::query()->count())->toBe(2);

    $employee->delete();

    expect(EmployeeChild::query()->count())->toBe(0);
});

/* |---| The CSV |---| */

it('exports every new line in its own column and the children as one cell', function () {
    $employee = e201Employee(e201Site());
    $headers = EmployeeColumns::headers();

    expect($headers)->toContain('Telephone Number', 'Religion', 'Height', 'Weight', 'Blood Type', "Spouse's Name", "Spouse's Employment", 'Children',
        'College', 'College Course', 'College Year Graduated', 'Graduate Studies', 'Graduate Course/Masteral', 'Graduate Year Graduated', 'Bank and Branch')
        ->and($headers)->not->toContain('License Number', 'Passport Number')
        ->and(count($headers))->toBe(count(array_unique($headers)));

    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));
    $view = app(EmployeeRecords::class)->find(app(Access::class), $employee->id, details: true);
    $row = array_combine(EmployeeColumns::row(), EmployeeColumns::cells($view, []));

    expect($row['Telephone Number'])->toBe('(042) 911 5690')
        ->and($row['Height'])->toBe('162.5')
        ->and($row['Weight'])->toBe('54')
        ->and($row['Blood Type'])->toBe('O+')
        ->and($row['Children'])->toBe('Ana Villanueva (2019-05-04); Ben Villanueva Jr.')
        ->and($row['Graduate Year Graduated'])->toBe('N/A');

    $lines = array_map('str_getcsv', array_filter(explode("\n", $this->get('/employees/export')->assertOk()->streamedContent())));
    $exported = array_combine(array_map('strval', $lines[0]), array_map('strval', $lines[1]));
    expect($exported['Children'])->toBe('Ana Villanueva (2019-05-04); Ben Villanueva Jr.')
        ->and($exported['Bank and Branch'])->toBe('GoTyme Bank Corporation - Digital / Online');
});

/* |---| The printed sheet |---| */

/** The sheet on the record page: the print-only `<section class="sheet-201 …">`. */
function e201Sheet(string $html): string
{
    $start = (int) strpos($html, '<section class="sheet-201');
    $end = (int) strpos($html, '</section>', $start);

    return substr($html, $start, $end - $start);
}

it('prints an Employee Records view as the 201 sheet and omits it from My Profile', function () {
    config(['copems.company_name' => 'Fixture Company']);
    $employee = e201Employee(e201Site());
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    // The button, labelled Print, offers 201 File, which prints the page
    // itself through the root's `record-print` listener: no new tab, and no
    // page of its own to open.
    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Print')
        ->assertDontSee('Print 201 File')
        ->assertSee('201 File')
        ->assertDontSeeHtml('target="_blank"');
    expect($page->html())->toMatch('/x-on:click="\$dispatch\((\'|&#039;)record-print(\'|&#039;), \{ sheet: (\'|&#039;)201(\'|&#039;) \}\)"/')
        ->toContain('$nextTick(() => window.print())');
    expect(Route::has('employees.print'))->toBeFalse();
    $this->get('/employees/'.$employee->id.'/print')->assertNotFound();

    // The sheet rides along hidden, the only thing on paper, on legal paper.
    $html = $page->html();
    expect($html)->toContain('class="record-page ')
        ->toContain('.sheet-201 { display: none; }')
        ->toContain('.record-page > :not(.sheet-201) { display: none !important; }')
        ->toContain('@page { size: 8.5in 14in;');

    // HR prints anyone: every line of the sheet, the dates spelled out, the
    // children in order with the sheet's three ruled lines kept, the
    // measures with their units, and nothing of the app in it.
    $sheet = e201Sheet($html);
    expect($sheet)->toContain('class="sheet-201 record-print"')
        ->toContain('Fixture Company')
        ->toContain('Rosa Villar Villanueva')
        ->toContain('Work From Home')
        ->toContain('August 1, 2024')
        ->toContain('(042) 911 5690')
        // The e-mail carries a break hint before each "@" and ".", so one too
        // long for its field wraps there and not mid-word.
        ->toContain('rosa<wbr>.villanueva<wbr>@company<wbr>.com')
        ->toContain('March 14, 1996')
        ->toContain('Lucena City')
        ->toContain('162.5 cm')
        ->toContain('54 kg')
        ->toContain('O+')
        ->toContain('Emergency contact')
        ->toContain('Teacher, Tayabas West Central School')
        ->toContain('Educational background')
        ->toContain('Statutory &amp; bank details')
        ->toContain('GoTyme Bank Corporation - Digital / Online')
        ->toContain('TIN no')
        // The paper form's license and passport lines are left off: those
        // are documents on the Documents card.
        ->not->toContain('License no')
        ->not->toContain('Passport')
        ->not->toContain('wire:')
        ->not->toContain('Employee Records');
    expect(strpos($sheet, 'Ana Villanueva'))->toBeLessThan((int) strpos($sheet, 'Ben Villanueva Jr.'))
        ->and(substr_count(e201KidsBlock($sheet), '<span class="v">'))->toBe(3)
        ->and(e201KidsBlock($sheet))->not->toContain('Birthdate', 'May 4, 2019')
        ->and($sheet)->toContain('<dt>No. of children</dt><dd>2</dd>');

    // My Profile stays readable without a print control or hidden sheet.
    $other = EmployeeProfile::query()->create([
        'employee_code' => 'COP-00202', 'first_name' => 'Ben', 'last_name' => 'Domingo',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-15', 'monthly_rate' => 20000,
    ]);
    $self = e201User(e201Role([ModuleCode::PROFILE, ModuleCode::EMPLOYEES], false), $other);
    $this->actingAs($self);
    app()->forgetInstance(Access::class);

    Livewire::test(Show::class, ['employee' => $other->id, 'own' => true])
        ->assertSee('Ben Domingo')
        ->assertDontSee('201 File')
        ->assertDontSeeHtml('wire:click="printPds"')
        ->assertDontSeeHtml('sheet-201')
        ->assertDontSee('Villanueva')
        ->call('printPds')
        ->assertForbidden();
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Employee not found')
        ->assertDontSee('Villanueva')
        ->assertDontSee('sheet-201');

    // An unlinked account holding the module prints nothing.
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], false)));
    app()->forgetInstance(Access::class);
    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSee('Employee not found')
        ->assertDontSee('sheet-201');
});

it('lists all thirty children on the 201 sheet', function () {
    $employee = e201Employee(e201Site());
    EmployeeChild::query()->where('employee_id', $employee->id)->delete();
    $names = [];
    foreach (range(1, 30) as $n) {
        // Sixty characters each, the longest a child's line is likely to run.
        $names[] = $name = str_pad(sprintf('Child %02d Villanueva', $n), 60, ' Dela Cruz');
        $child = new EmployeeChild;
        $child->fill(['employee_id' => $employee->id, 'position' => $n - 1, 'name' => $name, 'birth_date' => null])->save();
    }
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    // The sheet keeps its one-page fit: it scales a long record down and
    // never drops a child to make room.
    $sheet = e201Sheet(Livewire::test(Show::class, ['employee' => $employee->id])->html());
    $at = 0;
    foreach ($names as $name) {
        expect(mb_strlen($name))->toBe(60);
        $found = strpos($sheet, '<span class="v">'.e($name).'</span>', $at);
        expect($found)->not->toBeFalse();
        $at = (int) $found + 1;
    }
    expect($sheet)->toContain('<dt>No. of children</dt><dd>30</dd>')
        ->and(substr_count(e201KidsBlock($sheet), '<span class="v">'))->toBe(30);
});

it('prints a record with nothing on the sheet as blank rules, never a dash', function () {
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'COP-00203', 'first_name' => 'Nena', 'last_name' => 'Reyes',
        'employment_status' => 'Probationary', 'date_hired' => '2026-09-01', 'monthly_rate' => 20000,
    ]);
    $this->actingAs(e201User(e201Role([ModuleCode::EMPLOYEES], true)));

    $sheet = e201Sheet(Livewire::test(Show::class, ['employee' => $employee->id])->html());

    expect($sheet)->toContain('Nena Reyes')
        ->and($sheet)->not->toContain('—')
        ->and($sheet)->toContain('<dt>Area office</dt><dd></dd>')
        ->and($sheet)->toContain('<dt>No. of children</dt><dd></dd>')
        ->and(substr_count(e201KidsBlock($sheet), '<span class="v"></span>'))->toBe(3)
        ->and($sheet)->toContain('class="photo blank"');
});
