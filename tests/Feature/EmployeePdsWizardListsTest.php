<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Models\AuditLog;
use App\Models\EmployeeAddress;
use App\Models\EmployeeChild;
use App\Models\EmployeeEducation;
use App\Models\EmployeeEligibility;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVoluntaryWork;
use App\Models\EmployeeWorkExperience;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\CreateEmployee;
use App\Modules\Workforce\Application\EmployeeDetailRows;
use App\Modules\Workforce\Application\PdsLists;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The hire wizard asks for every part of the record: the Family step (the
 * spouse in parts, the parents, the children with their dates of birth),
 * the five Personal Data Sheet lists as inline rows, the Other info lines
 * and the declarations. A list step may be left empty, and a draft row left
 * blank is not a row; a row with a problem holds its step, and Create jumps
 * to the first step that has one. CreateEmployee writes every part in ONE
 * transaction, so a part whose table does not exist yet leaves no trace of
 * the hire. No legacy column is ever written, and a caller without Employee
 * Records all-data hires nobody.
 */
function pdswizlActor(bool $allData = true): User
{
    $role = Role::query()->create([
        'name' => 'Test wizard lists'.($allData ? ' all' : ''),
        'all_data' => $allData,
        'is_system' => false,
    ]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);

    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id])->save();

    return $user->refresh();
}

function pdswizlSite(): WorkSite
{
    Position::query()->create(['code' => 'POS-001', 'name' => 'Driver']);
    EmploymentStatusOption::query()->create(['code' => 'EMS-001', 'name' => 'Probationary']);

    return WorkSite::query()->create([
        'name' => 'Head Office',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 100,
        'geofence_exempt' => false,
    ]);
}

/**
 * Every required answer of every step.
 *
 * @return array<string, mixed>
 */
