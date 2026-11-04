<?php

declare(strict_types=1);

use App\Livewire\Edtr\PunchStation;
use App\Livewire\Portal\MyPunches;
use App\Livewire\Portal\PortalPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\LeaveCredit;
use App\Models\LeaveTravelRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Payslip;
use App\Models\PayslipDeduction;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Models\WorkSite;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Portal module: /portal gates on the PORTAL grant; every scoped list on
 * the page is EMPTY for an employee-less actor (never IS NULL rows, never an
 * error) and stays the caller's OWN even for an all_data holder; filing mints
 * the LR code server-side, audits and notifies the Leave Management holders;
 * withdrawing is self-only; the payslip dialog opens own payslips only; the
 * Leave & travel tab's credits card and the hint under the filing form are
 * the caller's OWN rows for the current Manila year, read on that tab only,
 * and filing requires enough credits for the selected type and start year.
 */

/**
 * Helpers are portal-prefixed: Pest loads every test file into one process.
 *
 * @param  list<string>  $modules  ModuleCode values to grant
 */
function portalRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Portal '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/** @param  list<string>  $modules  ModuleCode values to grant */
function portalActor(array $modules, bool $allData = false, ?string $employeeId = null): User
{
    $user = User::factory()->create([
        'role_id' => portalRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);

    return $user;
}

function portalEmployee(string $code, string $first, string $last): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => $code,
        'first_name' => $first,
        'last_name' => $last,
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/** @param  array<string, string|bool>  $overrides  leave_travel_requests columns that replace the defaults below */
function portalRequest(EmployeeProfile $employee, string $code, array $overrides = []): LeaveTravelRequest
{
    $request = new LeaveTravelRequest;
    $request->forceFill(array_merge([
        'code' => $code,
        'employee_id' => $employee->getKey(),
        'request_type' => 'SICK',
        'day_type' => 'WHOLE_DAY',
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-11',
        'reason' => 'Flu',
        'status' => 'PENDING',
    ], $overrides))->save();

    return $request;
}

function portalPayslip(EmployeeProfile $employee, string $start, string $end, float $net): Payslip
{
    $payslip = new Payslip;
    $payslip->forceFill([
        'employee_id' => $employee->getKey(),
        'period_start' => $start,
        'period_end' => $end,
        'gross_pay' => $net,
        'total_deductions' => 0,
        'net_pay' => $net,
    ])->save();

    return $payslip;
}

/** A Leave Settings row, the source of the request options and the credit columns. */
function portalCreditType(string $code, string $name): LeaveType
{
    $type = new LeaveType;
    $type->forceFill(['code' => $code, 'name' => $name])->save();

    return $type;
}

/** One entered credit — the row Leave Management's Leave Credits tab writes. */
function portalCreditRow(EmployeeProfile $employee, string $requestType, float $credits, int $year = 2026): LeaveCredit
{
    $credit = new LeaveCredit;
    $credit->forceFill([
        'employee_id' => $employee->getKey(),
        'year' => $year,
        'request_type' => $requestType,
        'credits' => $credits,
    ])->save();

    return $credit;
}

/**
 * Runs $work with the clock frozen at 2026-09-26 09:00 Manila, so the panel's
 * year — LeaveCreditPolicy::yearOf(ManilaClock::todayKey()), exactly as the
 * page derives it — is 2026 whichever day the suite runs on. The clock is
 * released in `finally`, so a failing assertion never leaks it into the next
 * test.
 */
function portalCreditFrozen(Closure $work): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 09:00:00', ManilaClock::TZ));

    try {
        expect(LeaveCreditPolicy::yearOf(ManilaClock::todayKey()))->toBe(2026);
        $work();
    } finally {
        CarbonImmutable::setTestNow();
    }
}

/**
 * The markup of the credits card alone — everything from its data attribute
 * to the filing form that follows it — so an assertion about what the card
 * lists is not answered by the form's own list of every leave type.
 */
function portalCreditCard(string $html): string
{
    if (preg_match('/data-leave-credits>(.*?)<form /s', $html, $m) !== 1) {
        throw new RuntimeException('The leave credits card is not on the page.');
    }

    return $m[1];
}

/** The filing form's Submit button tag, attributes and all. */
function portalCreditSubmitButton(string $html): string
{
    if (preg_match('/<button[^>]*type="submit"[^>]*>/', $html, $m) !== 1) {
        throw new RuntimeException('The Submit button is not on the page.');
    }

    return $m[0];
}

