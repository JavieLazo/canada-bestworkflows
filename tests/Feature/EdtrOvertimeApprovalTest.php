<?php

declare(strict_types=1);

use App\Livewire\Edtr\EdtrPage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\OvertimeApproval;
use App\Models\PayrollFormula;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Daily Time Record keeps computed OT on the table and approval details
 * in a separate review dialog, with pending days approvable inline.
 * Overtime is excluded until approved; no rejection is required to leave it
 * unpaid. Only an all-data EDTR holder sees decision controls, including on
 * their own record; each action re-resolves the actor, and the command
 * refuses whatever slips past.
 */

/** @param list<string> $modules */
function edtrotActor(array $modules, bool $allData = true, ?string $employeeId = null): User
{
    $role = new Role;
    $role->forceFill(['name' => 'EdtrOT '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    $user = User::factory()->create(['role_id' => $role->getKey(), 'employee_id' => $employeeId]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function edtrotEmployee(): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'EOT-'.random_int(10000, 99999), 'first_name' => 'Liza', 'last_name' => 'Domingo',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ])->save();

    $shift = new Shift;
    $shift->forceFill([
        'employee_id' => (string) $employee->getKey(), 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 5,
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60, 'requires_break_punch' => false,
    ])->save();

    return $employee;
}

/** A day worked 08:00 to $out, Manila; past 17:00 is overtime. */
function edtrotWorked(EmployeeProfile $employee, string $date, string $out = '19:30'): void
{
    foreach (['TIME_IN' => '08:00', 'TIME_OUT' => $out] as $type => $at) {
        $punch = new AttendanceLog;
        $punch->forceFill([
            'employee_id' => (string) $employee->getKey(), 'log_type' => $type, 'source' => 'MANUAL',
            'is_geofenced' => true, 'is_anomaly' => false,
            'logged_at' => ManilaClock::instant("{$date}T{$at}:00")->utc(),
        ])->save();
    }
}

/** The page on the DTR tab with the employee picked. */
function edtrotPage(EmployeeProfile $employee): mixed
{
    return Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey());
}

/** The open day's overtime panel, separate from the punch correction form. */
function edtrotPanel(string $html): string
{
    return Str::before(Str::after($html, 'data-overtime-decision'), '</dialog>');
}

beforeEach(function () {
    // Thursday evening; the cut-off on screen is Sep 1-15.
    $this->travelTo(new DateTimeImmutable('2026-09-10T18:00:00+08:00'));
});

it('keeps computed overtime approval on the OT row without a period bulk action', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotWorked($employee, '2026-09-09', '17:00');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee);
    $html = $page->html();
    $header = Str::before(Str::after($html, '<table class="hris-table">'), '</thead>');
    $row = Str::before(Str::after($html, 'wire:key="dtr-2026-09-08"'), '</tr>');
    $withoutOvertime = Str::before(Str::after($html, 'wire:key="dtr-2026-09-09"'), '</tr>');

    expect($header)->toContain('>OT</th>')->not->toContain('OT approved')
        ->and(substr_count($row, '<td'))->toBe(11)
        ->and($row)->toContain('2h 30m')->not->toContain('data-ot-status')
        ->toContain('wire:click="openOvertime(\'2026-09-08\')"')
        ->and($withoutOvertime)->not->toContain('openOvertime')
        ->and($html)->not->toContain('data-pending-overtime')
        ->not->toContain('overtime not approved')
        ->not->toContain('It is excluded from payroll unless approved. No rejection is needed.')
        ->not->toContain('Approve all')
        ->not->toContain('approvePendingOvertime')
        ->not->toContain('Reject all unreviewed')
        ->not->toContain('cannot be finalized')
        // Overtime remains on the row; the summary has only five cards.
        ->not->toContain('wire:key="stat-Overtime"')
        ->toContain('sm:grid-cols-3 lg:grid-cols-5')
        ->and(OvertimeApproval::query()->count())->toBe(0);

    $page->call('openOvertime', '2026-09-08');
    expect(edtrotPanel($page->html()))->toContain('data-ot-status="PENDING"')
        ->toContain('Not approved — excluded from payroll')
        ->not->toContain('No rejection is needed to leave it unpaid.')
        ->and($page->html())->toContain('Not approved')
        ->not->toContain('>Pending</span>');
});

