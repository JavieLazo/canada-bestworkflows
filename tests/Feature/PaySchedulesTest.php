<?php

declare(strict_types=1);

use App\Domain\Enums\AuditEvent;
use App\Domain\Enums\PayrollRunKind;
use App\Domain\Enums\PayrollRunStatus;
use App\Domain\Payroll\StatutorySchedule;
use App\Livewire\Payroll\Index as PayrollIndex;
use App\Livewire\Payroll\RunWorkspace;
use App\Livewire\Records\PaySchedulesPage;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Holiday;
use App\Models\Notification;
use App\Models\PayrollPaySchedule;
use App\Models\PayrollRun;
use App\Models\PayrollStatutoryTable;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\User;
use App\Modules\Payroll\Application\Commands\DeletePaySchedule;
use App\Modules\Payroll\Application\Commands\MarkPayrollReleased;
use App\Modules\Payroll\Application\Commands\SavePayroll;
use App\Modules\Payroll\Application\Commands\SavePaySchedule;
use App\Modules\Payroll\Application\PayrollDraft;
use App\Modules\Payroll\Application\PaySchedules;
use App\Modules\Payroll\Application\Queries\PayScheduleRecords;
use App\Modules\Payroll\Application\StatutoryTables;
use App\Modules\Payroll\Domain\Schedules\PaySchedule;
use App\Support\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Pay schedules (2026_11_02_0004): how a month is cut into salary periods
 * and when each is paid, one schedule the company's default. A regular
 * payroll records which schedule gave it its dates, its pay date and its
 * contribution month; the withholding table is picked by the pay date and
 * the contributions by the contribution month; the run list and workspace
 * show the pay date and the schedule's deadlines; a finalized payroll can
 * be marked released; and payroll officers are reminded of a pay date
 * with no finalized payroll behind it. Nothing here moves a peso on a run
 * made without a schedule.
 */

