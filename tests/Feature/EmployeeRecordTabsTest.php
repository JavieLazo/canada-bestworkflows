<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\AddEmployeeDialog;
use App\Livewire\Employees\Show;
use App\Models\AuditLog;
use App\Models\EmployeeAddress;
use App\Models\EmployeeChild;
use App\Models\EmployeeOtherItem;
use App\Models\EmployeePdsDetail;
use App\Models\EmployeeProfile;
use App\Models\EmploymentStatusOption;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Support\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The employee record is split into tabs drawn with the Leave Management
 * strip (wrapping, and a Section select below sm): Profile carries Personal
 * Details, Emergency Contact, Employee Details, Addresses and Government IDs
 * & Bank; Family carries Spouse, Parents and Children; the five list tabs
 * mount their `employees.pds-list` card, and the Documents tab its card,
 * only while shown; Other Info carries three cards of lines; Declarations
 * one card. `?tab=` opens a tab, an unknown value collapses to Profile,
 * editing a section moves to its tab, and leaving that tab drops the open
 * edit. On management views the print-only 201 sheet sits outside the tabs
 * whatever tab shows, so every tab assertion here is scoped to the cards
 * (the HTML before the sheet) or to a card's wire:key. No screen carries a
 * Personal Data Sheet item or part number.
 *
 * The cards prefer the structured values and fall back to the stored ones:
 * an untyped residential address reads "—" with the stored one-line address
 * once, in the "Entered before" note under the card; the stored spouse reads
 * the same way under the Spouse card; the stored citizenship text shows
 * under an unset Citizenship while editing. No legacy value is ever written.
 * Permitted own-record sections submit proposals for independent approval. A Payroll
 * viewer of another employee receives the same facts without edit controls.
 */

/** @param list<string> $modules */
function rectabRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill([
        'name' => 'Tabs '.implode('-', $modules).' '.Str::random(6),
        'all_data' => $allData,
        'is_system' => false,
    ])->save();

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

function rectabHr(): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => rectabRole([ModuleCode::EMPLOYEES], true)->id])->save();

    test()->actingAs($user->refresh());

    return $user;
}

/**
 * A record with a stored one-line address and citizenship text, a height in
 * centimetres, a spouse, a child and a college.
 *
 * @param  array<string, mixed>  $attributes
 */
function rectabEmployee(array $attributes = []): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Work From Home', 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    $employee = EmployeeProfile::query()->create($attributes + [
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
        'monthly_rate' => 30000, 'address' => 'Blk 3 Lot 7, Poblacion', 'citizenship' => 'Filipino',
        'phone' => '0917 555 0101', 'email' => 'rosa.'.Str::lower(Str::random(6)).'@company.com',
        'birth_date' => '1996-03-14', 'sex' => 'Female', 'civil_status' => 'Married', 'height_cm' => 162.5,
        'spouse_name' => 'Ben Villanueva', 'college' => 'Colegio de la Ciudad de Tayabas',
    ]);

    $child = new EmployeeChild;
    $child->fill(['employee_id' => $employee->id, 'position' => 0, 'name' => 'Ana Villanueva', 'birth_date' => '2019-05-04'])->save();

    return $employee;
}

