<?php

declare(strict_types=1);

use App\Domain\Enums\ModuleCode;
use App\Livewire\Employees\Show;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Workforce\Application\Commands\UpdateEmployee;
use App\Modules\Workforce\Application\EmployeeFields;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The record's Declarations tab is one card: no visible heading, the line
 * "Answered Yes or No; details are kept only for a Yes.", then the twelve
 * questions worded as the Personal Data Sheet words them, with no item or
 * part numbers, each read as "Not answered" (never as No), "No", or "Yes"
 * with one line per detail given. Editing asks each question with a
 * Not answered / Yes / No select and its details only while Yes is chosen;
 * saving any other answer clears them. Everyone who can open the record
 * reads the answers; managers edit other records and employees propose own corrections.
 */

/** @param list<string> $modules */
function pdsdeclpageRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Decl page '.implode('-', $modules).' '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        RoleModuleGrant::query()->create(['role_id' => $role->id, 'module' => $module]);
    }

    return $role;
}

/** Signs in a viewer holding $role, linked to $employee when given. */
function pdsdeclpageViewer(Role $role, ?EmployeeProfile $employee = null): User
{
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id, 'employee_id' => $employee?->id])->save();

    test()->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    return $user;
}

function pdsdeclpageHr(): User
{
    return pdsdeclpageViewer(pdsdeclpageRole([ModuleCode::EMPLOYEES], true));
}

function pdsdeclpageEmployee(): EmployeeProfile
{
    $site = WorkSite::query()->create([
        'name' => 'Site '.Str::random(6), 'latitude' => 14.5995, 'longitude' => 120.9842, 'radius_meters' => 100, 'geofence_exempt' => true,
    ]);

    return EmployeeProfile::query()->create([
        'employee_code' => 'COP-'.Str::upper(Str::random(6)),
        'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'position' => 'Developer',
        'employment_status' => 'Regular', 'date_hired' => '2024-08-01', 'work_site_id' => $site->id,
    ]);
}

/**
 * Saves the declarations through the command as Employee Records all-data,
 * signing nobody in.
 *
 * @param  array<string, mixed>  $changes
 */
function pdsdeclpageAnswer(EmployeeProfile $employee, array $changes): void
{
    $role = pdsdeclpageRole([ModuleCode::EMPLOYEES], true);
    $user = User::factory()->create();
    $user->forceFill(['role_id' => $role->id])->save();

    $form = EmployeeFields::toForm(EmployeeProfile::query()->findOrFail($employee->id));
    app(UpdateEmployee::class)->handle(Access::forActor($user->refresh()), $employee->id, 'declarations', $changes + $form);
}

/** Sample answers: all No but one Yes and one Not answered. */
function pdsdeclpageMockAnswers(EmployeeProfile $employee): void
{
    $answers = array_fill_keys(['decl_34a', 'decl_34b', 'decl_35a', 'decl_35b', 'decl_36', 'decl_38a', 'decl_39', 'decl_40a', 'decl_40b', 'decl_40c'], 'no');

    pdsdeclpageAnswer($employee, $answers + [
        'decl_37' => 'yes', 'decl_37_details' => 'Finished contract, ABC Trading Corporation, 31 Dec 2023',
        'decl_38b' => '',
    ]);
}

/**
 * The Declarations card: from its wire:key to the page's first dialog (the
 * record's delete confirm), which follows the tabs.
 */
function pdsdeclpageCard(string $html): string
{
    $start = strpos($html, 'wire:key="section-declarations"');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '<ui-modal', (int) $start);

    return substr($html, (int) $start, $end === false ? null : $end - (int) $start);
}

/**
 * Markup without Livewire's morph markers and without the whitespace that
 * Blade's indentation leaves around tags. Text inside an element keeps its
 * own line breaks.
 */
function pdsdeclpageNormal(string $html): string
{
    $html = (string) preg_replace('/<!--\[if (END)?BLOCK\]><!\[endif\]-->/', '', $html);

    return trim((string) preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html));
}

/** The read view's <dl>, normalised, its escaped apostrophes read back as typed. */
function pdsdeclpageList(string $card): string
{
    $start = strpos($card, '<dl class="mt-4 grid gap-4 sm:grid-cols-2">');
    expect($start)->not->toBeFalse();
    $end = strpos($card, '</dl>', (int) $start);

    return str_replace('&#039;', "'", pdsdeclpageNormal(substr($card, (int) $start, (int) $end + 5 - (int) $start)));
}

