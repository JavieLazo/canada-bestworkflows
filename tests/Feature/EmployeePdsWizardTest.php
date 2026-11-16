<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Models\EmployeeAddress;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Position;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The hire wizard asks what the record holds: thirteen steps, Personal
 * Details, Addresses, Emergency Contact and Employee Details first and
 * Government IDs & Bank last. A hire writes the
 * residential address in parts (at least one is required), the permanent
 * address or the "Same as residential" answer it was given, the structured
 * civil status and citizenship, UMID and PhilSys in their own rows, and the
 * height typed in metres as centimetres. It never writes the legacy one-line
 * address or citizenship text, and a caller without Employee Records
 * all-data hires nobody.
 */
function pdswizActor(bool $allData = true): User
{
    $role = Role::query()->create([
        'name' => 'Test wizard'.($allData ? ' all' : ''),
        'all_data' => $allData,
        'is_system' => false,
    ]);
    RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => ModuleCode::EMPLOYEES]);

    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id])->save();

    return $user->refresh();
}

function pdswizSite(): WorkSite
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
 * Every required answer of every step, the residential address as one part.
 *
 * @return array<string, mixed>
 */
function pdswizForm(WorkSite $site, string $email = 'juan.wizard@company.com'): array
{
    return [
        'employee_id_no' => 'ID-'.substr(md5($email), 0, 8),
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'phone' => '0917 123 4567',
        'email' => $email,
        'birth_date' => '1990-05-01',
        'sex' => 'Male',
        'civil_status' => 'Single',
        'res_street' => 'Rizal St.',
        'emergency_name' => 'Maria Dela Cruz',
        'emergency_relation' => 'Spouse',
        'emergency_phone' => '0917 987 6543',
        'position_id' => (string) Position::query()->where('name', 'Driver')->value('id'),
        'employment_status' => 'Probationary',
        'work_site_id' => $site->id,
        'date_hired' => '2026-08-01',
        'monthly_rate' => '25000',
    ];
}

/**
 * The wizard opened and filled with `$form`, key by key, as a person types.
 *
 * @param  array<string, mixed>  $form
 * @return Testable<AddEmployeeDialog>
 */
function pdswizFilled(array $form): Testable
{
    $wizard = Livewire::test(AddEmployeeDialog::class)->call('openDialog');

    foreach ($form as $key => $value) {
        $wizard->set("form.{$key}", $value);
    }

    return $wizard;
}

/** @return array{address: ?string, citizenship: ?string} */
function pdswizLegacy(string $id): array
{
    $row = DB::table('employee_profiles')->where('id', $id)->first(['address', 'citizenship']);

    return ['address' => $row?->address, 'citizenship' => $row?->citizenship];
}

it('walks thirteen steps with Personal, Addresses, Emergency and Employee Details first and Government IDs & Bank last', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    $form = pdswizForm($site);
    unset($form['res_street']);

    $titles = [
        'Personal Details', 'Addresses', 'Emergency Contact', 'Employee Details', 'Family',
        'Educational Background', 'Civil Service Eligibility', 'Work Experience', 'Learning and Development',
        'Voluntary Work', 'Other Information', 'Declarations', 'Government IDs &amp; Bank',
    ];
    $dots = array_map(static fn (string $title, int $i): string => 'aria-label="Step '.($i + 1).': '.$title.'"', $titles, array_keys($titles));

    $wizard = pdswizFilled($form)
        ->assertSet('step', 0)
        ->assertSee('Step 1 of 13')
        ->assertSeeHtmlInOrder($dots)
        ->assertSeeHtml('aria-label="Step 2: Addresses"')
        ->assertSeeHtml('aria-label="Step 13: Government IDs &amp; Bank"')
        ->assertDontSeeHtml('aria-label="Step 14:')
        // Thirteen dots fit from sm up; below it the "Step N of 13" line
        // and the step title carry the place.
        ->assertSeeHtml('<ol class="hidden items-center sm:flex">')
        // Personal Details in the record's words, without the one-line address.
        ->assertSee('Sex at birth')
        ->assertSee('Mobile no.')
        ->assertSee('In metres, like 1.68.')
        ->assertSeeHtml('aria-label="Citizenship"')
        ->assertDontSeeHtml('wire:key="add-address"')
        ->assertDontSeeHtml('wire:key="add-citizenship"');

    $wizard->call('goNext')
        ->assertSet('step', 1)
        ->assertSee('Step 2 of 13')
        ->assertSeeHtml('<h4 class="text-sm font-semibold">')
        ->assertSee('Residential Address')
        ->assertSee('Permanent Address')
        ->assertSeeHtml('aria-label="Residential house/block/lot no."')
        ->assertSeeHtml('aria-label="Same as residential"')
        // No record exists yet, so nothing was entered before.
        ->assertDontSee('Entered before');

    $wizard->set('form.res_barangay', 'Poblacion')
        ->call('goNext')
        ->assertSet('step', 2)
        ->assertSee('Emergency Contact');

    $wizard->set('step', 12)
        ->assertSee('Step 13 of 13')
        ->assertSee('Government IDs & Bank')
        ->assertSeeInOrder(['SSS Number', 'UMID ID no.', 'Pag-IBIG Number', 'PhilHealth Number', 'PhilSys card no.', 'TIN'])
        ->assertSee('Create Employee');
});