/*
|--------------------------------------------------------------------------
| Route gating
|--------------------------------------------------------------------------
*/

it('sends a guest to the login page', function () {
    $this->get('/portal')->assertRedirect(route('login'));
});

it('turns away an actor whose role lacks the PORTAL module', function () {
    portalActor(['LEAVES']);

    $this->get('/portal')->assertRedirect();
});

it('renders for an actor holding PORTAL', function () {
    $employee = portalEmployee('EMP-101', 'Liza', 'Domingo');
    portalActor(['PORTAL'], employeeId: $employee->getKey());

    $this->get('/portal')
        ->assertOk()
        ->assertSee('My Portal');
});

it('offers no exports on any My Portal tab', function (bool $allData) {
    $employee = portalEmployee('EMP-NO-EXPORT', 'Self', 'Service');
    portalActor(['PORTAL', 'LEAVES', 'PAYROLL', 'EMPLOYEES', 'INVENTORY'], $allData, $employee->getKey());

    $page = Livewire::test(PortalPage::class)->assertDontSee('Export CSV');
    foreach (['requests', 'payslips', 'inventory', 'punch'] as $tab) {
        $page->set('tab', $tab)->assertSet('tab', $tab)->assertDontSee('Export CSV');
    }
    Livewire::test(MyPunches::class)->assertDontSee('Export CSV')
        ->call('punchRecorded')->assertDontSee('Export CSV');
})->with(['employee scope' => false, 'all-data scope' => true]);

/*
|--------------------------------------------------------------------------
| Row scoping — the employee-less-account leak lives or dies here
|--------------------------------------------------------------------------
*/

it('shows an employee-less actor the not-linked notice and empty lists, never an error', function () {
    $other = portalEmployee('EMP-102', 'Ana', 'Reyes');
    portalRequest($other, 'LR-001');
    portalPayslip($other, '2026-08-01', '2026-08-15', 7777.77);

    portalActor(['PORTAL']); // holds the module, no employee link

    $this->get('/portal')
        ->assertOk()
        ->assertSee('Ask HR to link your account.');

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->assertDontSee('Ana')
        ->set('tab', 'payslips')
        ->assertSee('No payslip has been posted for you yet.')
        ->assertDontSee('7,777.77');
});

it("keeps an all_data holder's portal lists to their own rows", function () {
    $mine = portalEmployee('EMP-103', 'Self', 'Service');
    $other = portalEmployee('EMP-104', 'Marco', 'Cruz');
    portalRequest($mine, 'LR-002', ['request_type' => 'SICK']);
    portalRequest($other, 'LR-003', ['request_type' => 'VACATION']);
    portalPayslip($mine, '2026-08-01', '2026-08-15', 1234.56);
    portalPayslip($other, '2026-08-01', '2026-08-15', 7777.77);

    portalActor(['PORTAL', 'LEAVES', 'PAYROLL'], allData: true, employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->assertSee('Sick Leave')
        ->assertDontSee('Vacation Leave')
        ->set('tab', 'payslips')
        ->assertSee('1,234.56')
        ->assertDontSee('7,777.77');
});

it('shows an employee-less actor zero punches in My punches', function () {
    portalActor(['PORTAL']);

    Livewire::test(MyPunches::class)
        ->assertSee('No punches recorded yet.');
});

/*
|--------------------------------------------------------------------------
| Filing — the port of POST /api/leave-requests, self-service branch
|--------------------------------------------------------------------------
*/

it('files a leave with a server-minted LR code, audit row and reviewer notification', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-001', 'name' => 'Sick Leave'])->save();

    $mine = portalEmployee('EMP-105', 'Liza', 'Domingo');
    portalCreditRow($mine, 'SICK_LEAVE', 1);
    $other = portalEmployee('EMP-106', 'Ana', 'Reyes');
    // A code already minted for somebody else: the next one must count past
    // it, not renumber from the caller's own (empty) list.
    portalRequest($other, 'LR-007', ['start_date' => '2026-01-05', 'end_date' => '2026-01-06']);

    // Somebody who reviews leave: active, all_data, holds LEAVES.
    $reviewer = User::factory()->create([
        'role_id' => portalRole(['LEAVES'], true)->getKey(),
        'employee_id' => null,
    ]);

    $actor = portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'SICK_LEAVE')
        ->set('dayType', 'HALF_DAY')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-02')
        ->set('reason', '  Flu  ')
        ->call('file')
        ->assertSet('type', '')
        ->assertSet('dayType', 'WHOLE_DAY')
        ->assertSet('reason', '');

    $created = LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->first();
    expect($created)->not->toBeNull();
    expect($created->code)->toBe('8');
    expect($created->request_type)->toBe('SICK_LEAVE');
    expect($created->day_type)->toBe('HALF_DAY');
    expect($created->status)->toBe('PENDING');
    expect($created->reason)->toBe('Flu');
    expect($created->start_date->format('Y-m-d'))->toBe('2026-09-01');
    expect($created->end_date->format('Y-m-d'))->toBe('2026-09-02');

    expect(
        AuditLog::query()
            ->where('action', 'REQUEST_FILED')
            ->where('entity', 'leave_travel_requests')
            ->where('entity_id', $created->getKey())
            ->where('details', "Request 8 filed 2026-09-01 \u{2192} 2026-09-02")
            ->exists()
    )->toBeTrue();

    $notification = Notification::query()->where('user_id', $reviewer->getKey())->first();
    expect($notification)->not->toBeNull();
    expect($notification->kind)->toBe('LEAVE_FILED');
    expect($notification->title)->toBe('Request 8 filed by '.$actor->name);
    expect($notification->body)->toBe('Sick Leave · 2026-09-01');
    expect($notification->href)->toBe('/leaves');

    // The actor's own filing is not news to them.
    expect(Notification::query()->where('user_id', $actor->getKey())->exists())->toBeFalse();
});