/** Whether a Flux label reading $text is on the card. */
function pdsdeclpageLabelled(string $card, string $text): bool
{
    return preg_match('/<ui-label[^>]*>'.preg_quote($text, '/').'<\/ui-label>/', pdsdeclpageNormal($card)) === 1;
}

/** @return array<string, mixed> one details row as stored, untouched by any cast */
function pdsdeclpageRaw(EmployeeProfile $employee): array
{
    return (array) DB::table('employee_pds_details')->where('employee_id', $employee->id)->first();
}

/* |---| The read view |---| */

it('draws the twelve questions with the form wording, the two stems and no heading or numbers', function () {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageMockAnswers($employee);
    pdsdeclpageHr();

    $card = pdsdeclpageCard(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'declarations')->html());

    // The Declarations card's read view, verbatim (for the sample answers
    // saved above).
    $expected = <<<'HTML'
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
    <div class="min-w-0 sm:col-span-2">
        <dt class="break-words text-sm text-muted-foreground">Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,</dt>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">within the third degree?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">within the fourth degree (for Local Government Unit - Career Employees)?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Have you ever been found guilty of any administrative offense?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Have you been criminally charged before any court?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 sm:col-span-2">
        <dt class="break-words text-sm text-muted-foreground">Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 sm:col-span-2">
        <dt class="break-words text-sm text-muted-foreground">Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?</dt>
        <dd class="mt-1 break-words text-sm font-medium whitespace-pre-line">Yes
    Details: Finished contract, ABC Trading Corporation, 31 Dec 2023</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?</dt>
        <dd class="mt-1 break-words text-sm text-muted-foreground">Not answered</dd>
    </div>
    <div class="min-w-0 sm:col-span-2">
        <dt class="break-words text-sm text-muted-foreground">Have you acquired the status of an immigrant or permanent resident of another country?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 sm:col-span-2">
        <dt class="break-words text-sm text-muted-foreground">Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:</dt>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Are you a member of any indigenous group?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Are you a person with disability?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    <div class="min-w-0 ">
        <dt class="break-words text-sm text-muted-foreground">Are you a solo parent?</dt>
        <dd class="mt-1 break-words text-sm font-medium">No</dd>
    </div>
    </dl>
    HTML;

    expect(pdsdeclpageList($card))->toBe(pdsdeclpageNormal($expected))
        ->and($card)->toContain('<p class="text-sm text-muted-foreground">Answered Yes or No; details are kept only for a Yes.</p>')
        ->toContain('<h2 class="sr-only">Declarations</h2>')
        ->toContain('wire:click="startEdit(\'declarations\')"')
        ->not->toContain('<h2 class="text-base')
        ->not->toContain('Questions 34')
        ->not->toContain('References')
        ->not->toContain('Government-issued')
        ->not->toContain('Government Issued')
        ->and(strip_tags($card))->not->toMatch('/\b(3[4-9]|40)\s*[a-c]?\b|\bItem\b|\bQ\d/');
});

it('reads Not answered, No, and Yes with its details', function () {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageHr();

    // No details row at all: every question reads Not answered, never No.
    $blank = pdsdeclpageList(pdsdeclpageCard(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'declarations')->html()));

    expect(substr_count($blank, '<dd class="mt-1 break-words text-sm text-muted-foreground">Not answered</dd>'))->toBe(12)
        ->and($blank)->not->toContain('>No</dd>')
        ->not->toContain('>Yes');

    pdsdeclpageAnswer($employee, [
        'decl_34a' => 'no',
        'decl_35b' => 'yes', 'decl_35b_details' => 'Estafa, RTC Lucena', 'decl_35b_date_filed' => '2020-03-12', 'decl_35b_case_status' => 'Dismissed',
        'decl_36' => 'yes',
        'decl_39' => 'yes', 'decl_39_country' => 'Canada',
        'decl_40c' => 'yes', 'decl_40c_id_no' => 'SP-2024-118',
    ]);

    $list = pdsdeclpageList(pdsdeclpageCard(Livewire::test(Show::class, ['employee' => $employee->id])->set('tab', 'declarations')->html()));

    expect($list)
        ->toContain('within the third degree?</dt><dd class="mt-1 break-words text-sm font-medium">No</dd>')
        ->toContain('(for Local Government Unit - Career Employees)?</dt><dd class="mt-1 break-words text-sm text-muted-foreground">Not answered</dd>')
        ->toContain("criminally charged before any court?</dt><dd class=\"mt-1 break-words text-sm font-medium whitespace-pre-line\">Yes\nDetails: Estafa, RTC Lucena\nDate filed: 12 Mar 2020\nStatus of case/s: Dismissed</dd>")
        // A Yes with no details reads Yes alone, on one line.
        ->toContain('by any court or tribunal?</dt><dd class="mt-1 break-words text-sm font-medium">Yes</dd>')
        ->toContain("permanent resident of another country?</dt><dd class=\"mt-1 break-words text-sm font-medium whitespace-pre-line\">Yes\nCountry: Canada</dd>")
        ->toContain("Are you a solo parent?</dt><dd class=\"mt-1 break-words text-sm font-medium whitespace-pre-line\">Yes\nID No.: SP-2024-118</dd>")
        ->and(substr_count($list, '>Not answered</dd>'))->toBe(7);
});