/** A signed-in viewer holding $role, linked to $employee when given. */
function rectabViewer(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

/** The record page's cards: everything before the print-only 201 sheet. */
function rectabCards(string $html): string
{
    $end = strpos($html, '<section class="sheet-201');

    return $end === false ? $html : substr($html, 0, $end);
}

/** The print-only 201 sheet. */
function rectabSheet(string $html): string
{
    $start = strpos($html, '<section class="sheet-201');

    return $start === false ? '' : substr($html, $start);
}

/* |---| The tabs |---| */

it('draws the record tabs in order in the strip and the Section select', function () {
    $employee = rectabEmployee();
    rectabHr();

    $cards = rectabCards(Livewire::test(Show::class, ['employee' => $employee->id])->html());
    $labels = ['Profile', 'Family', 'Education', 'Eligibility', 'Work Experience', 'Training', 'Voluntary Work', 'Other Info', 'Declarations', 'Documents', 'Issued Items'];

    // Leave Management's strip, sized by its content and wrapping, so the
    // tabs take a second row at narrower widths instead of being clipped.
    $strip = '<div class="inline-flex h-auto flex-wrap items-center justify-start rounded-lg bg-muted p-1 text-muted-foreground" role="tablist" aria-label="Record sections">';

    expect($cards)->toContain($strip)
        ->not->toContain('inline-flex h-9 items-center')
        ->and(substr_count($cards, 'role="tab"'))->toBe(11)
        ->and(substr_count($cards, 'aria-selected="true"'))->toBe(1)
        ->and(array_values(Show::TABS))->toBe($labels)
        ->and(Show::LIST_TABS)->toBe(['education', 'eligibility', 'experience', 'training', 'voluntary']);

    // The strip's buttons, in order.
    $tablist = substr($cards, (int) strpos($cards, $strip), (int) strpos($cards, '<div class="relative w-full sm:hidden">') - (int) strpos($cards, $strip));
    $at = 0;

    foreach (array_keys(Show::TABS) as $key) {
        $next = strpos($tablist, 'wire:click="$set(\'tab\', \''.$key.'\')"', $at);

        expect($next)->not->toBeFalse();
        $at = (int) $next;
    }

    foreach ($labels as $label) {
        expect($tablist)->toContain($label);
    }

    // Below sm the strip hides and a bare select named "Section" takes
    // over, with no visible label, one option per tab in strip order and
    // the current tab selected in the first paint.
    $select = substr($cards, (int) strpos($cards, '<div class="relative w-full sm:hidden">'));
    $at = 0;

    expect($cards)->toContain('<div class="hidden sm:block">')
        ->and($select)->toContain('wire:model.live="tab"')
        ->toContain('aria-label="Section"')
        ->not->toContain('<ui-label')
        ->and(substr_count($select, '<option'))->toBe(11)
        ->and(substr_count($select, 'selected="selected"'))->toBe(1)
        ->and($select)->toMatch('/<option\s+selected="selected"\s+value="profile"/');

    foreach (array_keys(Show::TABS) as $key) {
        $next = strpos($select, 'value="'.$key.'"', $at);

        expect($next)->not->toBeFalse();
        $at = (int) $next;
    }

    // On another tab the select already shows that tab, not Profile.
    $other = rectabCards(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'education')->html());
    $select = substr($other, (int) strpos($other, '<div class="relative w-full sm:hidden">'));

    expect(substr_count($select, 'selected="selected"'))->toBe(1)
        ->and($select)->toMatch('/<option\s+selected="selected"\s+value="education"/');
});

it('opens each tab from ?tab= and falls back to Profile for an unknown one', function (string $tab) {
    $employee = rectabEmployee();
    rectabHr();

    try {
        Livewire::withQueryParams(['tab' => $tab]);
        Livewire::test(Show::class, ['employee' => $employee->id])
            ->assertSet('tab', $tab)
            ->assertSeeHtml('aria-selected="true"');

        Livewire::withQueryParams(['tab' => 'payroll']);
        Livewire::test(Show::class, ['employee' => $employee->id])
            ->assertSet('tab', 'profile')
            ->assertSeeHtml('wire:key="section-personal"');
    } finally {
        Livewire::withQueryParams([]);
    }
})->with(array_keys(Show::TABS));

it('opens on Profile with its five cards and no card of another tab', function () {
    $employee = rectabEmployee();
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSet('tab', 'profile')
        ->assertSeeHtml('wire:key="section-personal"')
        ->assertSeeHtml('wire:key="section-emergency"')
        ->assertSeeHtml('wire:key="section-employment"')
        ->assertSeeHtml('wire:key="section-addresses"')
        ->assertSeeHtml('wire:key="section-statutory"')
        ->assertDontSeeHtml('wire:key="section-spouse"')
        ->assertDontSeeHtml('wire:key="section-children"')
        ->assertDontSeeHtml('wire:key="section-skills"')
        ->assertDontSeeHtml('&quot;name&quot;:&quot;employees.pds-list&quot;')
        ->assertDontSeeHtml('&quot;name&quot;:&quot;employees.documents&quot;');

    $cards = rectabCards($page->html());

    expect($cards)->toContain('Government IDs &amp; Bank')
        ->toContain('>Addresses</h2>')
        ->toContain('Delete Employee')
        ->not->toContain('Colegio de la Ciudad de Tayabas')
        ->not->toContain('Ana Villanueva');
});