it('refuses dates that overlap an existing pending or approved request', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-002', 'name' => 'Vacation Leave'])->save();

    $mine = portalEmployee('EMP-107', 'Over', 'Lap');
    portalRequest($mine, 'LR-001', ['start_date' => '2026-09-01', 'end_date' => '2026-09-03']);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'VACATION')
        ->set('start', '2026-09-02')
        ->set('end', '2026-09-02')
        ->call('file');

    expect(LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->count())->toBe(1);
});

it('refuses a request type Leave Settings does not hold', function () {
    $mine = portalEmployee('EMP-108', 'No', 'Type');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'MADE_UP_TYPE')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-01')
        ->call('file');

    expect(LeaveTravelRequest::query()->count())->toBe(0);
});

it('refuses filing for an employee-less account', function () {
    $type = new LeaveType;
    $type->forceFill(['code' => 'LT-003', 'name' => 'Sick Leave'])->save();

    portalActor(['PORTAL']);

    Livewire::test(PortalPage::class)
        ->set('type', 'SICK')
        ->set('start', '2026-09-01')
        ->set('end', '2026-09-01')
        ->call('file');

    expect(LeaveTravelRequest::query()->count())->toBe(0);
});

it('files official business without a Leave Settings row behind it', function () {
    $mine = portalEmployee('EMP-109', 'Field', 'Work');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->set('type', 'BUSINESS_TRAVEL')
        ->set('start', '2026-09-04')
        ->set('end', '2026-09-04')
        ->call('file');

    $created = LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->first();
    expect($created)->not->toBeNull();
    expect($created->request_type)->toBe('BUSINESS_TRAVEL');
    expect($created->code)->toBe('1');
});

/*
|--------------------------------------------------------------------------
| Withdrawing — the port of POST /api/leave-requests/[id]/withdraw
|--------------------------------------------------------------------------
*/

it('withdraws the caller\'s own pending request, with the audit row', function () {
    $mine = portalEmployee('EMP-110', 'With', 'Draw');
    $request = portalRequest($mine, 'LR-004');

    $actor = portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('WITHDRAWN');
    expect($fresh->is_paid)->toBeNull();
    expect($fresh->reviewed_by)->toBeNull();
    expect($fresh->reviewed_at)->toBeNull();

    expect(
        AuditLog::query()
            ->where('action', 'REQUEST_WITHDRAWN')
            ->where('entity', 'leave_travel_requests')
            ->where('entity_id', $request->getKey())
            ->where('details', 'Request LR-004 withdrawn by '.$actor->name)
            ->exists()
    )->toBeTrue();
});

it("refuses to withdraw somebody else's request", function () {
    $mine = portalEmployee('EMP-111', 'Self', 'Only');
    $other = portalEmployee('EMP-112', 'Not', 'Mine');
    $request = portalRequest($other, 'LR-005');

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    expect($request->fresh()->status)->toBe('PENDING');
});