it('approves a day from its dialog with the minutes the approver typed', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    $user = edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08');
    // The draft starts at the most that may be approved.
    expect($page->get('overtimeForm'))->toBe(['minutes' => '150', 'note' => '', 'seaport' => false, 'credited' => ''])
        ->and($page->html())->toContain('data-overtime-decision')->toContain('Not approved — excluded from payroll');

    $page->set('overtimeForm.minutes', '90')->set('overtimeForm.note', 'Client deadline')->call('approveOvertime');

    $row = OvertimeApproval::query()->sole();
    expect($row->approved_minutes)->toBe(90)
        ->and($row->note)->toBe('Client deadline')
        ->and($row->reviewed_by)->toBe($user->id)
        ->and($page->get('overtimeError'))->toBeNull()
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);

    $html = $page->html();
    expect(edtrotPanel($html))->toContain('data-ot-status="PARTIAL"')->toContain('Approved 1h 30m')
        ->and($html)->not->toContain('data-pending-overtime')->not->toContain('OT approved');
});

it('rejects a day from its dialog', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')->call('rejectOvertime');

    expect(OvertimeApproval::query()->sole()->decision)->toBe('REJECTED')
        ->and(edtrotPanel($page->html()))->toContain('data-ot-status="REJECTED"')->toContain('Rejected');
});

it('shows the refusal in the dialog and writes nothing for minutes over the ceiling', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->set('overtimeForm.minutes', '151')
        ->call('approveOvertime');

    expect($page->get('overtimeError'))->toBe('Approve between 1m and 2h 30m of overtime.')
        ->and($page->html())->toContain('Approve between 1m and 2h 30m of overtime.')
        ->and(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lets an attendance approver approve their own overtime through each DTR control', function (string $control) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-07', '18:00');
    edtrotWorked($employee, '2026-09-08');
    $actor = edtrotActor(['EDTR'], employeeId: $employee->id);
    $page = edtrotPage($employee);
    expect($page->html())->toContain('approveDayOvertime')->not->toContain('approvePendingOvertime');

    if ($control === 'dialog') {
        $page->call('openOvertime', '2026-09-08');
        expect($page->html())->toContain('data-overtime-decision')
            ->toContain('wire:click="approveOvertime"')
            ->not->toContain('another approver must approve overtime');
        $page->set('overtimeForm.minutes', '90')->call('approveOvertime');
    } else {
        $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);
    }

    $expectedMinutes = match ($control) {
        'dialog' => 90,
        'inline' => 150,
        default => throw new LogicException('Unknown overtime approval control'),
    };
    expect($page->get('overtimeError'))->toBeNull()
        ->and(OvertimeApproval::query()->count())->toBe(1)
        ->and(OvertimeApproval::query()->sum('approved_minutes'))->toEqual($expectedMinutes)
        ->and(OvertimeApproval::query()->pluck('reviewed_by')->unique()->values()->all())->toBe([$actor->id])
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);
})->with(['inline', 'dialog']);

it('shows a viewer without all-data no controls and keeps the dialog\'s day locked', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR'], allData: false, employeeId: (string) $employee->getKey());

    $page = Livewire::test(EdtrPage::class)->set('tab', 'dtr');
    // A self-only viewer sees computed overtime but no approval controls.
    expect($page->html())->not->toContain('data-pending-overtime')
        ->not->toContain('wire:click="approveOvertime"');

    $page->set('overtimeDate', '2026-09-08');
})->throws(Exception::class, 'Cannot update locked property: [overtimeDate]');