/* |---| Editing |---| */

it('asks the details only while Yes is chosen and clears them when No is saved', function () {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->set('tab', 'declarations')
        ->call('startEdit', 'declarations')
        ->assertSet('editingSection', 'declarations');

    $card = pdsdeclpageCard($page->html());

    // Twelve selects, each named by its question, offering the three answers
    // in order; no detail is asked yet.
    expect(substr_count($card, 'wire:model.live="form.decl_'))->toBe(12)
        ->and(pdsdeclpageLabelled($card, 'within the third degree?'))->toBeTrue()
        ->and(pdsdeclpageLabelled($card, 'Are you a solo parent?'))->toBeTrue()
        ->and(pdsdeclpageNormal($card))
        ->toMatch('/<select[^>]*aria-label="[^"]*within the third degree\?"[^>]*wire:model\.live="form\.decl_34a"[^>]*><option\s+value=""[^>]*>Not answered<\/option><option\s+value="yes"[^>]*>Yes<\/option><option\s+value="no"[^>]*>No<\/option><\/select>/s')
        ->toContain('<p class="break-words text-sm text-muted-foreground sm:col-span-2">Are you related by consanguinity')
        ->toContain('<p class="break-words text-sm text-muted-foreground sm:col-span-2">Pursuant to: (a) Indigenous')
        ->and($card)->not->toContain('form.decl_35b_details')
        ->not->toContain('form.decl_35b_date_filed')
        ->not->toContain('form.decl_39_country')
        ->not->toContain('Status of case/s');

    // Yes on criminally charged asks its three details, and only its own.
    $card = pdsdeclpageCard($page->set('form.decl_35b', 'yes')->html());

    expect($card)->toContain('wire:model.blur="form.decl_35b_details"')
        ->toContain('form.decl_35b_date_filed')
        ->toContain('aria-label="'.mb_substr('Date filed — Have you been criminally charged before any court?', 0, 60).'"')
        ->toContain('wire:model.blur="form.decl_35b_case_status"')
        ->and(pdsdeclpageLabelled($card, 'Date filed'))->toBeTrue()
        ->and(pdsdeclpageLabelled($card, 'Status of case/s'))->toBeTrue()
        ->and($card)->not->toContain('form.decl_34a_details')
        ->not->toContain('form.decl_39_country');

    // Each other question's detail follows its own Yes.
    $card = pdsdeclpageCard($page->set('form.decl_39', 'yes')->set('form.decl_40a', 'yes')->set('form.decl_40b', 'yes')->set('form.decl_40c', 'yes')->html());

    expect($card)->toContain('wire:model.blur="form.decl_39_country"')
        ->toContain('wire:model.blur="form.decl_40a_specify"')
        ->toContain('wire:model.blur="form.decl_40b_id_no"')
        ->toContain('wire:model.blur="form.decl_40c_id_no"')
        ->and(pdsdeclpageLabelled($card, 'Country'))->toBeTrue()
        ->and(pdsdeclpageLabelled($card, 'Specify'))->toBeTrue()
        ->and(substr_count(pdsdeclpageNormal($card), '>ID No.</ui-label>'))->toBe(2);

    $page->set('form.decl_35b_details', 'Estafa, RTC Lucena')
        ->set('form.decl_35b_date_filed', '2020-03-12')
        ->set('form.decl_35b_case_status', 'Dismissed')
        ->set('form.decl_39', 'no')->set('form.decl_40a', '')->set('form.decl_40b', '')->set('form.decl_40c', '')
        ->call('saveSection', 'declarations')
        ->assertSet('editingSection', null)
        ->assertSet('formErrors', []);

    expect(pdsdeclpageRaw($employee))->toMatchArray([
        'decl_35b_details' => 'Estafa, RTC Lucena', 'decl_35b_case_status' => 'Dismissed', 'decl_39_country' => null,
    ])
        ->and((int) pdsdeclpageRaw($employee)['decl_35b'])->toBe(1)
        ->and((string) pdsdeclpageRaw($employee)['decl_35b_date_filed'])->toStartWith('2020-03-12')
        ->and(pdsdeclpageList(pdsdeclpageCard($page->html())))
        ->toContain("Yes\nDetails: Estafa, RTC Lucena\nDate filed: 12 Mar 2020\nStatus of case/s: Dismissed</dd>");

    // No hides the details again and the save clears them.
    $card = pdsdeclpageCard($page->call('startEdit', 'declarations')->set('form.decl_35b', 'no')->html());

    expect($card)->not->toContain('form.decl_35b_details')
        ->not->toContain('form.decl_35b_date_filed');

    $page->call('saveSection', 'declarations')->assertSet('editingSection', null);

    $raw = pdsdeclpageRaw($employee);

    expect($raw)->toMatchArray(['decl_35b_details' => null, 'decl_35b_date_filed' => null, 'decl_35b_case_status' => null])
        ->and($raw['decl_35b'])->not->toBeNull()
        ->and((int) $raw['decl_35b'])->toBe(0)
        ->and(pdsdeclpageList(pdsdeclpageCard($page->html())))
        ->toContain('criminally charged before any court?</dt><dd class="mt-1 break-words text-sm font-medium">No</dd>');
});