function pdswizlForm(WorkSite $site, string $email = 'rosa.lists@company.com'): array
{
    return [
        'employee_id_no' => 'ID-'.substr(md5($email), 0, 8),
        'first_name' => 'Rosa',
        'last_name' => 'Villanueva',
        'phone' => '0917 123 4567',
        'email' => $email,
        'birth_date' => '1990-05-01',
        'sex' => 'Female',
        'civil_status' => 'Married',
        'res_street' => 'Rizal St.',
        'emergency_name' => 'Ana Villanueva',
        'emergency_relation' => 'Sister',
        'emergency_phone' => '0917 987 6543',
        'position_id' => (string) Position::query()->where('name', 'Driver')->value('id'),
        'employment_status' => 'Probationary',
        'work_site_id' => $site->id,
        'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ];
}

/**
 * The spouse, the parents and three declarations, as the Family and
 * Declarations steps take them.
 *
 * @return array<string, string>
 */
function pdswizlParts(): array
{
    return [
        'spouse_surname' => 'Villanueva', 'spouse_first_name' => 'Ben', 'spouse_middle_name' => 'Reyes',
        'spouse_occupation' => 'Teacher', 'spouse_employer' => 'DepEd Tayabas', 'spouse_telephone' => '(042) 911 5690',
        'father_surname' => 'Santos', 'father_first_name' => 'Pedro', 'father_name_extension' => 'Sr.',
        'mother_surname' => 'Perez', 'mother_first_name' => 'Lourdes', 'mother_middle_name' => 'Castillo',
        'decl_34a' => 'no', 'decl_35b' => 'yes', 'decl_35b_details' => 'Estafa',
        'decl_35b_date_filed' => '2020-03-02', 'decl_35b_case_status' => 'Dismissed',
    ];
}

/**
 * One valid row of a list, as the wizard's inputs hold it.
 *
 * @return array<string, string>
 */
function pdswizlRow(string $list): array
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

/**
 * The wizard opened and filled with `$form`, key by key, as a person types.
 *
 * @param  array<string, mixed>  $form
 * @return Testable<AddEmployeeDialog>
 */
function pdswizlFilled(array $form): Testable
{
    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach ($form as $key => $value) {
        $wizard->set("form.{$key}", $value);
    }

    return $wizard;
}

/**
 * A row added on its list step and typed in, field by field.
 *
 * @param  Testable<AddEmployeeDialog>  $wizard
 * @param  array<string, string>  $row
 */
function pdswizlAddRow(Testable $wizard, string $list, int $index, array $row): void
{
    $wizard->call('addRow', $list);

    foreach ($row as $key => $value) {
        $wizard->set("form.rows_{$list}.{$index}.{$key}", $value);
    }
}

/** @return array<string, int> every table a hire writes, by row count */
function pdswizlCounts(): array
{
    $counts = [
        'employee_profiles' => EmployeeProfile::query()->count(),
        'employee_pds_details' => EmployeePdsDetail::query()->count(),
        'employee_addresses' => EmployeeAddress::query()->count(),
        'employee_children' => EmployeeChild::query()->count(),
        'employee_other_items' => EmployeeOtherItem::query()->count(),
    ];

    foreach (PdsLists::KEYS as $list) {
        $table = PdsLists::spec($list)['table'];
        $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : -1;
    }

    return $counts;
}

/* |---| Steps |---| */

it('lets every list step pass empty', function (int $step, string $list, string $title, string $text, string $empty) {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());

    $wizard = pdswizlFilled(pdswizlForm($site));

    for ($i = 0; $i < $step; $i++) {
        $wizard->call('goNext')->assertSet('step', $i + 1);
    }

    expect($wizard->html())->toMatch('/<h3[^>]*>\s*'.preg_quote($title, '/').'\s*<\/h3>/');
    $wizard->assertSee('Step '.($step + 1).' of 13')
        ->assertSee($text)
        ->assertSee($empty)
        ->assertSee(PdsLists::spec($list)['add']);

    // An untouched Add is a blank draft, not a row: the step still passes.
    $wizard->call('addRow', $list)
        ->assertSet("form.rows_{$list}", [PdsLists::blankRow($list)])
        ->assertDontSee($empty)
        ->call('goNext')
        ->assertSet('step', $step + 1);

    // Every list left so, the hire goes through and stores no row at all.
    $wizard->set('step', 12)->call('create')->assertDispatched('employee-created');

    expect(EmployeeProfile::query()->count())->toBe(1);
    foreach (PdsLists::KEYS as $key) {
        expect(DB::table(PdsLists::spec($key)['table'])->count())->toBe(0, $key);
    }
})->with([
    'education' => [5, 'education', 'Educational Background', 'A level with no school prints as N/A on the PDS.', 'None recorded · prints as N/A'],
    'eligibility' => [6, 'eligibility', 'Civil Service Eligibility', 'Leave Rating, License no. and Valid until blank when they do not apply.', 'None recorded · prints as N/A'],
    'work experience' => [7, 'experience', 'Work Experience', 'A job before this one. The current job comes from Employee Details.', 'No previous job recorded.'],
    'training' => [8, 'training', 'Learning and Development', 'Write the title and the sponsor in full.', 'None recorded · prints as N/A'],
    'voluntary work' => [9, 'voluntary', 'Voluntary Work', "Write the organization's name and address in full.", 'None recorded · prints as N/A'],
]);