it('refuses to withdraw a request that is no longer pending', function () {
    $mine = portalEmployee('EMP-113', 'Al', 'Ready');
    $request = portalRequest($mine, 'LR-006', ['status' => 'APPROVED', 'is_paid' => true]);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)->call('withdraw', $request->getKey());

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->is_paid)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The payslip dialog
|--------------------------------------------------------------------------
*/

it('opens an own payslip and refuses another employee\'s', function () {
    $mine = portalEmployee('EMP-114', 'Pay', 'Slip');
    $other = portalEmployee('EMP-115', 'Not', 'Yours');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    $foreign = portalPayslip($other, '2026-08-01', '2026-08-15', 9000.00);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    Livewire::test(PortalPage::class)
        ->call('viewPayslip', $foreign->getKey())
        ->assertSet('viewingId', null)
        ->assertSet('payslipOpen', false)
        ->call('viewPayslip', $own->getKey())
        ->assertSet('viewingId', $own->getKey())
        ->assertSet('payslipOpen', true);
});

it('opens a payslip from its row, by pointer or keyboard, with no separate view button', function () {
    $mine = portalEmployee('EMP-ROW', 'Row', 'Reader');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.Livewire::test(PortalPage::class)->set('tab', 'payslips')->html());
    $path = new DOMXPath($document);
    $row = '//tr[@*[name()="wire:key"]="payslip-'.$own->getKey().'"]';

    expect($path->evaluate('string('.$row.'/@*[name()="wire:click"])'))->toBe("viewPayslip('".$own->getKey()."')")
        ->and($path->evaluate('string('.$row.'/@tabindex)'))->toBe('0')
        ->and($path->evaluate('string('.$row.'/@aria-label)'))->toBe('View payslip for Aug 01, 2026 to Aug 15, 2026')
        // Enter and Space on the focused row do what the click does, for the same payslip;
        // `.prevent` keeps Space from scrolling the page instead.
        ->and($path->evaluate('string('.$row.'/@*[name()="x-on:keydown.enter.prevent"])'))->toBe("\$wire.viewPayslip('".$own->getKey()."')")
        ->and($path->evaluate('string('.$row.'/@*[name()="x-on:keydown.space.prevent"])'))->toBe("\$wire.viewPayslip('".$own->getKey()."')")
        ->and($path->evaluate('count('.$row.'/@*[starts-with(name(), "x-on:keydown.")])'))->toBe(2.0)
        // THE ROW IS THE ONLY CONTROL: no button, and no empty column left behind for one.
        ->and($path->evaluate('count('.$row.'//button)'))->toBe(0.0)
        ->and($path->evaluate('count('.$row.'/td)'))->toBe(4.0)
        ->and($path->evaluate('count('.$row.'/ancestor::table/thead//th)'))->toBe(4.0);
});

it('opens the payslip as a bare sheet with nothing round it but its two controls', function () {
    $mine = portalEmployee('EMP-BARE', 'Bare', 'Sheet');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.Livewire::test(PortalPage::class)->set('tab', 'payslips')->call('viewPayslip', $own->getKey())->html());
    $path = new DOMXPath($document);
    $dialog = '//dialog[.//article[contains(@class, "hris-payslip")]]';
    $has = static fn (string $class): string => '[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]';

    // NO PANEL: Flux's bare variant gives the dialog no background, padding, shadow or
    // ring of its own, so nothing frames the sheet.
    expect($path->evaluate('count('.$dialog.$has('bg-transparent').')'))->toBe(1.0)
        ->and($path->evaluate('count('.$dialog.$has('bg-white').')'))->toBe(0.0)
        ->and($path->evaluate('count('.$dialog.$has('p-6').')'))->toBe(0.0)
        ->and($path->evaluate('count('.$dialog.$has('shadow-lg').')'))->toBe(0.0)
        ->and($path->evaluate('count('.$dialog.$has('ring').')'))->toBe(0.0)
        // Inside it: the two controls, then the sheet. No heading and no period line of
        // the dialog's own — the payslip names itself and prints its period its own way.
        ->and($path->evaluate('count('.$dialog.'/*)'))->toBe(2.0)
        ->and($path->evaluate('count('.$dialog.'//article)'))->toBe(1.0)
        ->and($path->evaluate('count('.$dialog.'//*[@data-flux-heading or @data-flux-text])'))->toBe(0.0)
        ->and($path->evaluate('normalize-space(('.$dialog.'//button)[1])'))->toBe('Print')
        ->and($path->evaluate('normalize-space(('.$dialog.'//button)[2])'))->toBe('Close')
        ->and($path->evaluate('count('.$dialog.'//button)'))->toBe(2.0)
        ->and($path->evaluate('string('.$dialog.')'))->toContain('01 Aug 2026')
        ->not->toContain('Aug 01, 2026');
});