it('ignores the overtime actions from a viewer without all-data', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR'], allData: false, employeeId: (string) $employee->getKey());

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('openOvertime', '2026-09-08')
        ->assertSet('overtimeOpen', false)
        ->assertSet('overtimeDate', null)
        ->set('overtimeForm.minutes', '60')
        ->call('approveOvertime')
        ->call('rejectOvertime')
        ->call('rejectPendingOvertime');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('shows an outdated approval in its OT row only as far as the day now pays it', function () {
    $employee = edtrotEmployee();
    // Approved when the day ran to 19:30; its punches were corrected to 18:00 since.
    edtrotWorked($employee, '2026-09-08', '18:00');
    $row = new OvertimeApproval;
    $row->forceFill(['employee_id' => (string) $employee->getKey(), 'date' => '2026-09-08', 'decision' => 'APPROVED',
        'computed_minutes' => 150, 'approved_minutes' => 150])->save();
    edtrotActor(['EDTR']);

    $html = edtrotPage($employee)->call('openOvertime', '2026-09-08')->html();

    expect(edtrotPanel($html))->toContain('data-ot-status="OUTDATED"')
        // Payroll pays min(computed now, approved): one hour, not the stale 2h 30m.
        ->and($html)->toContain('>1h approved</div>')
        ->and($html)->not->toContain('2h 30m approved');
});

it('opens the overtime approval dialog directly with the day\'s evidence', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->assertSet('overtimeOpen', true)
        ->assertSet('overtimeDate', '2026-09-08')
        ->assertSet('correctionOpen', false)
        ->assertSet('correctingDate', null);
    $html = $page->html();
    $panel = edtrotPanel($html);

    expect($html)->toContain('Overtime approval')
        ->not->toContain('correction-tab-overtime')
        ->not->toContain('wire:submit="saveCorrection"')
        ->and($panel)->toContain('wire:click="approveOvertime"')
        ->toContain('wire:click="rejectOvertime"')
        ->toContain('Final Time Out')
        ->toContain('>7:30 PM<');
});

it('keeps punch correction separate and cannot approve from its open date', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openCorrection', '2026-09-08')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctingDate', '2026-09-08')
        ->assertSet('overtimeOpen', false)
        ->assertSet('overtimeDate', null);

    expect($page->html())->toContain('wire:submit="saveCorrection"')
        ->not->toContain('data-overtime-decision')
        ->not->toContain('correction-tab-overtime')
        ->not->toContain('wire:click="approveOvertime"');

    $page->set('overtimeForm.minutes', '60')->call('approveOvertime')->call('rejectOvertime');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(AttendanceLog::query()->count())->toBe(2);
});

it('opens only one of the overtime and punch dialogs at a time', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openCorrection', '2026-09-08')
        ->call('openOvertime', '2026-09-08')
        ->assertSet('correctionOpen', false)
        ->assertSet('correctingDate', null)
        ->assertSet('overtimeOpen', true)
        ->assertSet('overtimeDate', '2026-09-08');

    $page->set('overtimeForm.minutes', '151')->call('approveOvertime')
        ->call('openCorrection', '2026-09-08')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctingDate', '2026-09-08')
        ->assertSet('overtimeOpen', false)
        ->assertSet('overtimeDate', null)
        ->assertSet('overtimeError', null)
        ->assertSet('overtimeForm', ['minutes' => '', 'note' => '', 'seaport' => false, 'credited' => '']);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('clears a closed overtime draft and reloads each dates saved approval or ceiling', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotWorked($employee, '2026-09-09', '18:00');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->set('overtimeForm.minutes', '90')->set('overtimeForm.note', 'Client deadline')
        ->call('approveOvertime')
        ->set('overtimeForm.minutes', '151')->set('overtimeForm.note', 'Unsaved note')
        ->call('approveOvertime')
        ->assertSet('overtimeError', 'Approve between 1m and 2h 30m of overtime.')
        ->set('overtimeOpen', false)
        ->assertSet('overtimeDate', null)
        ->assertSet('overtimeError', null)
        ->assertSet('overtimeForm', ['minutes' => '', 'note' => '', 'seaport' => false, 'credited' => '']);

    $page->call('openOvertime', '2026-09-09')
        ->assertSet('overtimeDate', '2026-09-09')
        ->assertSet('overtimeForm', ['minutes' => '60', 'note' => '', 'seaport' => false, 'credited' => ''])
        ->set('overtimeOpen', false)
        ->call('openOvertime', '2026-09-08')
        ->assertSet('overtimeForm', ['minutes' => '90', 'note' => 'Client deadline', 'seaport' => false, 'credited' => '']);

    expect(OvertimeApproval::query()->sole()->approved_minutes)->toBe(90)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);
});