it('adds and removes inline rows on a list step and holds the step on a bad row', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());

    $wizard = pdswizlFilled(pdswizlForm($site))->set('step', 5);

    pdswizlAddRow($wizard, 'education', 0, pdswizlRow('education'));
    pdswizlAddRow($wizard, 'education', 1, ['level' => 'GRADUATE', 'course' => 'MBA']);

    // Each row is its own block, its inputs named by their row.
    $wizard->assertSeeHtml('<h4 class="text-sm font-semibold">School 1</h4>')
        ->assertSeeHtml('<h4 class="text-sm font-semibold">School 2</h4>')
        ->assertSeeHtml('aria-label="Name of School — school 2"')
        ->assertSeeHtml('aria-label="Remove school 2"')
        ->assertSeeHtml('wire:model="form.rows_education.1.school"');

    expect($wizard->instance()->stepErrors(5))->toBe([
        'rows_education' => 'School 2: Name of School is required.',
        'rows_education.1.school' => 'Name of School is required.',
    ]);

    // Next is refused, with the row's message under its input and the
    // list's under the Add button.
    $wizard->call('goNext')
        ->assertSet('step', 5)
        ->assertSee('School 2: Name of School is required.')
        ->assertSeeHtml('<p class="text-xs text-danger">Name of School is required.</p>');

    // A shape problem on the first row names that row.
    $wizard->set('form.rows_education.0.attended_from', '08')
        ->assertSee('School 1: Attended From must be a four-digit year, like 2015.')
        ->set('form.rows_education.0.attended_from', '2008');

    // Removing the bad row clears its messages; the step passes.
    $wizard->call('removeRow', 'education', 1)
        ->assertSet('form.rows_education', [pdswizlRow('education')])
        ->assertDontSee('School 2')
        ->assertDontSee('Name of School is required.')
        ->call('goNext')
        ->assertSet('step', 6);

    expect($wizard->instance()->stepErrors(5))->toBe([]);

    // An unknown list is ignored, and a list stops growing at its cap.
    $wizard->call('addRow', 'references')
        ->assertSet('form.rows_references', null)
        ->set('form.rows_training', array_fill(0, PdsLists::MAX_ROWS, pdswizlRow('training')))
        ->call('addRow', 'training');

    expect($wizard->get('form.rows_training'))->toHaveCount(PdsLists::MAX_ROWS);

    // The Other info lines are added and removed in place as well.
    $wizard->set('step', 10)
        ->assertSeeHtml('<h4 class="text-sm font-semibold">Skills and Hobbies</h4>')
        ->assertSeeHtml('<h4 class="text-sm font-semibold">Distinctions</h4>')
        ->assertSeeHtml('<h4 class="text-sm font-semibold">Memberships</h4>')
        ->call('addItem', 'skills')
        ->set('form.skills.0', 'Bookkeeping')
        ->call('addItem', 'skills')
        ->set('form.skills.1', 'Chess')
        ->call('removeItem', 'skills', 0)
        ->assertSet('form.skills', ['Chess'])
        ->call('addItem', 'passwords')
        ->assertSet('form.passwords', null);
});

it('draws the Family and Declarations steps from the record\'s own parts', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());

    $wizard = pdswizlFilled(pdswizlForm($site))->set('step', 4)
        ->assertSeeHtmlInOrder([
            '<h4 class="text-sm font-semibold">Spouse</h4>',
            '<h4 class="text-sm font-semibold">Parents</h4>',
            '<h5 class="text-sm font-semibold">Father</h5>',
            '<h5 class="text-sm font-semibold">Mother&#039;s Maiden Name</h5>',
            '<h4 class="text-sm font-semibold">Children</h4>',
        ])
        ->assertSee('Employer / business name')
        ->assertDontSeeHtml('wire:model.blur="form.spouse_name"')
        ->call('addChild')
        ->assertSeeHtml('aria-label="Child 1 name"')
        ->assertSeeHtml('aria-label="Child 1 date of birth"');

    // A spouse's telephone in a shape no phone takes holds the step.
    $wizard->set('form.spouse_telephone', 'call me')
        ->call('goNext')
        ->assertSet('step', 4)
        ->set('form.spouse_telephone', '(042) 911 5690')
        ->call('goNext')
        ->assertSet('step', 5);

    // Declarations: the twelve questions, each detail only while Yes is chosen.
    $wizard->set('step', 11)
        ->assertSee('Answered Yes or No; details are kept only for a Yes.')
        ->assertSee('within the third degree?')
        ->assertDontSee('Date filed')
        ->set('form.decl_35b', 'yes')
        ->assertSee('Date filed')
        ->assertSee('Status of case/s')
        ->set('form.decl_35b', 'maybe')
        ->call('goNext')
        ->assertSet('step', 11)
        ->set('form.decl_35b', '')
        ->call('goNext')
        ->assertSet('step', 12);
});