it('aligns each amount heading over its right-aligned amounts', function () {
    $mine = portalEmployee('EMP-ALIGN', 'Aligned', 'Columns');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.Livewire::test(PortalPage::class)->set('tab', 'payslips')->html());
    $path = new DOMXPath($document);
    $table = '//tr[@*[name()="wire:key"]="payslip-'.$own->getKey().'"]/ancestor::table';
    $has = static fn (string $class): string => '[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]';

    // hris-table's own `thead th { text-align: left }` outranks a plain text-right,
    // so the headings carry the important form; the amounts need only the plain one.
    expect($path->evaluate('count('.$table.'/thead//th'.$has('!text-right').')'))->toBe(3.0)
        ->and($path->evaluate('count('.$table.'/thead//th'.$has('text-right').')'))->toBe(0.0)
        ->and($path->evaluate('count('.$table.'/tbody/tr[1]/td'.$has('text-right').')'))->toBe(3.0);
});

it('shows the payslip alone in My Portal, without the additional payroll details', function (bool $allData) {
    $mine = portalEmployee('EMP-DETAILS', 'Only', 'Payslip');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 1500.00);
    $own->forceFill(['sss_er' => 1515.15, 'philhealth_er' => 375.25, 'pagibig_er' => 100.35])->save();
    portalActor($allData ? ['PORTAL', 'PAYROLL'] : ['PORTAL'], $allData, $mine->getKey());

    // The same for someone who also runs payroll: My Portal is their own copy.
    Livewire::test(PortalPage::class)->set('tab', 'payslips')->call('viewPayslip', $own->getKey())
        ->assertSet('payslipOpen', true)
        ->assertSee('Net pay')
        ->assertDontSee('Additional payroll details')
        ->assertDontSee('Employer contributions')
        ->assertDontSee('1,515.15')
        ->assertDontSeeHtml('hris-payslip-details')
        ->assertDontSeeHtml('data-employer-shares');
})->with(['portal employee' => false, 'payroll administrator' => true]);

it('shows only own posted deduction names once alongside legacy deductions', function (bool $allData) {
    $mine = portalEmployee('PORTAL-DEDUCT-MINE', 'Own', 'Deductions');
    $other = portalEmployee('PORTAL-DEDUCT-OTHER', 'Other', 'Deductions');
    $own = portalPayslip($mine, '2026-08-01', '2026-08-15', 800);
    $own->forceFill([
        'gross_pay' => 1000, 'other_earnings' => 15, 'sss' => 100, 'other_deductions' => 25,
        'custom_deductions' => 75, 'total_deductions' => 200,
    ])->save();
    $legacy = portalPayslip($mine, '2026-07-01', '2026-07-15', 490);
    $legacy->forceFill(['gross_pay' => 500, 'other_deductions' => 10, 'total_deductions' => 10])->save();
    $foreign = portalPayslip($other, '2026-08-01', '2026-08-15', 900);
    foreach ([
        [$own, 0, 'UNIFORM', 'Uniform installment', 50],
        // A catalog name may match a statutory label; both rows must survive.
        [$own, 1, 'SSS_REPAYMENT', 'SSS', 25],
        [$foreign, 0, 'PRIVATE', 'Foreign private deduction', 100],
    ] as [$payslip, $position, $code, $name, $amount]) {
        $line = new PayslipDeduction;
        $line->forceFill([
            'payslip_id' => $payslip->getKey(), 'position' => $position,
            'deduction_code' => $code, 'name' => $name, 'amount' => $amount,
        ])->save();
    }

    portalActor($allData ? ['PORTAL', 'PAYROLL'] : ['PORTAL'], $allData, $mine->getKey());

    $page = Livewire::test(PortalPage::class)
        ->set('tab', 'payslips')
        ->call('viewPayslip', $foreign->getKey())
        ->assertSet('viewingId', null)
        ->assertDontSee('Foreign private deduction')
        ->call('viewPayslip', $own->getKey())
        ->assertSee('Uniform installment')
        ->assertSee('200.00')
        ->assertDontSee('Foreign private deduction');
    // The Payroll Deductions list's order: the posted typed lines in their
    // code order (SSS_REPAYMENT before UNIFORM), then the one free-form Other
    // line, then the DTR-priced lines (0 here, so filtered).
    $page->assertViewHas('deductions', [
        ['label' => 'SSS', 'value' => 100.0, 'hint' => null],
        ['label' => 'SSS', 'value' => 25.0, 'hint' => null],
        ['label' => 'Uniform installment', 'value' => 50.0, 'hint' => null],
        ['label' => 'Other deductions', 'value' => 25.0, 'hint' => null],
    ]);
    $page->assertViewHas('earnings', [['label' => 'Other earnings', 'value' => 15.0, 'hint' => null]]);
    $page->call('viewPayslip', $legacy->getKey())
        ->assertDontSee('Uniform installment')
        ->assertDontSee('Foreign private deduction');
    $page->assertViewHas('deductions', [['label' => 'Other deductions', 'value' => 10.0, 'hint' => null]]);
})->with(['portal employee' => false, 'payroll administrator' => true]);