it('collapses a tampered tab value back to Profile and drops an open edit', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->set('tab', 'payroll')
        ->assertSet('tab', 'profile')
        // Leaving the edited section's tab drops the edit: no hidden draft.
        ->call('startEdit', 'personal')
        ->assertSet('editingSection', 'personal')
        ->set('tab', 'other')
        ->assertSet('tab', 'other')
        ->assertSet('editingSection', null)
        // A made-up value while the spouse is being edited lands on Profile
        // and drops that edit too.
        ->call('startEdit', 'spouse')
        ->assertSet('tab', 'family')
        ->set('tab', 'nonsense')
        ->assertSet('tab', 'profile')
        ->assertSet('editingSection', null)
        ->assertSeeHtml('wire:key="section-personal"');
});

it('keeps an open edit when the tab already showing is chosen again', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'statutory')
        ->set('form.sss_no', '34-1111111-1')
        ->set('tab', 'profile')
        ->assertSet('editingSection', 'statutory')
        ->assertSet('form.sss_no', '34-1111111-1')
        ->call('startEdit', 'skills')
        ->set('tab', 'other')
        ->assertSet('editingSection', 'skills');
});

it('mounts the Documents card only on its tab', function () {
    $employee = rectabEmployee();
    rectabHr();

    $documents = '&quot;name&quot;:&quot;employees.documents&quot;';

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertDontSeeHtml($documents)
        ->set('tab', 'documents')
        ->assertSeeHtml($documents)
        ->assertDontSeeHtml('wire:key="section-personal"')
        ->set('tab', 'family')
        ->assertDontSeeHtml($documents)
        ->set('tab', 'other')
        ->assertDontSeeHtml($documents);
});

it('mounts a list tab\'s card only on its tab', function () {
    $employee = rectabEmployee();
    rectabHr();

    // The child's wire:snapshot names it — what assertSeeLivewire() looks
    // for, spelt out because PHPStan cannot see that macro.
    $list = '&quot;name&quot;:&quot;employees.pds-list&quot;';

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertDontSeeHtml($list)
        ->set('tab', 'education')
        ->assertSeeHtml($list)
        ->assertSeeHtml('&quot;list&quot;:&quot;education&quot;')
        ->assertDontSeeHtml('wire:key="section-personal"')
        ->set('tab', 'family')
        ->assertDontSeeHtml($list)
        ->set('tab', 'training')
        ->assertSeeHtml($list)
        ->assertSeeHtml('&quot;list&quot;:&quot;training&quot;');
});

it('keeps management editing documents and printing available for any employee record', function (bool $linked) {
    EmploymentStatusOption::query()->create(['code' => 'REG', 'name' => 'Regular']);
    $employee = rectabEmployee(['employee_id_no' => 'STAFF-42']);
    rectabViewer(rectabRole([ModuleCode::EMPLOYEES], true), $linked ? $employee : null);
    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSeeHtml('wire:click="startEdit(\'employment\')"')
        ->assertSeeHtml('wire:click="printPds"')
        ->call('startEdit', 'employment')
        ->set('form.monthly_rate', '35000')
        ->call('saveSection', 'employment')
        ->assertSet('editingSection', null);
    expect($employee->refresh()->monthly_rate)->toBe(35000.0);

    $page->call('startEdit', 'personal')
        ->assertSeeHtml('wire:model.blur="form.employee_id_no"')
        ->set('form.employee_id_no', 'STAFF-43')
        ->call('saveSection', 'personal')
        ->assertSet('editingSection', null)
        ->call('printPds')
        ->assertFileDownloaded()
        ->set('tab', 'documents')
        ->assertSeeHtml('&quot;name&quot;:&quot;employees.documents&quot;');
    expect($employee->refresh()->employee_id_no)->toBe('STAFF-43');
})->with([false, true]);

it('keeps the whole 201 sheet on the page on every tab', function () {
    $employee = rectabEmployee();
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id]);

    foreach (array_keys(Show::TABS) as $tab) {
        $sheet = rectabSheet($page->set('tab', $tab)->html());

        expect($sheet)->toContain('162.5 cm')
            ->toContain('Ana Villanueva')
            ->toContain('Colegio de la Ciudad de Tayabas')
            ->toContain('Ben Villanueva')
            ->toContain('Blk 3 Lot 7, Poblacion');
    }
});