it('holds the wizard on Addresses until a residential part is filled', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    $form = pdswizForm($site);
    unset($form['res_street']);

    $wizard = pdswizFilled($form)->call('goNext')->assertSet('step', 1)
        // Quiet until a part is visited or Next is pressed.
        ->assertDontSee('Residential address is required — fill in at least one part.');

    // Visiting a part and leaving it blank names the group.
    $wizard->set('form.res_street', '')
        ->assertSet('touched.residential_address', true)
        ->assertSee('Residential address is required — fill in at least one part.')
        ->call('goNext')
        ->assertSet('step', 1);

    // Create from the last step jumps back here rather than hiring.
    $wizard->set('step', 12)->call('create')->assertSet('step', 1);
    expect(EmployeeProfile::query()->count())->toBe(0);

    $wizard->set('form.res_zip_code', '4327')
        ->assertDontSee('Residential address is required — fill in at least one part.')
        ->call('goNext')
        ->assertSet('step', 2);
});

it('asks for the permanent parts when Same as residential is No, without requiring one', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    $wizard = pdswizFilled(pdswizForm($site))->call('goNext')->assertSet('step', 1)
        ->assertSet('form.permanent_address_same', true)
        ->assertDontSeeHtml('aria-label="Permanent street"');

    // The permanent address may be left for later.
    $wizard->set('form.permanent_address_same', 'false')
        ->assertSet('touched.permanent_address', true)
        ->assertSeeHtml('aria-label="Permanent street"')
        ->assertSeeHtml('aria-label="Permanent ZIP code"')
        ->assertDontSee('Permanent address is required')
        ->call('goNext')
        ->assertSet('step', 2)
        ->set('step', 1);

    // A part's own rule still applies once it has an answer.
    $wizard->set('form.perm_zip_code', '43')
        ->assertSee('ZIP code must be 4 digits.')
        ->call('goNext')
        ->assertSet('step', 1);

    $wizard->set('form.perm_zip_code', '4301')
        ->call('goNext')
        ->assertSet('step', 2);

    // Back to Yes: the permanent parts leave the step and stop counting.
    $wizard->set('step', 1)
        ->set('form.perm_zip_code', '')
        ->set('form.permanent_address_same', 'true')
        ->assertDontSeeHtml('aria-label="Permanent street"')
        ->call('goNext')
        ->assertSet('step', 2);
});

it('hires with both address rows, the details row and centimetres from metres', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    pdswizFilled(pdswizForm($site) + [
        'height_cm' => '1.68',
        'res_house_no' => 'Blk 5 Lot 2',
        'res_city' => 'Tayabas City',
        'res_province' => 'Quezon',
        'res_zip_code' => '4327',
        'permanent_address_same' => 'false',
        'perm_barangay' => 'San Isidro',
        'perm_city' => 'Lucena City',
        'perm_zip_code' => '4301',
        'umid_no' => '0111-2222333-4',
        'philsys_pcn' => '1234-5678-9012-3456',
    ])
        ->set('form.civil_status', 'Other')
        ->set('form.civil_status_other', 'Civil union')
        ->set('form.citizenship_kind', 'DUAL')
        ->set('form.dual_citizenship_by', 'NATURALIZATION')
        ->set('form.dual_citizenship_country', 'Canada')
        ->call('create')
        ->assertDispatched('employee-created')
        ->assertSet('open', false);

    $hire = EmployeeProfile::query()->where('email', 'juan.wizard@company.com')->firstOrFail();
    $details = EmployeePdsDetail::query()->where('employee_id', $hire->id)->firstOrFail();
    $addresses = EmployeeAddress::query()->where('employee_id', $hire->id)->get()->keyBy('type');

    expect($hire->height_cm)->toBe(168.0)
        ->and($hire->civil_status)->toBe('Other')
        ->and($details->civil_status_other)->toBe('Civil union')
        ->and($details->citizenship_kind)->toBe('DUAL')
        ->and($details->dual_citizenship_by)->toBe('NATURALIZATION')
        ->and($details->dual_citizenship_country)->toBe('Canada')
        ->and($details->permanent_address_same)->toBeFalse()
        ->and($details->umid_no)->toBe('0111-2222333-4')
        ->and($details->philsys_pcn)->toBe('1234-5678-9012-3456')
        ->and($addresses->keys()->sort()->values()->all())->toBe(['PERMANENT', 'RESIDENTIAL'])
        ->and($addresses['RESIDENTIAL']->only(['house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zip_code']))
        ->toBe(['house_no' => 'Blk 5 Lot 2', 'street' => 'Rizal St.', 'subdivision' => null, 'barangay' => null, 'city' => 'Tayabas City', 'province' => 'Quezon', 'zip_code' => '4327'])
        ->and($addresses['PERMANENT']->only(['house_no', 'street', 'barangay', 'city', 'zip_code']))
        ->toBe(['house_no' => null, 'street' => null, 'barangay' => 'San Isidro', 'city' => 'Lucena City', 'zip_code' => '4301']);
});