it('does not open overtime outside the displayed period or for a date with none', function (string $date) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotWorked($employee, '2026-09-09', '17:00');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', $date)
        ->assertSet('overtimeOpen', false)
        ->assertSet('overtimeDate', null)
        ->set('overtimeForm.minutes', '60')->call('approveOvertime');

    expect($page->html())->not->toContain('data-overtime-decision')
        ->and(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['2026-08-31', 'not-a-date', '2026-13-01', '2026-09-09', '2026-09-11']);

it('refuses an overtime action when all-data access was removed after opening', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    $actor = edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->assertSet('overtimeOpen', true);
    Role::query()->whereKey($actor->role_id)->update(['all_data' => false]);
    $this->actingAs($actor->refresh());
    app()->forgetInstance(Access::class);

    $page->set('overtimeForm.minutes', '60')->call('approveOvertime')->call('rejectOvertime');

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('cannot apply an overtime draft after the selected employee changes', function () {
    $employee = edtrotEmployee();
    $other = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotWorked($other, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->set('overtimeForm.minutes', '90')->set('overtimeForm.note', 'First employee only')
        ->call('employeePicked', 'dtr-employee', (string) $other->getKey())
        ->assertSet('dtrEmployee', (string) $other->getKey())
        ->assertSet('overtimeOpen', false)
        ->assertSet('overtimeDate', null)
        ->assertSet('overtimeForm', ['minutes' => '', 'note' => '', 'seaport' => false, 'credited' => ''])
        ->call('approveOvertime')->call('rejectOvertime');

    expect($page->html())->not->toContain('data-overtime-decision')
        ->and(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('approves pending overtime directly in its DTR row without opening either dialog', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    $actor = edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $beforePunches = AttendanceLog::query()->orderBy('id')->get()->toArray();
    $row = Str::before(Str::after($page->html(), 'wire:key="dtr-2026-09-08"'), '</tr>');
    expect($row)->toContain('approveDayOvertime')->toContain('2h 30m');

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150)
        ->assertSet('overtimeOpen', false)
        ->assertSet('correctionOpen', false);
    $decision = OvertimeApproval::query()->sole();
    expect($decision->approved_minutes)->toBe(150)
        ->and($decision->reviewed_by)->toBe($actor->id)
        ->and($page->get('overtimeDate'))->toBeNull()
        ->and($page->html())->toContain('>Approved</div>')->not->toContain('data-overtime-decision')
        ->and(Str::before(Str::after($page->html(), 'wire:key="dtr-2026-09-08"'), '</tr>'))->not->toContain('approveDayOvertime')
        ->and(AttendanceLog::query()->orderBy('id')->get()->toArray())->toBe($beforePunches)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);
});

it('does not overwrite a day decided after its inline approval button rendered', function (string $decision) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $saved = new OvertimeApproval;
    $saved->forceFill([
        'employee_id' => $employee->id, 'date' => '2026-09-08', 'decision' => $decision,
        'computed_minutes' => 150, 'approved_minutes' => $decision === 'APPROVED' ? 30 : 0,
        'note' => 'Another reviewer decided this day',
    ])->save();
    $before = $saved->fresh()->toArray();

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);

    expect($saved->fresh()->toArray())->toBe($before)
        ->and(OvertimeApproval::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['APPROVED', 'REJECTED']);

it('refuses forged inline dates outside the displayed period or after today', function (string $date) {
    $employee = edtrotEmployee();
    foreach (['2026-08-31', '2026-09-08', '2026-09-11'] as $worked) {
        edtrotWorked($employee, $worked);
    }
    edtrotActor(['EDTR']);

    edtrotPage($employee)->call('approveDayOvertime', $employee->id, $date, 150);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['not-a-date', '2026-09-31', '2026-08-31', '2026-09-11']);

it('refuses inline approval without attendance all-data access', function (string $who) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    match ($who) {
        'own scoped' => edtrotActor(['EDTR'], allData: false, employeeId: $employee->id),
        'foreign scoped' => edtrotActor(['EDTR'], allData: false, employeeId: edtrotEmployee()->id),
        'unlinked scoped' => edtrotActor(['EDTR'], allData: false),
        'no attendance grant' => edtrotActor(['PAYROLL']),
        default => throw new LogicException('Unknown overtime actor fixture'),
    };
    $page = edtrotPage($employee);
    expect($page->html())->not->toContain('wire:click="approveDayOvertime');
    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['own scoped', 'foreign scoped', 'unlinked scoped', 'no attendance grant']);

it('refuses inline approval outside the DTR tab or without a picked employee', function (string $state) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);
    $page = $state === 'punch tab'
        ? edtrotPage($employee)->set('tab', 'punch')
        : Livewire::test(EdtrPage::class)->set('tab', 'dtr');

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['punch tab', 'no employee']);

it('uses the displayed capped amount for inline approval and refuses a forged excess', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    PayrollFormula::create([
        'code' => 'F-EDTR-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => 'Display only',
        'params' => [['key' => 'working_hours', 'value' => 8], ['key' => 'percentage', 'value' => 1.25], ['key' => 'max_ot', 'value' => 1]],
    ]);
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 61);
    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 60);
    expect(OvertimeApproval::query()->sole()->approved_minutes)->toBe(60)
        ->and(OvertimeApproval::query()->sole()->computed_minutes)->toBe(150)
        ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);
});