it('honours the ?tab= deep link for requests and payslips only', function () {
    $mine = portalEmployee('EMP-116', 'Deep', 'Link');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    // The facade and the manager are one singleton, so the query params set
    // here are the ones the next test() call mounts with.
    Livewire::withQueryParams(['tab' => 'payslips']);
    Livewire::test(PortalPage::class)
        ->assertSet('tab', 'payslips');

    Livewire::withQueryParams(['tab' => 'nonsense']);
    Livewire::test(PortalPage::class)
        ->assertSet('tab', 'punch');
});

/*
|--------------------------------------------------------------------------
| The punch station on the portal
|--------------------------------------------------------------------------
*/

it('records a self-service punch with the browser fix and its accuracy', function () {
    $site = WorkSite::query()->create([
        'name' => 'Portal site '.Str::random(4),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'radius_meters' => 150,
        'geofence_exempt' => true,
    ]);
    $mine = portalEmployee('PORTAL-PUNCH', 'Liza', 'Domingo');
    $mine->forceFill(['work_site_id' => $site->getKey()])->save();
    portalActor(['PORTAL'], employeeId: (string) $mine->getKey());

    Livewire::test(PunchStation::class, [
        'lockedEmployeeId' => (string) $mine->getKey(),
        'selfService' => true,
        'showClock' => false,
    ])->call('punch', 'TIME_IN', 14.5995, 120.9842, 8.4);

    $log = AttendanceLog::query()->where('employee_id', $mine->getKey())->sole();
    expect($log->source)->toBe('WEB_GPS')
        ->and((float) $log->latitude)->toBe(14.5995)
        ->and((float) $log->longitude)->toBe(120.9842)
        ->and((float) $log->accuracy_meters)->toBe(8.4);
});

it('shows no count pill on My punches', function () {
    portalActor(['PORTAL']);

    expect(Livewire::test(MyPunches::class)->html())->not->toContain('pill pill-muted num');
});

/*
|--------------------------------------------------------------------------
| Leave credits on the Leave & travel tab — own rows, current year, a hint
|--------------------------------------------------------------------------
*/

it("keeps an all_data holder's leave credits card to their own rows", function () {
    portalCreditType('LT-101', 'Vacation Leave');
    portalCreditType('LT-102', 'Emergency Leave');

    $mine = portalEmployee('EMP-201', 'Own', 'Credits');
    $other = portalEmployee('EMP-202', 'Marco', 'Cruz');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);
    portalCreditRow($other, 'EMERGENCY_LEAVE', 42.5);
    // Two approved with-pay days of mine are USED; the other employee's three
    // must not be charged to me even though all_data + LEAVES can read them.
    portalRequest($mine, 'LR-201', ['request_type' => 'VACATION_LEAVE', 'status' => 'APPROVED', 'is_paid' => true]);
    portalRequest($other, 'LR-202', ['request_type' => 'EMERGENCY_LEAVE', 'status' => 'APPROVED', 'is_paid' => true, 'start_date' => '2026-08-03', 'end_date' => '2026-08-05']);

    portalActor(['PORTAL', 'LEAVES'], allData: true, employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->assertSee('Leave credits · 2026')
            ->assertDontSee('42.5')
            ->assertDontSee('39.5');

        $card = portalCreditCard($page->html());

        expect($card)->toContain('Vacation Leave')
            ->and($card)->toContain('<td class="num text-right">15</td>')
            ->and($card)->toContain('<td class="num text-right">2</td>')
            ->and($card)->toContain('>13</span>')
            ->and($card)->not->toContain('Emergency Leave');
    });
});