/** @param list<string> $modules */
function pschActor(array $modules = ['PAYROLL'], bool $allData = true): Access
{
    $role = new Role;
    $role->forceFill(['name' => 'Pay schedules '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();
    foreach ($modules as $module) {
        (new RoleModuleGrant)->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }
    $user = User::factory()->create(['role_id' => $role->getKey(), 'is_active' => true]);
    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function pschEmployee(string $lastName): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill(['employee_code' => 'PSCH-'.Str::upper(Str::random(5)), 'first_name' => 'Pay', 'last_name' => $lastName,
        'employment_status' => 'Regular', 'date_hired' => '2024-01-01', 'monthly_rate' => 30000, 'is_active' => true])->save();

    return $employee->refresh();
}

/**
 * The 15th-and-30th schedule, paid the working day before a weekend or holiday, with a deadline.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function pschFigures(array $changes = []): array
{
    return array_replace(PaySchedule::standard()->toArray(), [
        'periods' => [
            ['start' => 1, 'end' => 15, 'pay_day' => ['kind' => PaySchedule::PAY_DAY, 'day' => 15, 'next_month' => false]],
            ['start' => 16, 'end' => null, 'pay_day' => ['kind' => PaySchedule::PAY_DAY, 'day' => 30, 'next_month' => false]],
        ],
        'holiday_rule' => PaySchedule::MOVE_BEFORE,
        'deadlines' => [['label' => 'Approved', 'days_before' => 3]],
    ], $changes);
}

/* |---| The migration and the default |---| */

it('creates the schedules table with one default and the run columns, and resumes from a partial run', function () {
    expect(PaySchedules::ready())->toBeTrue();
    $default = PaySchedules::default();
    expect($default?->name)->toBe('Semi-monthly, paid on the last day')
        ->and(PaySchedules::schedule($default)->toArray())->toBe(PaySchedule::standard()->toArray());

    $m = require base_path('database/migrations/2026_11_02_0004_create_payroll_pay_schedules_table.php');
    $m->down();
    expect(Schema::hasTable('payroll_pay_schedules'))->toBeFalse()->and(Schema::hasColumn('payroll_runs', 'pay_date'))->toBeFalse();
    $m->up();
    $m->up();
    expect(Schema::hasColumns('payroll_runs', ['schedule_id', 'pay_date', 'contribution_month', 'released_at', 'released_by', 'released_by_name']))->toBeTrue()
        ->and(Schema::hasIndex('payroll_pay_schedules', 'pps_name_unq'))->toBeTrue()
        ->and(PayrollPaySchedule::query()->count())->toBe(1)
        ->and(PayrollPaySchedule::query()->sole()->is_default)->toBeTrue();
});

/* |---| The commands |---| */

it('adds, edits, re-defaults and deletes schedules for whoever manages the Payroll Settings, audited, keeping exactly one default', function () {
    $reader = pschActor(['PAYROLL'], allData: false);
    expect(fn () => app(SavePaySchedule::class)->handle($reader, null, 'Mine', pschFigures(), false))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.')
        ->and(PayrollPaySchedule::query()->count())->toBe(1);

    $actor = pschActor();
    $save = app(SavePaySchedule::class);
    expect(fn () => $save->handle($actor, null, ' ', pschFigures(), false))->toThrow(RuntimeException::class, 'Name the schedule')
        ->and(fn () => $save->handle($actor, null, 'Bad', pschFigures(['holiday_rule' => 'SKIP']), false))->toThrow(RuntimeException::class, 'Choose what a pay date on a weekend or holiday does.')
        ->and(fn () => $save->handle($actor, null, 'Semi-monthly, paid on the last day', pschFigures(), false))->toThrow(RuntimeException::class, 'A pay schedule with that name already exists.')
        ->and(fn () => $save->handle($actor, (string) Str::ulid(), 'Gone', pschFigures(), false))->toThrow(RuntimeException::class, 'That pay schedule no longer exists.');

    $id = $save->handle($actor, null, '15th and 30th', pschFigures(), false);
    $row = PayrollPaySchedule::query()->whereKey($id)->sole();
    expect($row->is_default)->toBeFalse()
        ->and($row->created_by)->toBe((int) $actor->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_SCHEDULE_SAVED->value)->sole()->details)
        ->toBe('Pay schedule "15th and 30th" added: 1st–15th paid on the 15th; 16th–end paid on the 30th; moved before a weekend or holiday; Approved 3 days before; contributions of the period\'s month');

    // The seeded default cannot be un-defaulted or deleted; making another the default moves the flag.
    $seeded = PaySchedules::default();
    expect(fn () => $save->handle($actor, $seeded->id, $seeded->name, $seeded->params, false))->toThrow(RuntimeException::class, 'Make another schedule the default first')
        ->and(fn () => app(DeletePaySchedule::class)->handle($actor, $seeded->id))->toThrow(RuntimeException::class, 'The default schedule cannot be deleted.');
    $save->handle($actor, $id, '15th and 30th', pschFigures(), true);
    expect($row->refresh()->is_default)->toBeTrue()
        ->and($seeded->refresh()->is_default)->toBeFalse()
        ->and(PayrollPaySchedule::query()->where('is_default', true)->count())->toBe(1);

    // A schedule a payroll took its dates from stays; one no payroll used goes.
    $employee = pschEmployee('Dated');
    app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'November first half', '2026-11-01', '2026-11-15', [$employee->id], [], [], [], scheduleId: $seeded->id));
    expect(fn () => app(DeletePaySchedule::class)->handle($actor, $seeded->id))->toThrow(RuntimeException::class, 'One payroll took its dates from this schedule, so it stays on record.');
    $spare = $save->handle($actor, null, 'Spare', pschFigures(), false);
    expect(app(DeletePaySchedule::class)->handle($actor, $spare))->toBe('Pay schedule "Spare" deleted.')
        ->and(PayrollPaySchedule::query()->whereKey($spare)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditEvent::PAY_SCHEDULE_DELETED->value)->sole()->details)->toBe('Pay schedule "Spare" deleted')
        ->and(app(PayScheduleRecords::class)->list($actor)->pluck('name')->all())->toBe(['15th and 30th', 'Semi-monthly, paid on the last day']);
});

/* |---| The payroll |---| */