it('never silently increases the displayed inline approval after punches change', function (string $out) {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    AttendanceLog::query()->where('log_type', 'TIME_OUT')->update([
        'logged_at' => ManilaClock::instant("2026-09-08T{$out}:00")->utc(),
    ]);

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);

    if ($out === '20:00') {
        expect(OvertimeApproval::query()->sole()->approved_minutes)->toBe(150)
            ->and(OvertimeApproval::query()->sole()->computed_minutes)->toBe(180)
            ->and(AuditLog::query()->where('action', 'DTR_OVERTIME_APPROVED')->count())->toBe(1);
    } else {
        expect(OvertimeApproval::query()->count())->toBe(0)
            ->and(AuditLog::query()->count())->toBe(0);
    }
})->with(['larger day' => '20:00', 'smaller day' => '18:00']);

it('omits inline approval for posted dates and refuses a forged click on them', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    (new Payslip)->forceFill([
        'employee_id' => $employee->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-15',
        'gross_pay' => 1000, 'total_deductions' => 0, 'net_pay' => 1000,
    ])->save();
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $row = Str::before(Str::after($page->html(), 'wire:key="dtr-2026-09-08"'), '</tr>');
    expect($row)->not->toContain('approveDayOvertime')
        ->and(RoleModuleGrant::query()->where('module', 'PAYROLL')->exists())->toBeFalse();

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(Payslip::query()->sole()->net_pay)->toBe(1000.0);
});

it('leaves the inline day unpaid when its required audit cannot be saved', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $punches = AttendanceLog::query()->orderBy('id')->get()->toArray();
    AuditLog::creating(static function (): void {
        throw new RuntimeException('Synthetic inline audit failure');
    });

    $page->call('approveDayOvertime', $employee->id, '2026-09-08', 150)
        ->assertSet('overtimeOpen', false)
        ->assertSet('correctionOpen', false);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and(AttendanceLog::query()->orderBy('id')->get()->toArray())->toBe($punches)
        ->and($page->html())->toContain('approveDayOvertime');
});

it('cannot approve a newly selected employee from the previous employees rendered row', function () {
    $employee = edtrotEmployee();
    $other = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotWorked($other, '2026-09-08');
    edtrotActor(['EDTR']);
    $page = edtrotPage($employee);
    $renderedEmployeeId = $page->get('dtrEmployee');
    $row = Str::before(Str::after($page->html(), 'wire:key="dtr-2026-09-08"'), '</tr>');
    expect($renderedEmployeeId)->toBe($employee->id)
        ->and($row)->toContain('approveDayOvertime')->toContain($employee->id);

    // The pick arrives first; a queued click still carries the first row's identity.
    $page->call('employeePicked', 'dtr-employee', $other->id)
        ->assertSet('dtrEmployee', $other->id)
        ->call('approveDayOvertime', $renderedEmployeeId, '2026-09-08', 150);

    expect(OvertimeApproval::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0)
        ->and($page->get('dtrEmployee'))->toBe($other->id);
});

it('approves a day as seaport overtime from its dialog and shows the category afterwards', function () {
    $employee = edtrotEmployee();
    edtrotWorked($employee, '2026-09-08');
    edtrotActor(['EDTR']);

    $page = edtrotPage($employee)->call('openOvertime', '2026-09-08')
        ->set('overtimeForm.seaport', true)
        ->call('approveOvertime');

    $row = OvertimeApproval::query()->sole();
    expect($row->category)->toBe('SEAPORT')
        ->and($row->approved_minutes)->toBe(150)
        ->and($page->get('overtimeError'))->toBeNull()
        ->and(edtrotPanel($page->html()))->toContain('data-overtime-category="SEAPORT"');

    // Reopening the day reads the category back into the draft.
    $page->set('overtimeOpen', false)->call('openOvertime', '2026-09-08');
    expect($page->get('overtimeForm.seaport'))->toBeTrue();
});