it('shows an employee-less actor no credits card, only the link notice', function () {
    portalCreditType('LT-103', 'Vacation Leave');
    $other = portalEmployee('EMP-203', 'Ana', 'Reyes');
    portalCreditRow($other, 'VACATION_LEAVE', 42.5);

    portalActor(['PORTAL']); // holds the module, no employee link

    portalCreditFrozen(function (): void {
        Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->assertSee('Ask HR to link your account.')
            ->assertDontSeeHtml('data-leave-credits')
            ->assertDontSee('Leave credits · 2026')
            ->assertDontSee('42.5')
            ->assertDontSeeHtml('data-credit-hint');
    });
});

it('tells a linked employee with nothing entered that no credits exist for the year', function () {
    portalCreditType('LT-104', 'Vacation Leave');
    $mine = portalEmployee('EMP-204', 'No', 'Credits');
    $other = portalEmployee('EMP-205', 'Ana', 'Reyes');
    portalCreditRow($other, 'VACATION_LEAVE', 42.5);
    // A credit of mine for ANOTHER year is not this year's.
    portalCreditRow($mine, 'VACATION_LEAVE', 77.5, 2025);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE')
            ->assertSeeHtml('data-leave-credits')
            ->assertSee('No leave credits have been entered for 2026 yet.')
            ->assertDontSee('42.5')
            ->assertDontSee('77.5')
            ->assertDontSeeHtml('data-credit-hint');

        // The empty state stands alone: no table of somebody else's rows, or
        // of my other year's, behind it.
        expect(portalCreditCard($page->html()))->not->toContain('<table');
    });
});

it('renders the punch station without the credits card or a credits read', function () {
    portalCreditType('LT-105', 'Vacation Leave');
    $mine = portalEmployee('EMP-206', 'Punch', 'First');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            Livewire::test(PortalPage::class)
                ->assertSet('tab', 'punch')
                ->assertDontSee('Leave credits ·')
                ->assertDontSeeHtml('data-leave-credits');

            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }

        // The table lands with the files before the cron migrate runs: the
        // punch station must keep working while /portal?tab=requests waits.
        expect($queries->filter(fn (string $q): bool => str_contains($q, 'leave_credits')))->toBeEmpty();
    });
});

it('disables Submit and refuses filing when the draft exceeds the selected leave credits', function () {
    portalCreditType('LT-106', 'Vacation Leave');
    $mine = portalEmployee('EMP-207', 'One', 'Left');
    portalCreditRow($mine, 'VACATION_LEAVE', 1);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function () use ($mine): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE')
            ->set('dayType', 'WHOLE_DAY')
            ->set('start', '2026-09-28')
            ->set('end', '2026-09-29')
            ->assertSeeHtml('data-credit-error')
            ->assertSee('This request needs 2 days, but your available balance for this leave type in 2026 is 1 day.');

        expect(portalCreditSubmitButton($page->html()))->toMatch('/\sdisabled(?:=|\s|>)/');

        // Calling the action directly must not bypass the disabled button.
        $page->call('file');

        expect(LeaveTravelRequest::query()->where('employee_id', $mine->getKey())->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    });
});

it('quotes the covered days and the balance plainly when the credits cover the draft', function () {
    portalCreditType('LT-107', 'Vacation Leave');
    $mine = portalEmployee('EMP-208', 'Plenty', 'Left');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE')
            ->set('dayType', 'WHOLE_DAY')
            ->set('start', '2026-09-28')
            ->set('end', '2026-09-29')
            ->assertSee('This request needs')
            ->assertSeeHtml('class="num">2 days</span>')
            ->assertSee('Vacation Leave credits available.')
            ->assertDontSee('pill-caution');
    });
});

it('charges a half-day draft 0.5 per covered day in the hint', function () {
    portalCreditType('LT-108', 'Vacation Leave');
    $mine = portalEmployee('EMP-209', 'Half', 'Day');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE')
            ->set('dayType', 'HALF_DAY')
            ->set('start', '2026-09-28')
            ->set('end', '2026-09-28')
            ->assertSee('This request needs')
            // dayCount(0.5) is "0.5"; only exactly one day reads "day".
            ->assertSeeHtml('class="num">0.5 days</span>')
            ->assertDontSee('pill-caution');
    });
});

it('says which year a draft outside the panel year would be charged to', function () {
    portalCreditType('LT-109', 'Vacation Leave');
    $mine = portalEmployee('EMP-210', 'Next', 'Year');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE')
            ->set('start', '2027-01-05')
            ->set('end', '2027-01-05')
            ->assertSeeHtml('data-credit-error')
            ->assertSee('You have no available leave credits for the selected leave type in 2027.')
            ->assertSee('This request falls in 2027; the credits shown are for 2026.')
            ->assertDontSee('This request needs');
    });
});