it('records the schedule, pay date and contribution month on a payroll, picks the tables by them, and refuses what cannot be used', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 02:00:00', 'UTC'));
    $actor = pschActor();
    $employee = pschEmployee('Scheduled');
    Holiday::create(['code' => 'H-PSCH', 'name' => 'Bonifacio Day', 'date' => '2026-11-30', 'schedule' => 'ANNUAL', 'type' => 'REGULAR', 'percent' => 100]);
    $schedule = PayrollPaySchedule::query()->whereKey(app(SavePaySchedule::class)->handle($actor, null, '15th and 30th', pschFigures(['contribution_month' => PaySchedule::MONTH_PAY_DATE]), true))->sole();

    // 30 Nov 2026 is a holiday on a Monday: the Friday before, the 27th; the period's month carries the contributions.
    $period = PaySchedules::periodFor($schedule, '2026-11-16', '2026-11-30');
    expect($period)->toMatchArray(['pay_date' => '2026-11-27', 'contribution_month' => '2026-11', 'position' => 'LAST'])
        ->and($period['deadlines'])->toBe([['label' => 'Approved', 'date' => '2026-11-24']])
        ->and(PaySchedules::periodFor($schedule, '2026-11-10', '2026-11-25'))->toBeNull()
        ->and(PaySchedules::nextAfter($schedule, '2026-11-16')['start'])->toBe('2026-12-01');

    // Saved with the schedule: the pay date it gives, unless typed; the contribution month its rule gives.
    ['id' => $runId] = app(SavePayroll::class)->handle($actor, new PayrollDraft(null, 'November second half', '2026-11-16', '2026-11-30', [$employee->id], [], [], [], scheduleId: $schedule->id));
    $run = PayrollRun::query()->whereKey($runId)->sole();
    expect($run->schedule_id)->toBe($schedule->id)
        ->and($run->pay_date?->format('Y-m-d'))->toBe('2026-11-27')
        ->and($run->contribution_month)->toBe('2026-11')
        ->and(AuditLog::query()->where('action', AuditEvent::PAYROLL_RUN_CREATED->value)->sole()->details)->toContain('; on the 15th and 30th schedule; paid 27 Nov 2026');
    app(SavePayroll::class)->handle($actor, new PayrollDraft($runId, 'November second half', '2026-11-16', '2026-11-30', [$employee->id], [], [], [], scheduleId: $schedule->id, payDate: '2026-12-01'));
    expect($run->refresh()->pay_date?->format('Y-m-d'))->toBe('2026-12-01');

    // Refusals: a day that is not one, a schedule that is gone, a lagged window, and a 13th month run.
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($runId, 'November second half', '2026-11-16', '2026-11-30', [$employee->id], [], [], [], payDate: '2026-13-01')))->toThrow(RuntimeException::class, 'Enter the pay date as a real day, or leave it blank.')
        ->and(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($runId, 'November second half', '2026-11-16', '2026-11-30', [$employee->id], [], [], [], scheduleId: (string) Str::ulid())))->toThrow(RuntimeException::class, 'That pay schedule no longer exists.');
    $lagged = app(SavePaySchedule::class)->handle($actor, null, 'Lagged', pschFigures(['attendance_lag' => 5]), false);
    expect(fn () => app(SavePayroll::class)->handle($actor, new PayrollDraft($runId, 'November second half', '2026-11-16', '2026-11-30', [$employee->id], [], [], [], scheduleId: $lagged)))->toThrow(RuntimeException::class, 'cannot make a payroll yet');

    // The withholding table is picked by the pay date, the contributions by the contribution month.
    $december = new PayrollStatutoryTable;
    $december->forceFill(['kind' => StatutorySchedule::WITHHOLDING_TAX, 'effective_from' => '2026-12-01', 'covered_through' => '2027-12-31', 'params' => StatutorySchedule::BUILT_IN[StatutorySchedule::WITHHOLDING_TAX]])->save();
    $sssDecember = new PayrollStatutoryTable;
    $sssDecember->forceFill(['kind' => StatutorySchedule::SSS, 'effective_from' => '2026-12-01', 'covered_through' => '2027-12-31', 'params' => StatutorySchedule::BUILT_IN[StatutorySchedule::SSS]])->save();
    $tables = app(StatutoryTables::class);
    expect($tables->resolve('2026-11-16', '2026-11-30')->tables[StatutorySchedule::WITHHOLDING_TAX]['version_id'])->not->toBe($december->id)
        ->and($tables->resolve('2026-11-16', '2026-11-30', '2026-12-01')->tables[StatutorySchedule::WITHHOLDING_TAX]['version_id'])->toBe($december->id)
        ->and($tables->resolve('2026-11-16', '2026-11-30', '2026-12-01')->tables[StatutorySchedule::SSS]['version_id'])->not->toBe($sssDecember->id)
        ->and($tables->resolve('2026-11-16', '2026-11-30', '2026-12-01', '2026-12')->tables[StatutorySchedule::SSS]['version_id'])->toBe($sssDecember->id);
});