it('jumps to the first step with an error when Create is pressed', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());

    $wizard = pdswizlFilled(pdswizlForm($site))->set('step', 6);
    pdswizlAddRow($wizard, 'eligibility', 0, ['rating' => '85.40']);

    // A bad eligibility row and a missing position: Employee Details first.
    $position = $wizard->get('form.position_id');
    $wizard->set('form.position_id', '')
        ->set('step', 12)
        ->call('create')
        ->assertNotDispatched('employee-created')
        ->assertSet('step', 3)
        ->assertSee('Position is required.');

    $wizard->set('form.position_id', $position)
        ->set('step', 12)
        ->call('create')
        ->assertNotDispatched('employee-created')
        ->assertSet('step', 6)
        ->assertSee('Eligibility 1: Eligibility is required.');

    expect(EmployeeProfile::query()->count())->toBe(0);

    $wizard->set('form.rows_eligibility.0.name', 'Career Service Professional')
        ->set('step', 12)
        ->call('create')
        ->assertDispatched('employee-created');

    expect(EmployeeEligibility::query()->sole()->only(['name', 'rating']))
        ->toBe(['name' => 'Career Service Professional', 'rating' => '85.40']);
});

/* |---| The hire |---| */

it('hires with the spouse, parents, children with birthdates, every list, Other info and declarations in one transaction', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());

    $wizard = pdswizlFilled(pdswizlForm($site) + pdswizlParts())
        ->call('addChild')
        ->set('form.children.0.name', 'Lucas Villanueva')
        ->set('form.children.0.birth_date', '2018-04-02');

    pdswizlAddRow($wizard, 'education', 0, ['level' => 'ELEMENTARY', 'school' => 'Tayabas East Central School', 'year_graduated' => '2002']);
    pdswizlAddRow($wizard, 'education', 1, pdswizlRow('education'));
    foreach (['eligibility', 'experience', 'training', 'voluntary'] as $list) {
        pdswizlAddRow($wizard, $list, 0, pdswizlRow($list));
    }
    $wizard->call('addItem', 'skills')->set('form.skills.0', 'Bookkeeping')
        ->call('addItem', 'skills')->set('form.skills.1', 'Chess')
        ->call('addItem', 'distinctions')->set('form.distinctions.0', 'Outstanding Volunteer 2019')
        ->call('addItem', 'memberships')->set('form.memberships.0', 'Philippine Institute of CPAs');

    // Every step passes with its parts filled in, Personal to Declarations.
    for ($i = 0; $i < 12; $i++) {
        $wizard->call('goNext')->assertSet('step', $i + 1);
    }

    $wizard->assertSee('Create Employee')->call('create')->assertDispatched('employee-created');

    $hire = EmployeeProfile::query()->sole();
    $details = EmployeePdsDetail::query()->where('employee_id', $hire->id)->sole();

    expect($details->only(['spouse_surname', 'spouse_first_name', 'spouse_middle_name', 'spouse_occupation', 'spouse_employer', 'spouse_telephone']))
        ->toBe(['spouse_surname' => 'Villanueva', 'spouse_first_name' => 'Ben', 'spouse_middle_name' => 'Reyes', 'spouse_occupation' => 'Teacher', 'spouse_employer' => 'DepEd Tayabas', 'spouse_telephone' => '(042) 911 5690'])
        ->and($details->only(['father_surname', 'father_first_name', 'father_name_extension', 'mother_surname', 'mother_first_name', 'mother_middle_name']))
        ->toBe(['father_surname' => 'Santos', 'father_first_name' => 'Pedro', 'father_name_extension' => 'Sr.', 'mother_surname' => 'Perez', 'mother_first_name' => 'Lourdes', 'mother_middle_name' => 'Castillo'])
        ->and($details->decl_34a)->toBeFalse()
        ->and($details->decl_35b)->toBeTrue()
        ->and($details->decl_36)->toBeNull()
        ->and($details->decl_35b_details)->toBe('Estafa')
        ->and($details->decl_35b_date_filed?->format('Y-m-d'))->toBe('2020-03-02')
        ->and($details->decl_35b_case_status)->toBe('Dismissed');

    expect(EmployeeChild::query()->where('employee_id', $hire->id)->get()
        ->map(static fn (EmployeeChild $c): array => [$c->position, $c->name, $c->birth_date?->format('Y-m-d')])->all())
        ->toBe([[0, 'Lucas Villanueva', '2018-04-02']]);

    expect(EmployeeEducation::query()->where('employee_id', $hire->id)->get()->sortBy('level')->map->only(['level', 'school', 'year_graduated'])->values()->all())
        ->toBe([
            ['level' => 'COLLEGE', 'school' => 'Colegio de la Ciudad de Tayabas', 'year_graduated' => 2012],
            ['level' => 'ELEMENTARY', 'school' => 'Tayabas East Central School', 'year_graduated' => 2002],
        ])
        ->and(EmployeeEligibility::query()->where('employee_id', $hire->id)->sole()->name)->toBe('Career Service Professional')
        ->and(EmployeeWorkExperience::query()->where('employee_id', $hire->id)->sole()->only(['position_title', 'monthly_salary', 'government_service']))
        ->toBe(['position_title' => 'Junior Accountant', 'monthly_salary' => 18000.5, 'government_service' => false])
        ->and(EmployeeTraining::query()->where('employee_id', $hire->id)->sole()->only(['title', 'hours']))
        ->toBe(['title' => 'Basic Tax Compliance for Payroll Officers', 'hours' => 24.0])
        ->and(EmployeeVoluntaryWork::query()->where('employee_id', $hire->id)->sole()->only(['organization', 'date_to']))
        ->toBe(['organization' => 'Philippine Red Cross, Tayabas Chapter', 'date_to' => null]);

    expect(EmployeeOtherItem::query()->where('employee_id', $hire->id)->orderBy('kind')->orderBy('position')->get()
        ->map(static fn (EmployeeOtherItem $item): array => [$item->kind, $item->position, $item->text])->all())
        ->toBe([
            ['DISTINCTION', 0, 'Outstanding Volunteer 2019'],
            ['MEMBERSHIP', 0, 'Philippine Institute of CPAs'],
            ['SKILL', 0, 'Bookkeeping'],
            ['SKILL', 1, 'Chess'],
        ]);

    // One audit row for the hire, and it names no declaration.
    $audit = AuditLog::query()->where('action', 'EMPLOYEE_CREATED')->get();
    expect($audit)->toHaveCount(1)
        ->and((string) $audit->sole()->details)->not->toContain('Estafa')
        ->and((string) $audit->sole()->details)->not->toContain('Dismissed');
});