it('shows a refusal for a type with no credit entered and clears it when the selection has credits', function () {
    portalCreditType('LT-110', 'Vacation Leave');
    portalCreditType('LT-111', 'Sick Leave');
    $mine = portalEmployee('EMP-211', 'Sick', 'Draft');
    portalCreditRow($mine, 'VACATION_LEAVE', 15);

    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'SICK_LEAVE')
            ->set('start', '2026-09-28')
            ->set('end', '2026-09-29')
            ->assertSeeHtml('data-leave-credits')
            ->assertSee('Vacation Leave')
            ->assertSeeHtml('data-credit-error');
        $page->assertViewHas('submitDisabled', true);
        $page->set('type', 'VACATION_LEAVE')
            ->assertDontSeeHtml('data-credit-error')
            ->assertSeeHtml('data-credit-hint');
        $page->assertViewHas('submitDisabled', false);
    });
});

it('disables filing when the selected leave type has no remaining credits', function (string $name, string $token, ?float $credits) {
    portalCreditType('LT-112', $name);
    $mine = portalEmployee('EMP-212', 'No', 'Balance');
    if ($credits !== null) {
        portalCreditRow($mine, $token, $credits);
    }
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function () use ($token): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', $token)
            ->assertSee('You have no available leave credits for the selected leave type in 2026.');
        $page->assertViewHas('submitDisabled', true);
        expect(portalCreditSubmitButton($page->html()))->toMatch('/\sdisabled(?:=|\s|>)/');

        $page->call('file');
        expect(LeaveTravelRequest::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    });
})->with([
    'missing credits' => ['Vacation Leave', 'VACATION_LEAVE', null],
    'zero credits' => ['Vacation Leave', 'VACATION_LEAVE', 0.0],
    'Leave Without Pay' => ['Leave Without Pay', 'LEAVE_WITHOUT_PAY', null],
]);

it('rechecks credits when an enabled form is submitted after its balance changed', function () {
    portalCreditType('LT-113', 'Vacation Leave');
    $mine = portalEmployee('EMP-213', 'Stale', 'Form');
    $credit = portalCreditRow($mine, 'VACATION_LEAVE', 1);
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function () use ($credit): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE');
        $page->assertViewHas('submitDisabled', false);

        $credit->delete();
        $page->call('file')
            ->assertSet('type', 'VACATION_LEAVE')
            ->assertSee('You have no available leave credits');
        $page->assertViewHas('submitDisabled', true);

        expect(LeaveTravelRequest::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'REQUEST_FILED')->count())->toBe(0)
            ->and(Notification::query()->count())->toBe(0);
    });
});

it('uses the request year balance and recalculates eligibility when dates and day type change', function () {
    portalCreditType('LT-114', 'Vacation Leave');
    $mine = portalEmployee('EMP-214', 'Year', 'Balance');
    portalCreditRow($mine, 'VACATION_LEAVE', 0);
    portalCreditRow($mine, 'VACATION_LEAVE', 0.5, 2027);
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    portalCreditFrozen(function (): void {
        $page = Livewire::test(PortalPage::class)
            ->set('tab', 'requests')
            ->set('type', 'VACATION_LEAVE');
        $page->assertViewHas('submitDisabled', true);
        $page->set('end', '2027-01-05')
            ->set('start', '2027-01-05')
            ->assertSee('your available balance for this leave type in 2027 is 0.5 days.');
        $page->assertViewHas('submitDisabled', true);
        $page->set('dayType', 'HALF_DAY')
            ->assertSee('This request falls in 2027; the credits shown are for 2026.');
        $page->assertViewHas('submitDisabled', false);
        $page->call('file')
            ->assertSet('type', '');

        expect(LeaveTravelRequest::query()->sole()->day_type)->toBe('HALF_DAY');
    });
});

it('keeps official business enabled when no leave credits exist', function () {
    $mine = portalEmployee('EMP-215', 'Business', 'Travel');
    portalActor(['PORTAL'], employeeId: $mine->getKey());

    $page = Livewire::test(PortalPage::class)
        ->set('tab', 'requests')
        ->set('type', 'BUSINESS_TRAVEL')
        ->assertDontSeeHtml('data-credit-error');
    $page->assertViewHas('submitDisabled', false);
});