it('moves to the tab of the section being edited', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'children')
        ->assertSet('tab', 'family')
        ->assertSet('editingSection', 'children')
        ->assertSeeHtml('aria-label="Child 1 name"')
        ->assertSeeHtml('aria-label="Child 1 date of birth"')
        ->call('startEdit', 'addresses')
        ->assertSet('tab', 'profile')
        ->assertSet('editingSection', 'addresses')
        // An unknown section, or one the registry no longer has, changes
        // nothing.
        ->set('tab', 'documents')
        ->call('startEdit', 'payroll')
        ->assertSet('tab', 'documents')
        ->call('startEdit', 'family')
        ->assertSet('tab', 'documents')
        ->assertSet('editingSection', null);
});

it('moves to Family when the spouse is edited and to Other Info when a skill line is edited', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'spouse')
        ->assertSet('tab', 'family')
        ->assertSet('editingSection', 'spouse')
        ->assertSeeHtml('aria-label="Spouse&#039;s surname"')
        ->call('startEdit', 'skills')
        ->assertSet('tab', 'other')
        ->assertSet('editingSection', 'skills')
        ->assertSeeHtml('wire:click="addItem(\'skills\')"');
});

it('drops an open spouse edit when another tab is chosen', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'spouse')
        ->set('form.spouse_surname', 'Villanueva')
        ->set('tab', 'family')
        ->assertSet('editingSection', 'spouse')
        ->set('tab', 'other')
        ->assertSet('editingSection', null)
        ->set('tab', 'family')
        ->assertDontSeeHtml('aria-label="Spouse&#039;s surname"');

    expect(EmployeePdsDetail::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

it('shows no PDS item or part numbers anywhere in the cards', function (string $tab) {
    $employee = rectabEmployee();
    rectabHr();

    $cards = rectabCards(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', $tab)->html());

    expect(preg_match('/\b(I|II|III|IV|V|VI|VII|VIII)\s*·/', $cards))->toBe(0)
        ->and(preg_match('/\b(2[2-9]|3[0-9]|40)\.\s/', $cards))->toBe(0);
})->with(array_keys(Show::TABS));

it('probes each PDS table at most once per request', function () {
    $employee = rectabEmployee();
    rectabHr();

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'education');
        Livewire::test('employees.pds-list', ['employeeId' => $employee->id, 'list' => 'education']);
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    foreach (['employee_pds_details', 'employee_addresses', 'employee_education', 'employee_other_items'] as $table) {
        $probes = array_filter($log, static fn (array $entry): bool => (str_contains((string) $entry['query'], 'sqlite_master') || str_contains((string) $entry['query'], 'information_schema'))
            && (str_contains((string) $entry['query'], "'".$table."'") || in_array($table, $entry['bindings'], true)));

        expect(count($probes))->toBeLessThanOrEqual(1);
    }
});

/* |---| Family and Other Info |---| */

it('draws Spouse, Parents and Children on Family with the legacy spouse note while no part is typed', function () {
    $employee = rectabEmployee(['spouse_employment' => 'Teacher, DepEd']);
    rectabHr();

    $note = 'Entered before: Ben Villanueva · Teacher, DepEd — shown until the spouse is typed in parts.';

    $page = Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'family');
    $cards = rectabCards($page->html());

    // The Family grid: Spouse on the left, Parents and Children stacked on
    // the right, each its own card.
    expect($cards)->toContain('<div class="grid items-start gap-4 xl:grid-cols-2">')
        ->toContain('<div wire:key="section-spouse" class="hris-card overflow-hidden">')
        ->toContain('<div class="min-w-0 space-y-4">')
        ->toContain('<div wire:key="section-parents" class="hris-card overflow-hidden">')
        ->toContain('<section wire:key="section-children" class="hris-card overflow-hidden">')
        ->toContain('<h2 class="text-base font-bold">Spouse</h2>')
        ->toContain('<h2 class="text-base font-bold">Parents</h2>')
        ->toContain('<h2 class="text-base font-bold">Children</h2>');

    foreach (['Surname', 'First name', 'Name extension', 'Middle name', 'Occupation', 'Employer / business name', 'Business address', 'Telephone no.'] as $label) {
        expect($cards)->toContain('>'.$label.'</dt>');
    }

    // Nothing typed yet: every part reads "—", the stored spouse shows once,
    // in the note, and each parent reads "—".
    expect($cards)->toContain('<p class="mt-4 text-sm text-muted-foreground">'.$note.'</p>')
        ->and(substr_count($cards, 'Ben Villanueva'))->toBe(1)
        ->and($cards)->toMatch('/>Father<\/dt>\s*<dd class="mt-1 break-words text-sm">—<\/dd>/')
        ->toMatch('/>Mother\'s Maiden Name<\/dt>\s*<dd class="mt-1 break-words text-sm">—<\/dd>/')
        ->not->toContain('Spouse&#039;s Name');

    // Typed parts replace the note, and the parents read as one line each.
    EmployeePdsDetail::query()->create([
        'employee_id' => $employee->id,
        'spouse_surname' => 'Villanueva', 'spouse_first_name' => 'Benjamin', 'spouse_occupation' => 'Teacher III',
        'father_surname' => 'Dela Cruz', 'father_first_name' => 'Pedro', 'father_middle_name' => 'Ramos', 'father_name_extension' => 'Sr.',
        'mother_surname' => 'Perez', 'mother_first_name' => 'Lourdes', 'mother_middle_name' => 'Castillo',
    ]);

    $cards = rectabCards(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'family')->html());

    expect($cards)->toContain('Benjamin')
        ->toContain('Teacher III')
        ->toContain('Dela Cruz, Pedro Ramos Sr.')
        ->toContain('Perez, Lourdes Castillo')
        ->not->toContain('Entered before:')
        ->not->toContain('Ben Villanueva')
        // The stored spouse is never written.
        ->and(DB::table('employee_profiles')->where('id', $employee->id)->value('spouse_name'))->toBe('Ben Villanueva');
});