it('writes nothing at all when one list row is refused while its table is missing', function () {
    $migration = require base_path('database/migrations/2026_10_07_0007_create_employee_trainings_table.php');
    $engine = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    Schema::drop('employee_trainings');
    // The table probes are answered once per request (EmployeeDetailRows::hasTable).
    Once::flush();
    // On MySQL/MariaDB the DROP commits the open transaction: everything this
    // test seeds goes into a transaction of its own, rolled back before the
    // table comes back.
    if ($engine) {
        DB::connection()->getPdo()->beginTransaction();
    }

    try {
        $site = pdswizlSite();
        $this->actingAs(pdswizlActor());
        $before = pdswizlCounts();

        $form = pdswizlForm($site) + pdswizlParts() + [
            'children' => [['name' => 'Lucas Villanueva', 'birth_date' => '2018-04-02']],
            'skills' => ['Bookkeeping'],
            'rows_education' => [pdswizlRow('education')],
            'rows_training' => [pdswizlRow('training')],
        ];

        // The command, without the page: refused, and the profile, details,
        // address, child, school and skill written before the training row
        // are rolled back with it.
        expect(fn () => app(CreateEmployee::class)->handle(app(Access::class), $form))
            ->toThrow(RuntimeException::class, EmployeeDetailRows::NOT_READY);

        // The wizard: the same refusal as a toast, back on the first step.
        pdswizlFilled($form)
            ->call('create')
            ->assertNotDispatched('employee-created')
            ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === EmployeeDetailRows::NOT_READY)
            ->assertSet('step', 0);

        expect(pdswizlCounts())->toBe(array_replace($before, ['employee_trainings' => -1]))
            ->and(AuditLog::query()->where('action', 'EMPLOYEE_CREATED')->exists())->toBeFalse();
    } finally {
        if ($engine) {
            DB::connection()->getPdo()->rollBack();
        }
        $migration->up();
        Once::flush();
        if ($engine) {
            DB::connection()->getPdo()->beginTransaction();
        }
    }
});

