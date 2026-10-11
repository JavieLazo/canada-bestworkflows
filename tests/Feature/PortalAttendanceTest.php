<?php

declare(strict_types=1);

use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Domain\Attendance\DtrSummary;
use App\Domain\Enums\ModuleCode;
use App\Livewire\Portal\MyAttendance;
use App\Livewire\Portal\PortalPage;
use App\Livewire\Profile\ProfilePage;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\LeaveTravelRequest;
use App\Models\OvertimeApproval;
use App\Models\PayrollFormula;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\Queries\OwnAttendance;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\Views\OwnAttendanceView;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * My Portal reads only the current linked employee's attendance under PORTAL,
 * including for managers, and shares the DTR's calculation and day-close rules.
 * The tab is read-only and loads only while selected.
 */

/** @param list<string> $modules */
function portalAttendanceActor(?EmployeeProfile $employee = null, array $modules = [ModuleCode::PORTAL], bool $allData = false): User
{
    $role = new Role;
    $role->forceFill(['name' => 'Portal attendance '.Str::random(8), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->id, 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee?->id]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return $user;
}

function portalAttendanceEmployee(bool $scheduled = true): EmployeeProfile
{
    $employee = EmployeeProfile::query()->create([
        'employee_code' => 'PA-'.Str::random(8), 'first_name' => 'Ana', 'last_name' => 'Reyes',
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'is_active' => true,
    ]);
    if ($scheduled) {
        Shift::query()->create([
            'employee_id' => $employee->id, 'mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 0,
            'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
            'requires_break_punch' => false,
        ]);
    }

    return $employee;
}

function portalAttendancePunch(EmployeeProfile $employee, string $type, string $manilaStamp): void
{
    $log = new AttendanceLog;
    $log->forceFill([
        'employee_id' => $employee->id, 'log_type' => $type, 'source' => 'MANUAL',
        'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();
}

function portalAttendanceWorked(EmployeeProfile $employee, string $date, string $out = '17:00'): void
{
    portalAttendancePunch($employee, 'TIME_IN', $date.'T08:00:00');
    portalAttendancePunch($employee, 'TIME_OUT', $date.'T'.$out.':00');
}

/** @return list<string> The rendered attendance row's cell text, with whitespace collapsed. */
function portalAttendanceCells(string $html, string $date): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $nodes = (new DOMXPath($document))->query('//tr[@*[name()="wire:key"]="own-attendance-'.$date.'"]/td');
    $cells = [];
    if ($nodes !== false) {
        foreach ($nodes as $node) {
            assert($node instanceof DOMElement);
            $cells[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
        }
    }

    return $cells;
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-09T12:00:00+08:00'));
});

it('reads only own attendance for PORTAL employees and attendance administrators', function (bool $manager) {
    $own = portalAttendanceEmployee();
    $other = portalAttendanceEmployee();
    portalAttendanceWorked($own, '2026-10-05');
    portalAttendanceWorked($other, '2026-10-06', '19:00');
    portalAttendanceActor($own, $manager ? [ModuleCode::PORTAL, ModuleCode::EDTR, ModuleCode::PAYROLL] : [ModuleCode::PORTAL], $manager);

    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06');
    expect($attendance)->toBeInstanceOf(OwnAttendanceView::class);
    assert($attendance instanceof OwnAttendanceView);
    expect($attendance->days[0]->status)->toBe(DayRecord::PRESENT)
        ->and($attendance->days[1]->timeIn)->toBeNull()
        ->and($attendance->totals->workedMinutes)->toBe(480.0)
        ->and($attendance->overtimeByDate)->toBe([]);

    Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-06')
        ->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->totals->workedMinutes === 480.0)
        ->assertDontSeeHtml('wire:click="openCorrection')
        ->assertDontSeeHtml('wire:click="approve')
        ->assertDontSee('Export CSV');
})->with(['employee' => false, 'administrator' => true]);

it('does not widen the DTR reader to grant PORTAL access to employee selection', function () {
    $own = portalAttendanceEmployee();
    $other = portalAttendanceEmployee();
    portalAttendanceActor($own, allData: true);

    expect(fn () => app(AttendanceSource::class)->forDtr(app(Access::class), $other->id, '2026-10-05', '2026-10-06'))
        ->toThrow(HttpException::class);
});

it('returns no attendance for an unlinked account even with attendance management grants', function (bool $manager) {
    portalAttendanceWorked(portalAttendanceEmployee(), '2026-10-05');
    portalAttendanceActor(null, [ModuleCode::PORTAL, ModuleCode::EDTR, ModuleCode::PAYROLL], $manager);

    expect(app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06'))->toBeNull();
    Livewire::test(MyAttendance::class)
        ->assertViewHas('attendance', null)
        ->assertSee('Employee record not linked')
        ->assertDontSee('8:00 AM');
})->with(['employee' => false, 'administrator' => true]);

it('requires PORTAL on direct attendance reads sources and independently mounted components', function (bool $profileOnly) {
    portalAttendanceActor(portalAttendanceEmployee(), $profileOnly ? [ModuleCode::PROFILE] : [ModuleCode::EDTR, ModuleCode::PAYROLL], true);

    expect(fn () => app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06'))
        ->toThrow(HttpException::class);
    expect(fn () => app(AttendanceSource::class)->forOwnPortal(app(Access::class), '2026-10-05', '2026-10-06'))
        ->toThrow(HttpException::class);
    Livewire::test(MyAttendance::class)->assertForbidden();
})->with([
    'PROFILE only' => true,
    'attendance management without PORTAL' => false,
]);

it('rejects malformed reversed and oversized attendance ranges at the query boundary', function (string $start, string $end) {
    portalAttendanceActor(portalAttendanceEmployee());

    expect(fn () => app(OwnAttendance::class)->between(app(Access::class), $start, $end))
        ->toThrow(ValidationException::class);
})->with([
    'missing start' => ['', '2026-10-09'],
    'non-calendar day' => ['2026-02-30', '2026-03-01'],
    'unformatted date' => ['2026-9-01', '2026-09-10'],
    'reversed' => ['2026-10-09', '2026-10-01'],
    '32 inclusive days' => ['2026-09-01', '2026-10-02'],
]);

it('accepts exactly 31 days and recovers from invalid date drafts without stale rows', function () {
    portalAttendanceActor(portalAttendanceEmployee());
    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-09-01', '2026-10-01');
    expect($attendance?->days)->toHaveCount(31);

    Livewire::test(MyAttendance::class)
        ->set('start', '2026-02-30')
        ->assertHasErrors('period')
        ->assertViewHas('attendance', null)
        ->call('thisCutoff')
        ->assertHasNoErrors('period')
        ->assertSet('start', '2026-10-01')
        ->assertSet('end', '2026-10-15');
});

it('matches the DTR engine for overnight punch slots approved leave and holidays', function () {
    $employee = portalAttendanceEmployee();
    Shift::query()->where('employee_id', $employee->id)->update([
        'start_time' => '22:00:00', 'end_time' => '07:00:00',
        'break_start' => '02:00:00', 'break_end' => '03:00:00', 'requires_break_punch' => true,
    ]);
    portalAttendancePunch($employee, 'TIME_IN', '2026-10-05T22:00:00');
    portalAttendancePunch($employee, 'TIME_OUT', '2026-10-06T02:00:00');
    portalAttendancePunch($employee, 'TIME_IN', '2026-10-06T03:00:00');
    portalAttendancePunch($employee, 'TIME_OUT', '2026-10-06T08:00:00');
    LeaveTravelRequest::query()->create([
        'code' => 'PA-LEAVE', 'employee_id' => $employee->id, 'request_type' => 'VACATION_LEAVE',
        'day_type' => 'WHOLE_DAY', 'start_date' => '2026-10-06', 'end_date' => '2026-10-06',
        'status' => 'APPROVED', 'is_paid' => true, 'reason' => 'Private leave reason',
    ]);
    Holiday::query()->create([
        'code' => 'PA-HOLIDAY', 'name' => 'Attendance holiday', 'date' => '2026-10-07',
        'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 100,
    ]);
    $user = portalAttendanceActor($employee);
    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-07');
    assert($attendance instanceof OwnAttendanceView);

    RoleModuleGrant::query()->create(['role_id' => $user->role_id, 'module' => ModuleCode::EDTR]);
    $user->unsetRelation('role');
    $inputs = app(AttendanceSource::class)->forDtr(Access::forActor($user), $employee->id, '2026-10-05', '2026-10-07');
    $expected = DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, '2026-10-05', '2026-10-07', app(HolidayCalendar::class)->all());

    expect(array_map(static fn (DayRecord $day): array => $day->toArray(), $attendance->days))
        ->toBe(array_map(static fn (DayRecord $day): array => $day->toArray(), $expected))
        ->and($attendance->totals->toArray())->toBe(DtrSummary::of($expected)->toArray())
        ->and($attendance->days[0]->regularMinutes())->toBe(480.0)
        ->and($attendance->days[0]->overtimeMinutes)->toBe(60.0)
        ->and($attendance->days[0]->breakOut)->not->toBeNull()
        ->and($attendance->days[0]->breakIn)->not->toBeNull()
        ->and($attendance->days[1]->excusePaid)->toBeTrue()
        ->and($attendance->days[2]->status)->toBe(DayRecord::EXCUSED);

    Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-07')
        ->assertSee('next day')
        ->assertDontSee('Private leave reason');
});

it('shows dashes on unworked days off while retaining leave business and worked attendance', function () {
    $employee = portalAttendanceEmployee();
    portalAttendanceWorked($employee, '2026-10-04');
    portalAttendanceWorked($employee, '2026-10-08');
    Holiday::query()->create([
        'code' => 'PA-DAY-OFF', 'name' => 'Day off holiday', 'date' => '2026-10-05',
        'schedule' => 'ONE_TIME', 'type' => 'REGULAR', 'percent' => 100,
    ]);
    foreach (['2026-10-06' => 'VACATION_LEAVE', '2026-10-07' => 'BUSINESS_TRAVEL'] as $date => $type) {
        LeaveTravelRequest::query()->create([
            'code' => 'PA-OFF-'.$type, 'employee_id' => $employee->id, 'request_type' => $type,
            'day_type' => 'WHOLE_DAY', 'start_date' => $date, 'end_date' => $date,
            'status' => 'APPROVED', 'is_paid' => $type === 'VACATION_LEAVE' ? true : null,
        ]);
    }
    portalAttendanceActor($employee);
    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-10-03', '2026-10-08');
    assert($attendance instanceof OwnAttendanceView);

    $page = Livewire::test(MyAttendance::class)->set('start', '2026-10-03')->set('end', '2026-10-08');
    $page->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => count($view->days) === 6
        && $view->totals->toArray() === $attendance->totals->toArray()
        && $view->absences === $attendance->absences);
    $html = $page->html();

    foreach (['2026-10-03' => 'Oct 03, 2026', '2026-10-05' => 'Oct 05, 2026'] as $date => $label) {
        $cells = portalAttendanceCells($html, $date);
        expect($cells)->toHaveCount(10)
            ->and($cells[0])->toBe($label)
            ->and(array_slice($cells, 1))->toBe(array_fill(0, 9, '—'));
    }
    expect(portalAttendanceCells($html, '2026-10-04'))
        ->toContain('Present')->toContain('8:00 AM')->toContain('5:00 PM')
        ->and(portalAttendanceCells($html, '2026-10-06'))->toContain('Excused')
        ->and(portalAttendanceCells($html, '2026-10-07'))->toContain('Official business')
        ->and(portalAttendanceCells($html, '2026-10-08'))->toContain('Present')->toContain('8:00 AM')->toContain('5:00 PM');
});

it('keeps an orphan punch visible on a day off', function () {
    $employee = portalAttendanceEmployee();
    Shift::query()->where('employee_id', $employee->id)->update([
        'break_start' => null, 'break_end' => null, 'break_minutes' => 0,
    ]);
    portalAttendancePunch($employee, 'TIME_OUT', '2026-10-04T17:00:00');
    portalAttendanceActor($employee);

    $page = Livewire::test(MyAttendance::class)->set('start', '2026-10-04')->set('end', '2026-10-04');
    expect(portalAttendanceCells($page->html(), '2026-10-04'))->toContain('Oct 04, 2026')->toContain('5:00 PM');
    $page->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->days[0]->timeOut !== null);
});