it('shows a refused answer or detail under its own control and saves nothing', function () {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageHr();
    $tomorrow = CarbonImmutable::parse(EmployeeFields::todayKey())->addDay()->format('Y-m-d');

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->call('startEdit', 'declarations')
        ->set('form.decl_34a', 'maybe')
        ->set('form.decl_35b', 'yes')
        ->set('form.decl_35b_date_filed', $tomorrow)
        ->call('saveSection', 'declarations')
        ->assertSet('editingSection', 'declarations')
        ->assertSet('formErrors.decl_35b_date_filed', 'Date filed cannot be in the future.');

    $card = pdsdeclpageNormal(pdsdeclpageCard($page->html()));

    expect($page->get('formErrors.decl_34a'))->toEndWith('must be one of the listed options.')
        ->and($card)->toMatch('/wire:model\.live="form\.decl_34a".*?<\/ui-field>(?:(?!wire:model).)*?<p class="text-xs text-danger">[^<]*must be one of the listed options\.<\/p>/s')
        ->toContain('<p class="text-xs text-danger">Date filed cannot be in the future.</p>')
        ->and(DB::table('employee_pds_details')->where('employee_id', $employee->id)->exists())->toBeFalse();
});

/* |---| Who reads and who saves |---| */

it('keeps foreign declarations read-only and submits own declaration corrections for approval', function (string $viewer) {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageAnswer($employee, ['decl_39' => 'yes', 'decl_39_country' => 'Canada']);
    $before = pdsdeclpageRaw($employee);
    pdsdeclpageViewer(
        $viewer === 'own' ? pdsdeclpageRole([ModuleCode::PROFILE], false) : pdsdeclpageRole([ModuleCode::PAYROLL], true),
        $viewer === 'own' ? $employee : null,
    );
    $page = Livewire::test(Show::class, ['employee' => $employee->id, 'own' => $viewer === 'own'])->set('tab', 'declarations');
    expect(pdsdeclpageList(pdsdeclpageCard($page->html())))->toContain("Yes\nCountry: Canada</dd>");
    $page->call('startEdit', 'declarations');
    if ($viewer === 'payroll') {
        $page->assertSet('editingSection', null)->set('form.decl_39', 'no')->call('saveSection', 'declarations')->assertForbidden();
    } else {
        $page->assertSet('editingSection', 'declarations')
            ->set('form.decl_39', 'no')
            ->set('form.decl_39_country', '')
            ->call('saveSection', 'declarations')
            ->assertDispatched('record-change-submitted');
    }
    expect(pdsdeclpageRaw($employee))->toBe($before);
})->with(['own', 'payroll']);

/* |---| Edit from any tab |---| */

it('moves to the Declarations tab when its Edit is pressed', function () {
    $employee = pdsdeclpageEmployee();
    pdsdeclpageHr();

    $page = Livewire::test(Show::class, ['employee' => $employee->id])
        ->assertSet('tab', 'profile')
        ->call('startEdit', 'declarations')
        ->assertSet('tab', 'declarations')
        ->assertSet('editingSection', 'declarations');

    $card = pdsdeclpageCard($page->html());

    expect(substr_count($card, 'wire:model.live="form.decl_'))->toBe(12)
        ->and($card)->toContain('wire:click="saveSection(\'declarations\')"')
        ->toContain('wire:click="cancelEditing"');

    // Leaving the tab drops the open edit.
    $page->set('tab', 'family')->assertSet('editingSection', null);
});