it('never writes a legacy column on a hire', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor());
    $legacy = ['address', 'citizenship', 'spouse_name', 'spouse_employment', 'college', 'college_course',
        'college_year_graduated', 'graduate_school', 'graduate_course', 'graduate_year_graduated'];

    // A tampered form carries every legacy key beside the structured parts.
    $form = pdswizlForm($site) + pdswizlParts() + array_fill_keys($legacy, 'Tampered') + [
        'rows_education' => [pdswizlRow('education'), ['level' => 'GRADUATE', 'school' => 'University of the Philippines']],
    ];

    DB::enableQueryLog();
    $view = app(CreateEmployee::class)->handle(app(Access::class), $form);
    // MySQL/MariaDB quote identifiers with backticks, sqlite with double
    // quotes: one spelling keeps the scan meaningful on both.
    $writes = array_filter(
        array_map(static fn (string $sql): string => str_replace('`', '"', $sql), array_column(DB::getQueryLog(), 'query')),
        static fn (string $sql): bool => preg_match('/^\s*(insert|update)\b/i', $sql) === 1 && str_contains($sql, '"employee_profiles"'),
    );
    DB::disableQueryLog();

    expect($writes)->not->toBe([]);
    foreach ($writes as $sql) {
        foreach ($legacy as $column) {
            expect($sql)->not->toContain('"'.$column.'"');
        }
    }

    $row = (array) DB::table('employee_profiles')->where('id', $view->id)->first($legacy);
    expect($row)->toBe(array_fill_keys($legacy, null))
        ->and(EmployeeEducation::query()->where('employee_id', $view->id)->count())->toBe(2);
});

it('refuses the hire to a caller without all_data, leaving every table empty', function () {
    $site = pdswizlSite();
    $this->actingAs(pdswizlActor(allData: false));
    $before = pdswizlCounts();

    $form = pdswizlForm($site) + pdswizlParts() + [
        'children' => [['name' => 'Lucas Villanueva', 'birth_date' => '2018-04-02']],
        'skills' => ['Bookkeeping'],
    ];
    foreach (PdsLists::KEYS as $list) {
        $form['rows_'.$list] = [pdswizlRow($list)];
    }

    pdswizlFilled($form)->call('create')->assertForbidden();

    expect(fn () => app(CreateEmployee::class)->handle(app(Access::class), $form))->toThrow(HttpException::class);

    expect(pdswizlCounts())->toBe($before)
        ->and(array_sum($before))->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