it('draws the children with their dates of birth and No children listed. when none', function () {
    $employee = rectabEmployee();
    $second = new EmployeeChild;
    $second->fill(['employee_id' => $employee->id, 'position' => 1, 'name' => 'Ben Villanueva Jr.'])->save();
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'family');
    $cards = rectabCards($page->html());

    expect($cards)->toContain('<div class="hris-table-wrap hris-table-scroll">')
        ->toMatch('/<th>Full Name<\/th>\s*<th>Date of Birth<\/th>/')
        ->toContain('<td class="font-medium">Ana Villanueva</td>')
        ->toContain('<td class="num whitespace-nowrap">04 May 2019</td>')
        ->toContain('<td class="font-medium">Ben Villanueva Jr.</td>')
        ->toContain('<td class="num whitespace-nowrap">—</td>')
        ->not->toContain('2019-05-04');

    // The editor binds each child's date of birth beside the name.
    $page->call('startEdit', 'children')
        ->assertSeeHtml('aria-label="Child 2 date of birth"')
        ->set('form.children.1.birth_date', '2021-11-30')
        ->call('saveSection', 'children')
        ->assertSet('editingSection', null)
        ->assertSet('formErrors', []);

    expect(rectabCards($page->html()))->toContain('<td class="num whitespace-nowrap">30 Nov 2021</td>')
        ->and(EmployeeChild::query()->where('employee_id', $employee->id)->where('position', 1)->first()?->birth_date?->format('Y-m-d'))->toBe('2021-11-30');

    EmployeeChild::query()->where('employee_id', $employee->id)->delete();

    expect(rectabCards(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'family')->html()))
        ->toContain('No children listed.')
        ->not->toContain('<td class="font-medium">');
});