it('starts a new payroll from the default schedule on the workspace, follows the dates, and shows the pay date on the list', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 02:00:00', 'UTC'));
    $actor = pschActor();
    $employee = pschEmployee('Workspace');
    $schedule = app(SavePaySchedule::class)->handle($actor, null, '15th and 30th', pschFigures(), true);

    $page = Livewire::test(RunWorkspace::class)
        ->assertSet('scheduleId', $schedule)
        // The current month's first cut-off: 15 Nov 2026 is a Sunday, so the Friday before.
        ->assertSet('start', '2026-11-01')->assertSet('end', '2026-11-15')
        ->assertSet('payDate', '2026-11-13')
        ->assertSee('Approved by')
        ->assertDontSee('not a 1st–15th or 16th–end-of-month cut-off')
        ->set('start', '2026-11-16')->set('end', '2026-11-30')
        ->assertSet('payDate', '2026-11-30')
        // Dates that are not one of the schedule's periods keep the typed pay date and get the cut-off advice.
        ->set('end', '2026-11-25')
        ->assertSet('payDate', '2026-11-30')
        ->assertSee('not a 1st–15th or 16th–end-of-month cut-off')
        ->set('end', '2026-11-30')
        ->set('scheduleId', '')
        ->assertSet('payDate', '2026-11-30');
    $page->set('name', 'November second half')->set('scheduleId', $schedule)->call('openPicker')->call('togglePick', $employee->id)->call('applyPicked')->call('save');
    $run = PayrollRun::query()->sole();
    expect($run->schedule_id)->toBe($schedule)->and($run->pay_date?->format('Y-m-d'))->toBe('2026-11-30');

    Livewire::test(PayrollIndex::class)->assertSee('Pay date')->assertSee('Nov 30, 2026');
});

it('marks a finalized payroll released once, audited, and never a draft', function () {
    $actor = pschActor();
    $employee = pschEmployee('Released');
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-REL', 'name' => 'Released payroll', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'status' => PayrollRunStatus::DRAFT, 'kind' => PayrollRunKind::REGULAR])->save();
    expect(fn () => app(MarkPayrollReleased::class)->handle($actor, $run->id))->toThrow(RuntimeException::class, 'Only a finalized payroll is released.');
    $run->forceFill(['status' => PayrollRunStatus::FINALIZED, 'finalized_at' => CarbonImmutable::now('UTC')])->save();

    expect(app(MarkPayrollReleased::class)->handle($actor, $run->id))->toBe('Payroll PR-REL marked released.');
    $run->refresh();
    expect($run->released_at)->not->toBeNull()
        ->and($run->released_by)->toBe((int) $actor->userId)
        ->and(AuditLog::query()->where('action', AuditEvent::PAYROLL_RELEASED->value)->sole()->details)->toBe('Payroll PR-REL Released payroll marked released')
        ->and(fn () => app(MarkPayrollReleased::class)->handle($actor, $run->id))->toThrow(RuntimeException::class, 'This payroll is already marked released.')
        ->and(fn () => app(MarkPayrollReleased::class)->handle(pschActor(['PAYROLL'], allData: false), $run->id))->toThrow(RuntimeException::class, 'Your role cannot manage organization-wide data.');
    pschActor();
    Livewire::test(PayrollIndex::class)->assertSee('data-released-pill', false);
    Livewire::test(RunWorkspace::class, ['id' => $run->id])->assertSee('Released')->assertDontSee('Mark as released');
    unset($employee);
});

/* |---| The reminders |---| */