it('hires with Same as residential left at Yes: the residential row and a details row that stores the answer', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    pdswizFilled(pdswizForm($site))
        ->assertSet('form.permanent_address_same', true)
        ->call('create')
        ->assertDispatched('employee-created');

    $hire = EmployeeProfile::query()->where('email', 'juan.wizard@company.com')->firstOrFail();
    $details = EmployeePdsDetail::query()->where('employee_id', $hire->id)->get();

    // The answer the form started at is the answer given: stored as true,
    // never left unanswered. Nothing else was typed into the details row.
    expect(EmployeeAddress::query()->where('employee_id', $hire->id)->pluck('type')->all())->toBe(['RESIDENTIAL'])
        ->and($details)->toHaveCount(1)
        ->and($details[0]->permanent_address_same)->toBeTrue()
        ->and($details[0]->only(['civil_status_other', 'citizenship_kind', 'dual_citizenship_by', 'dual_citizenship_country', 'umid_no', 'philsys_pcn']))
        ->toBe(['civil_status_other' => null, 'citizenship_kind' => null, 'dual_citizenship_by' => null, 'dual_citizenship_country' => null, 'umid_no' => null, 'philsys_pcn' => null]);
});

it('asks Specify and the dual citizenship parts only while their choice is picked, and stores none of them otherwise', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    $wizard = pdswizFilled(pdswizForm($site))
        ->assertDontSeeHtml('wire:key="add-civil_status_other"')
        ->assertDontSeeHtml('wire:key="add-dual_citizenship_by"')
        ->set('form.civil_status', 'Other')
        ->assertSeeHtml('wire:key="add-civil_status_other"')
        ->call('goNext')
        ->assertSet('step', 0)
        ->assertSee('Specify the civil status.')
        ->set('form.citizenship_kind', 'DUAL')
        ->assertSeeHtml('wire:key="add-dual_citizenship_by"')
        ->assertSeeHtml('wire:key="add-dual_citizenship_country"')
        ->assertSee('Choose whether the dual citizenship is by birth or by naturalization.')
        ->assertSee('Name the country of the other citizenship.');

    // Typed, then the choice changed: hidden again, and cleared on the hire.
    $wizard->set('form.civil_status_other', 'Civil union')
        ->set('form.dual_citizenship_by', 'BIRTH')
        ->set('form.dual_citizenship_country', 'Japan')
        ->set('form.civil_status', 'Married')
        ->set('form.citizenship_kind', 'FILIPINO')
        ->assertDontSeeHtml('wire:key="add-civil_status_other"')
        ->assertDontSeeHtml('wire:key="add-dual_citizenship_country"')
        ->call('create')
        ->assertDispatched('employee-created');

    $hire = EmployeeProfile::query()->where('email', 'juan.wizard@company.com')->firstOrFail();
    $details = EmployeePdsDetail::query()->where('employee_id', $hire->id)->firstOrFail();

    expect($hire->civil_status)->toBe('Married')
        ->and($details->citizenship_kind)->toBe('FILIPINO')
        ->and($details->civil_status_other)->toBeNull()
        ->and($details->dual_citizenship_by)->toBeNull()
        ->and($details->dual_citizenship_country)->toBeNull();
});

it('leaves the legacy address and citizenship empty on every hire', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor());

    pdswizFilled(pdswizForm($site, 'first.hire@company.com'))
        ->set('form.citizenship_kind', 'FILIPINO')
        ->call('create')
        ->assertDispatched('employee-created');

    pdswizFilled(pdswizForm($site, 'second.hire@company.com') + ['res_city' => 'Tayabas City'])
        ->set('form.citizenship_kind', 'DUAL')
        ->set('form.dual_citizenship_by', 'BIRTH')
        ->set('form.dual_citizenship_country', 'Spain')
        ->call('create')
        ->assertDispatched('employee-created');

    $first = EmployeeProfile::query()->where('email', 'first.hire@company.com')->firstOrFail();
    $second = EmployeeProfile::query()->where('email', 'second.hire@company.com')->firstOrFail();

    expect(pdswizLegacy($first->id))->toBe(['address' => null, 'citizenship' => null])
        ->and(pdswizLegacy($second->id))->toBe(['address' => null, 'citizenship' => null])
        ->and(EmployeePdsDetail::query()->where('employee_id', $first->id)->value('citizenship_kind'))->toBe('FILIPINO')
        ->and(EmployeeAddress::query()->where('employee_id', $second->id)->value('city'))->toBe('Tayabas City');
});

it('refuses the hire to a caller without all_data, leaving all three tables empty', function () {
    $site = pdswizSite();
    $this->actingAs(pdswizActor(allData: false));

    pdswizFilled(pdswizForm($site) + ['umid_no' => '0111-2222333-4', 'permanent_address_same' => 'false', 'perm_city' => 'Lucena City'])
        ->call('create')
        ->assertForbidden();

    expect(EmployeeProfile::query()->count())->toBe(0)
        ->and(EmployeePdsDetail::query()->count())->toBe(0)
        ->and(EmployeeAddress::query()->count())->toBe(0);
});