it('draws the three Other Info cards with one line per item', function () {
    $employee = rectabEmployee();
    foreach ([['SKILL', 0, 'Advanced Excel'], ['SKILL', 1, 'Chess'], ['MEMBERSHIP', 0, 'Philippine Institute of Certified Public Accountants (PICPA)']] as [$kind, $position, $text]) {
        EmployeeOtherItem::query()->create(['employee_id' => $employee->id, 'kind' => $kind, 'position' => $position, 'text' => $text]);
    }
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'other');
    $cards = rectabCards($page->html());

    expect($cards)->toContain('<div class="grid items-start gap-4 xl:grid-cols-3">')
        ->toContain('<div wire:key="section-skills" class="hris-card overflow-hidden">')
        ->toContain('<div wire:key="section-distinctions" class="hris-card overflow-hidden">')
        ->toContain('<div wire:key="section-memberships" class="hris-card overflow-hidden">')
        ->toContain('<h2 class="text-base font-bold">Skills and Hobbies</h2>')
        ->toContain('<h2 class="text-base font-bold">Distinctions</h2>')
        ->toContain('<h2 class="text-base font-bold">Memberships</h2>')
        ->toContain('>Special skills and hobbies</dt>')
        ->toContain('>Non-academic distinctions / recognition</dt>')
        ->toContain('>Membership in association / organization</dt>')
        ->toMatch('/<dd class="mt-1 break-words text-sm">Advanced Excel<\/dd>\s*<dd class="mt-1 break-words text-sm">Chess<\/dd>/')
        ->toContain('<dd class="mt-1 break-words text-sm">Philippine Institute of Certified Public Accountants (PICPA)</dd>')
        // No distinction yet: one dash.
        ->toMatch('/>Non-academic distinctions \/ recognition<\/dt>(\s|<!--\[if BLOCK\]><!\[endif\]-->)*<dd class="mt-1 break-words text-sm">—<\/dd>/');
    // The cards name no form part (the Print menu's "Personal Data Sheet"
    // item sits above the tabs, outside them).
    expect(substr($cards, (int) strpos($cards, '<div class="grid items-start gap-4 xl:grid-cols-3">')))->not->toContain('Personal Data Sheet');

    // Lines are added and removed in place; a made-up list is ignored.
    $page->call('startEdit', 'skills')
        ->assertSet('form.skills', ['Advanced Excel', 'Chess'])
        ->assertSeeHtml('aria-label="Special skills and hobbies 1"')
        ->call('addItem', 'skills')
        ->set('form.skills.2', 'Public speaking')
        ->call('removeItem', 'skills', 1)
        ->call('addItem', 'payroll')
        ->assertSet('form.skills', ['Advanced Excel', 'Public speaking'])
        ->assertSet('form.payroll', null)
        ->call('saveSection', 'skills')
        ->assertSet('editingSection', null);

    expect(EmployeeOtherItem::query()->where('employee_id', $employee->id)->where('kind', 'SKILL')->orderBy('position')->pluck('text')->all())
        ->toBe(['Advanced Excel', 'Public speaking'])
        ->and(AuditLog::query()->where('action', 'EMPLOYEE_UPDATED')->latest('id')->value('details'))->not->toContain('Public speaking');
});

it('names each parent input by its group', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'parents')
        ->assertSet('tab', 'family')
        ->assertSeeHtml('<h3 class="text-sm font-semibold">Father</h3>')
        ->assertSeeHtml('<h3 class="text-sm font-semibold">Mother&#039;s Maiden Name</h3>')
        ->assertSeeHtml('aria-label="Father&#039;s surname"')
        ->assertSeeHtml('aria-label="Father&#039;s name extension"')
        ->assertSeeHtml('aria-label="Mother&#039;s maiden surname"')
        ->assertSeeHtml('aria-label="Mother&#039;s maiden middle name"')
        ->assertDontSeeHtml('aria-label="Surname"')
        ->assertDontSeeHtml('aria-label="Mother&#039;s maiden name extension"')
        ->set('form.father_surname', 'Dela Cruz')
        ->set('form.father_first_name', 'Pedro')
        ->call('saveSection', 'parents')
        ->assertSet('editingSection', null)
        ->assertSeeHtml('Dela Cruz, Pedro');
});

it('offers My Profile proposals while keeping nonmanager Employee Records views read-only', function (string $viewer) {
    $employee = rectabEmployee();
    rectabViewer(
        match ($viewer) {
            'profile' => rectabRole([ModuleCode::PROFILE], false),
            'own record' => rectabRole([ModuleCode::EMPLOYEES], false),
            default => rectabRole([ModuleCode::PAYROLL], true),
        },
        $viewer !== 'payroll' ? $employee : null,
    );
    $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => $viewer === 'profile']);
    foreach (['personal', 'addresses', 'spouse', 'parents', 'children', 'skills', 'distinctions', 'memberships', 'declarations'] as $section) {
        $page->call('cancelEditing')->call('startEdit', $section)
            ->assertSet('editingSection', $viewer === 'profile' ? $section : null);
        if ($viewer === 'profile') {
            $page->assertSee('Submit for Approval')->assertDontSee('Reason for Change');
        } else {
            $page->assertDontSee('Submit for Approval');
        }
    }
})->with(['profile', 'own record', 'payroll']);

/* |---| The Profile cards |---| */

it('labels Date of Birth on the card and the edit form', function () {
    $employee = rectabEmployee();
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id]);

    expect(rectabCards($page->html()))->toContain('>Date of Birth</dt>')
        ->not->toContain('Birthday');

    $page->call('startEdit', 'personal')
        ->assertSeeHtml('aria-label="Date of Birth"');

    expect(rectabCards($page->html()))->not->toContain('Birthday');
});