it('distinguishes computed overtime from approved minutes without exposing review metadata', function () {
    $employee = portalAttendanceEmployee();
    portalAttendanceWorked($employee, '2026-10-05', '19:00');
    portalAttendanceWorked($employee, '2026-10-06', '18:00');
    $user = portalAttendanceActor($employee);
    $approval = new OvertimeApproval;
    $approval->forceFill([
        'employee_id' => $employee->id, 'date' => '2026-10-05', 'decision' => 'APPROVED',
        'computed_minutes' => 120, 'approved_minutes' => 60, 'note' => 'Private review note', 'reviewed_by' => $user->id,
    ])->save();

    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06');
    assert($attendance instanceof OwnAttendanceView);
    $partial = $attendance->overtimeByDate['2026-10-05'];
    expect($partial->status)->toBe('PARTIAL')
        ->and($partial->computed_minutes)->toBe(120)
        ->and($partial->approved_minutes)->toBe(60)
        ->and($partial->payable_minutes)->toBe(60)
        ->and($partial->note)->toBeNull()
        ->and($partial->reviewer_name)->toBeNull()
        ->and($partial->decided_at)->toBeNull()
        ->and($attendance->overtimeByDate['2026-10-06']->status)->toBe('PENDING')
        ->and($attendance->overtimeByDate['2026-10-06']->payable_minutes)->toBe(0);

    Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-06')
        ->assertSee('Partially approved')
        ->assertDontSee('Pending approval')
        ->assertDontSee('Private review note');
    expect(OvertimeApproval::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('counts an absence only after the day closes and never computes future days', function () {
    PayrollFormula::query()->create([
        'code' => 'PA-OT', 'key' => 'OT', 'name' => 'Overtime', 'expression' => '',
        'params' => [['key' => 'max_ot', 'label' => 'Maximum hours', 'value' => 2]],
    ]);
    portalAttendanceActor(portalAttendanceEmployee());
    $query = app(OwnAttendance::class);
    $attendance = $query->between(app(Access::class), '2026-10-08', '2026-10-12');
    assert($attendance instanceof OwnAttendanceView);
    expect(array_column($attendance->days, 'date'))->toBe(['2026-10-08', '2026-10-09'])
        ->and($attendance->absences)->toBe(1);

    $this->travelTo(new DateTimeImmutable('2026-10-09T19:00:00+08:00'));
    expect($query->between(app(Access::class), '2026-10-08', '2026-10-12')?->absences)->toBe(2);

    $future = $query->between(app(Access::class), '2026-10-10', '2026-10-15');
    expect($future?->days)->toBe([])
        ->and($future?->totals->workedMinutes)->toBe(0.0)
        ->and($future?->absences)->toBe(0);
});

it('identifies unscheduled attendance calculated from the default schedule', function () {
    $employee = portalAttendanceEmployee(scheduled: false);
    portalAttendanceWorked($employee, '2026-10-05');
    portalAttendanceActor($employee);
    $attendance = app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06');
    assert($attendance instanceof OwnAttendanceView);

    expect($attendance->unscheduled['days'])->toBe(2)
        ->and($attendance->unscheduled['absences'])->toBe(1);
    Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-06')
        ->assertSee('no assigned schedule');
});

it('mounts attendance only on its Portal tab and supports the direct tab link', function () {
    portalAttendanceActor(portalAttendanceEmployee());
    $child = '&quot;name&quot;:&quot;portal.my-attendance&quot;';

    Livewire::test(PortalPage::class)
        ->assertDontSeeHtml($child)
        ->set('tab', 'attendance')->assertSeeHtml($child)
        ->set('tab', 'requests')->assertDontSeeHtml($child)
        ->set('tab', 'payslips')->assertDontSeeHtml($child);
    $this->get('/portal?tab=attendance')->assertOk()->assertSee($child, false);
});

it('keeps attendance out of My Profile and collapses its old tab link to the employee record', function () {
    portalAttendanceActor(portalAttendanceEmployee(), [ModuleCode::PROFILE, ModuleCode::PORTAL]);

    Livewire::test(ProfilePage::class)
        ->assertDontSee('Attendance')
        ->set('tab', 'attendance')
        ->assertSet('tab', 'record')
        ->assertDontSeeHtml('&quot;name&quot;:&quot;portal.my-attendance&quot;')
        ->assertDontSeeHtml('&quot;name&quot;:&quot;profile.my-attendance&quot;');
    $this->get('/profile?tab=attendance')->assertOk()
        ->assertSee('My record')
        ->assertDontSee('Attendance')
        ->assertDontSee('&quot;name&quot;:&quot;portal.my-attendance&quot;', false);
});

it('navigates semi-monthly cutoffs across month boundaries', function () {
    $this->travelTo(new DateTimeImmutable('2026-02-16T12:00:00+08:00'));
    portalAttendanceActor(portalAttendanceEmployee());

    Livewire::test(MyAttendance::class)
        ->assertSet('start', '2026-02-16')->assertSet('end', '2026-02-28')
        ->call('previousCutoff')->assertSet('start', '2026-02-01')->assertSet('end', '2026-02-15')
        ->call('previousCutoff')->assertSet('start', '2026-01-16')->assertSet('end', '2026-01-31')
        ->call('nextCutoff')->assertSet('start', '2026-02-01')->assertSet('end', '2026-02-15')
        ->call('thisCutoff')->assertSet('start', '2026-02-16')->assertSet('end', '2026-02-28');
});

it('refreshes computed attendance after a new punch without writing attendance or audit records', function () {
    $employee = portalAttendanceEmployee();
    portalAttendanceActor($employee);
    portalAttendancePunch($employee, 'TIME_IN', '2026-10-05T08:00:00');
    $page = Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-05');
    $page->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->days[0]->status === DayRecord::INCOMPLETE);

    portalAttendancePunch($employee, 'TIME_OUT', '2026-10-05T17:00:00');
    $page->call('refreshAttendance')
        ->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->days[0]->status === DayRecord::PRESENT && $view->totals->workedMinutes === 480.0);
    expect(AttendanceLog::query()->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('reads the current employee link on later attendance interactions', function () {
    $first = portalAttendanceEmployee();
    $second = portalAttendanceEmployee();
    portalAttendanceWorked($first, '2026-10-05');
    portalAttendanceWorked($second, '2026-10-05', '19:00');
    $user = portalAttendanceActor($first, [ModuleCode::PORTAL, ModuleCode::EDTR, ModuleCode::PAYROLL], true);
    $page = Livewire::test(MyAttendance::class)
        ->set('start', '2026-10-05')->set('end', '2026-10-05');
    $page->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->totals->workedMinutes === 480.0);

    $user->forceFill(['employee_id' => $second->id])->save();
    $this->actingAs($user->refresh());
    app()->forgetInstance(Access::class);
    $page->call('refreshAttendance')
        ->assertViewHas('attendance', fn (OwnAttendanceView $view): bool => $view->totals->workedMinutes === 600.0);

    $user->forceFill(['employee_id' => null])->save();
    $this->actingAs($user->refresh());
    app()->forgetInstance(Access::class);
    $page->call('refreshAttendance')->assertViewHas('attendance', null)->assertSee('Employee record not linked');
});

it('refuses attendance refresh immediately after PORTAL is revoked', function () {
    $user = portalAttendanceActor(portalAttendanceEmployee());
    $page = Livewire::test(MyAttendance::class);
    RoleModuleGrant::query()->where('role_id', $user->role_id)->where('module', ModuleCode::PORTAL)->delete();
    $user->unsetRelation('role');
    $this->actingAs($user->refresh());
    app()->forgetInstance(Access::class);

    $page->call('refreshAttendance')->assertForbidden();
    expect(fn () => app(OwnAttendance::class)->between(app(Access::class), '2026-10-05', '2026-10-06'))
        ->toThrow(HttpException::class);
});