it('reminds payroll officers of a pay date with no finalized payroll behind it, once, and nobody else', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 02:00:00', 'UTC'));
    $manager = pschActor();
    app(SavePaySchedule::class)->handle($manager, null, '15th and 30th', pschFigures(), true);
    $reader = pschActor(['PAYROLL'], allData: false);

    Artisan::call('reminders:sweep');
    $rows = Notification::query()->where('kind', 'PAYROLL_DUE')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe((int) $manager->userId)
        ->and($rows[0]->title)->toBe('Payroll for 01 Nov 2026 – 15 Nov 2026 is paid in 3 days')
        ->and($rows[0]->body)->toBe('Approved by 10 Nov 2026 — no finalized payroll covers it yet.')
        ->and($rows[0]->href)->toBe('/payroll')
        ->and(Notification::query()->where('user_id', (int) $reader->userId)->count())->toBe(0);

    Artisan::call('reminders:sweep');
    expect(Notification::query()->where('kind', 'PAYROLL_DUE')->count())->toBe(1);

    // Once a finalized payroll covers the period, a later period is the next reminder.
    $run = new PayrollRun;
    $run->forceFill(['code' => 'PR-DONE', 'name' => 'Done', 'period_start' => '2026-11-01', 'period_end' => '2026-11-15', 'status' => PayrollRunStatus::FINALIZED, 'kind' => PayrollRunKind::REGULAR, 'finalized_at' => CarbonImmutable::now('UTC')])->save();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 02:00:00', 'UTC'));
    Artisan::call('reminders:sweep');
    expect(Notification::query()->where('kind', 'PAYROLL_DUE')->pluck('title')->all())
        ->toBe(['Payroll for 01 Nov 2026 – 15 Nov 2026 is paid in 3 days', 'Payroll for 16 Nov 2026 – 30 Nov 2026 is paid in 5 days']);
});

/* |---| The page |---| */

it('adds, edits, re-defaults and deletes schedules from the page, previewing the coming pay dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 02:00:00', 'UTC'));
    pschActor(['PAYROLL'], allData: false);
    $this->get('/payroll/settings/pay-schedules')->assertOk()->assertSee('Semi-monthly, paid on the last day')->assertDontSee('openAdd');
    Livewire::test(PaySchedulesPage::class)->call('openAdd')->assertSet('dialogOpen', false);

    pschActor();
    $this->get('/payroll/settings/pay-schedules')->assertOk()->assertSee('openAdd')->assertSee('Default');
    Livewire::test(PaySchedulesPage::class)
        ->call('openAdd')
        ->assertSet('dialogOpen', true)
        ->assertSet('formPeriods', PaySchedulesPage::PERIODS_SEMI_MONTHLY)
        ->set('formName', '15th and 30th')
        ->set('formPayDays.0.kind', PaySchedule::PAY_DAY)->set('formPayDays.0.day', '15')
        ->set('formPayDays.1.kind', PaySchedule::PAY_DAY)->set('formPayDays.1.day', '30')
        ->set('formHolidayRule', PaySchedule::MOVE_BEFORE)
        ->call('addDeadline')
        ->set('formDeadlines.0.label', 'Approved')->set('formDeadlines.0.days', '3')
        ->set('formIsDefault', true)
        // The preview: 15 Nov 2026 is a Sunday, paid the Friday before.
        ->assertSee('data-schedule-preview', false)
        ->assertSee('13 Nov 2026')
        ->call('save')
        ->assertSet('dialogOpen', false)
        ->assertSet('formError', null)
        ->assertSee('15th and 30th')
        ->assertSee('Paid on the 15th and the 30th')
        ->assertSee('Working day before')
        ->assertSee('Approved 3 days before');
    $added = PayrollPaySchedule::query()->where('name', '15th and 30th')->sole();
    expect($added->is_default)->toBeTrue()->and(PayrollPaySchedule::query()->where('is_default', true)->count())->toBe(1);

    // A refusal stays in the dialog; the seeded one becomes the default again and the added one goes.
    $seeded = PayrollPaySchedule::query()->where('name', 'Semi-monthly, paid on the last day')->sole();
    Livewire::test(PaySchedulesPage::class)
        ->call('startEdit', $added->id)
        ->assertSet('formName', '15th and 30th')
        ->set('formPayDays.0.day', '32')
        ->call('save')
        ->assertSet('dialogOpen', true)
        ->assertSet('formError', 'A pay day is a day of the month, 1 to 31.')
        ->call('makeDefault', $seeded->id)
        ->call('confirmDelete', $added->id)
        ->assertSet('confirmOpen', true)
        ->call('deleteConfirmed')
        ->assertDontSee('schedule-'.$added->id, false);
    expect($seeded->refresh()->is_default)->toBeTrue()->and(PayrollPaySchedule::query()->count())->toBe(1);

    expect($this->get('/payroll/settings/pay-schedules/export')->assertOk()->streamedContent())->toContain('Semi-monthly, paid on the last day')->toContain('Paid on the last day of each period');
});