it('shows a dash for the residential address and the stored address once, in the Entered before note', function () {
    $employee = rectabEmployee();
    rectabHr();

    $note = 'Entered before: Blk 3 Lot 7, Poblacion — shown until the address is typed in parts.';

    $page = Livewire::test(Show::class, ['employee' => $employee->id]);
    $cards = rectabCards($page->html());

    // Nothing typed: the residential line reads "—", the stored line shows
    // once, in the note under the card; nobody has answered whether the
    // permanent address is the same, so it reads "—" too.
    expect($cards)->toMatch('/>Residential Address<\/dt>\s*<dd class="mt-1 break-words text-sm">—<\/dd>/')
        ->toMatch('/>Permanent Address<\/dt>\s*<dd class="mt-1 break-words text-sm">—<\/dd>/')
        ->toContain('<p class="mt-4 text-sm text-muted-foreground">'.$note.'</p>')
        ->not->toContain('was entered before as one line')
        ->not->toContain('Same as residential')
        ->and(substr_count($cards, 'Blk 3 Lot 7, Poblacion'))->toBe(1);

    // Typing the parts replaces it; the form's switch starts at Yes, and the
    // save stores that answer.
    $page->call('startEdit', 'addresses')
        ->assertSeeHtml('<p class="text-xs text-muted-foreground">'.$note.'</p>')
        ->set('form.res_house_no', 'Blk 5 Lot 2')
        ->set('form.res_street', 'Rizal St.')
        ->set('form.res_city', 'Tayabas City')
        ->set('form.res_province', 'Quezon')
        ->set('form.res_zip_code', '4327')
        ->call('saveSection', 'addresses')
        ->assertSet('editingSection', null)
        ->assertSet('formErrors', []);

    $cards = rectabCards($page->html());

    expect($cards)->toContain('Blk 5 Lot 2, Rizal St., Tayabas City, Quezon 4327')
        ->toContain('Same as residential')
        ->not->toContain('Entered before:')
        ->not->toContain('Blk 3 Lot 7, Poblacion')
        // The stored line is never written, and the sheet still falls back
        // to it only while nothing is typed — now it prints the parts.
        ->and(DB::table('employee_profiles')->where('id', $employee->id)->value('address'))->toBe('Blk 3 Lot 7, Poblacion');
});

it('keeps the stored address on the sheet while the card reads a dash', function () {
    $employee = rectabEmployee();
    rectabHr();

    expect(rectabSheet(Livewire::test(Show::class, ['employee' => $employee->id])->html()))->toContain('Blk 3 Lot 7, Poblacion');
});

it('holds an Addresses save that blanks every typed part while a stored one-line address exists, under the residential group', function () {
    $employee = rectabEmployee();
    EmployeeAddress::query()->create(['employee_id' => $employee->id, 'type' => EmployeeAddress::RESIDENTIAL, 'street' => 'Rizal St.']);
    rectabHr();

    $message = 'Residential address needs at least one part — the address entered before is kept until it is replaced.';

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'addresses')
        ->set('form.res_street', '')
        ->call('saveSection', 'addresses')
        ->assertSet('editingSection', 'addresses')
        ->assertSet('formErrors.residential_address', $message)
        ->assertSeeHtml('<p class="text-xs text-danger">'.$message.'</p>');

    expect(EmployeeAddress::query()->where('employee_id', $employee->id)->value('street'))->toBe('Rizal St.')
        ->and(DB::table('employee_profiles')->where('id', $employee->id)->value('address'))->toBe('Blk 3 Lot 7, Poblacion');
});

it('labels Sex at birth and Mobile no., shows height in metres and Civil Status Other with its specification', function () {
    $employee = rectabEmployee(['civil_status' => 'Other']);
    EmployeePdsDetail::query()->create([
        'employee_id' => $employee->id, 'civil_status_other' => 'Separated in fact',
        'citizenship_kind' => 'DUAL', 'dual_citizenship_by' => 'NATURALIZATION', 'dual_citizenship_country' => 'Japan',
    ]);
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id]);
    $cards = rectabCards($page->html());

    expect($cards)->toContain('Sex at birth')
        ->toContain('Mobile no.')
        ->not->toContain('Gender')
        ->toContain('1.625 m')
        ->not->toContain('162.5 cm')
        ->toContain('Other — Separated in fact')
        // The structured citizenship wins over the stored text, in one row.
        ->toContain('Dual citizenship · by naturalization · Japan')
        // Fields that wait on another answer have no read row of their own.
        ->not->toContain('>Specify</dt>')
        ->not->toContain('>Dual citizenship</dt>')
        ->not->toContain('>Country</dt>')
        ->not->toContain('shown until a citizenship is chosen.');

    // The sheet keeps its centimetres.
    expect(rectabSheet($page->html()))->toContain('162.5 cm');
});

it('shows Specify and the dual citizenship parts only while their choice is picked', function () {
    $employee = rectabEmployee();
    rectabHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->assertDontSeeHtml('aria-label="Specify"')
        ->set('form.civil_status', 'Other')
        ->assertSeeHtml('aria-label="Specify"')
        ->set('form.civil_status', 'Single')
        ->assertDontSeeHtml('aria-label="Specify"')
        ->assertDontSeeHtml('aria-label="Dual citizenship"')
        ->assertDontSeeHtml('aria-label="Country"')
        ->set('form.citizenship_kind', 'DUAL')
        ->assertSeeHtml('aria-label="Dual citizenship"')
        ->assertSeeHtml('aria-label="Country"');

    // Dual citizenship without its parts is held under each field, and
    // nothing is written.
    $page->call('saveSection', 'personal')
        ->assertSet('editingSection', 'personal')
        ->assertSet('formErrors.dual_citizenship_by', 'Choose whether the dual citizenship is by birth or by naturalization.')
        ->assertSet('formErrors.dual_citizenship_country', 'Name the country of the other citizenship.');

    expect(EmployeePdsDetail::query()->where('employee_id', $employee->id)->exists())->toBeFalse();

    $page->set('form.citizenship_kind', 'FILIPINO')
        ->assertDontSeeHtml('aria-label="Dual citizenship"')
        ->assertDontSeeHtml('aria-label="Country"')
        ->call('saveSection', 'personal')
        ->assertSet('editingSection', null);

    expect(EmployeePdsDetail::query()->where('employee_id', $employee->id)->value('citizenship_kind'))->toBe('FILIPINO')
        ->and(DB::table('employee_profiles')->where('id', $employee->id)->value('citizenship'))->toBe('Filipino');
});

it('shows the stored citizenship text as Entered before under an unset Citizenship while editing', function () {
    $employee = rectabEmployee(['citizenship' => 'Filipino by birth']);
    rectabHr();

    $note = 'Entered before: Filipino by birth — shown until a citizenship is chosen.';

    $page = Livewire::test(Show::class, ['employee' => $employee->id]);

    // Read mode shows the stored text as the citizenship, without the note.
    expect(rectabCards($page->html()))->toContain('Filipino by birth')
        ->not->toContain($note);

    $page->call('startEdit', 'personal')
        ->assertSeeHtml($note)
        ->set('form.citizenship_kind', 'FILIPINO')
        ->assertDontSeeHtml($note);

    // Once a citizenship is stored, the note is gone for good.
    EmployeePdsDetail::query()->create(['employee_id' => $employee->id, 'citizenship_kind' => 'FILIPINO']);

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'personal')
        ->assertDontSeeHtml('shown until a citizenship is chosen.');
});

it('names each address input by its group', function () {
    $employee = rectabEmployee();
    rectabHr();

    Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'addresses')
        ->assertSeeHtml('<h3 class="text-sm font-semibold">')
        ->assertSeeHtml('aria-label="Residential street"')
        ->assertSeeHtml('aria-label="Residential ZIP code"')
        ->assertSeeHtml('inputmode="numeric"')
        ->assertSeeHtml('aria-label="Same as residential"')
        // The permanent parts wait for "No".
        ->assertDontSeeHtml('aria-label="Permanent street"')
        ->set('form.permanent_address_same', 'false')
        ->assertSeeHtml('aria-label="Permanent street"')
        ->assertSeeHtml('aria-label="Permanent ZIP code"')
        ->assertSeeHtml('aria-label="Permanent house/block/lot no."')
        ->assertDontSeeHtml('aria-label="Street"')
        ->assertDontSeeHtml('aria-label="ZIP code"');

    // The wizard draws the same partial one heading level down: its step
    // title is already an h3.
    Livewire::test(AddEmployeeDialog::class)
        ->call('openDialog')
        ->set('step', 1)
        ->assertSeeHtml('<h4 class="text-sm font-semibold">')
        ->assertDontSeeHtml('<h3 class="text-sm font-semibold">')
        ->assertSeeHtml('aria-label="Residential street"')
        ->assertDontSeeHtml('aria-label="Street"');
});
