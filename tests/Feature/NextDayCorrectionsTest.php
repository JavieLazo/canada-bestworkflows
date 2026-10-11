<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\DayRecord;
use App\Domain\Attendance\DtrCalculator;
use App\Livewire\Edtr\EdtrPage;
use App\Models\AttendanceLog;
use App\Models\AttendanceRemark;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleModuleGrant;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Organization\Contracts\HolidayCalendar;
use App\Modules\Timekeeping\Application\Commands\CorrectPunches;
use App\Modules\Timekeeping\Contracts\AttendanceSource;
use App\Modules\Timekeeping\Contracts\Views\CorrectionPunchView;
use App\Modules\Timekeeping\Contracts\Views\DayCorrectionView;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;
use App\Support\Access;
use App\Support\ManilaClock;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Night shifts on the Daily Time Record:
 * a correction of one date replaces EXACTLY the punches the DTR reads as that
 * date's row, and each slot says for itself whether it is on the next day.
 *
 * Pinned here through the database, the command and the EdtrPage dialog:
 *
 *   - saving a date never takes or duplicates another date's punches —
 *     back-to-back nights in either order, the rest day after a night, and
 *     the seeded break Night Shift saved twice unchanged;
 *   - a "Next day" tick round-trips (written on the next morning, read back
 *     ticked, tagged "next day" in the table on the row that owns it), is
 *     accepted on an overnight or FLEXIBLE shift, and refused with the Shift
 *     Roster message on a shift that ends the same day — with no effect;
 *   - only a literal true is a tick, an earlier time without one is refused,
 *     and a night left on an open next-day Time In is refused before it can
 *     turn the rest day after it INCOMPLETE;
 *   - the dialog lists only the row's own punches, points at the previous
 *     night's, names a borrowed slot instead of prefilling it, and tags an
 *     owned punch that no slot shows;
 *   - the command stands alone (all-data EDTR only), a self-only viewer's
 *     save changes nothing, and the audit sentence says "next day".
 *
 * The clock is Monday 2026-09-28, noon in Manila (cut-off Sep 16–30).
 * 2026-09-23 is a Wednesday, 09-24 a Thursday, 09-25 a Friday and 09-26 a
 * Saturday — a rest day on the Mon–Fri week every shift here uses.
 */

/** @param list<string> $modules */
function ndcRole(array $modules, bool $allData): Role
{
    $role = new Role;
    $role->forceFill(['name' => 'Ndc '.Str::random(6), 'all_data' => $allData, 'is_system' => false])->save();

    foreach ($modules as $module) {
        $grant = new RoleModuleGrant;
        $grant->forceFill(['role_id' => $role->getKey(), 'module' => $module])->save();
    }

    return $role;
}

/**
 * A signed-in account and the Access built fresh from it — the scoped
 * instance is forgotten first, so a later Livewire::test never reads a
 * previous actor.
 *
 * @param  list<string>  $modules
 */
function ndcActor(array $modules, bool $allData = false, ?string $employeeId = null): Access
{
    $user = User::factory()->create([
        'role_id' => ndcRole($modules, $allData)->getKey(),
        'employee_id' => $employeeId,
    ]);

    test()->actingAs($user);
    app()->forgetInstance(Access::class);

    return Access::forActor($user);
}

function ndcEmployee(string $first = 'Liza', string $last = 'Domingo'): EmployeeProfile
{
    $employee = new EmployeeProfile;
    $employee->forceFill([
        'employee_code' => 'NDC-'.random_int(10000, 99999),
        'first_name' => $first,
        'last_name' => $last,
        'position' => 'Night Operator',
        'employment_status' => 'Regular',
        'date_hired' => '2024-01-01',
        'is_active' => true,
    ])->save();

    return $employee;
}

/**
 * The employee's one `shifts` row: by default a 22:00–06:00 night with no
 * break, Mon–Fri, 15 minutes' grace.
 *
 * @param  array<string, string|int|bool|null>  $overrides
 */
function ndcShift(EmployeeProfile $employee, array $overrides = []): Shift
{
    return Shift::query()->create(array_merge([
        'employee_id' => (string) $employee->getKey(),
        'mode' => 'FIXED',
        'work_days' => [1, 2, 3, 4, 5],
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'grace_minutes' => 15,
        'required_hours' => null,
        'break_start' => null,
        'break_end' => null,
        'break_minutes' => 0,
        'requires_break_punch' => false,
    ], $overrides));
}

/** The seeded Night Shift (ShiftTypeSeeder sft-4): 22:00–06:00 with a scheduled 02:00–03:00 break. */
function ndcBreakNight(EmployeeProfile $employee): Shift
{
    return ndcShift($employee, [
        'break_start' => '02:00:00',
        'break_end' => '03:00:00',
        'break_minutes' => 60,
        'requires_break_punch' => true,
    ]);
}

/** One station punch at a Manila wall-clock instant. */
function ndcPunch(EmployeeProfile $employee, string $type, string $manilaStamp): AttendanceLog
{
    $punch = new AttendanceLog;
    $punch->forceFill([
        'employee_id' => (string) $employee->getKey(),
        'site_id' => null,
        'log_type' => $type,
        'source' => 'WEB_GPS',
        'is_geofenced' => true,
        'is_anomaly' => false,
        'logged_at' => ManilaClock::instant($manilaStamp)->utc(),
    ])->save();

    return $punch;
}

/** @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string} */
function ndcSlots(?string $timeIn, ?string $breakOut, ?string $breakIn, ?string $timeOut): array
{
    return ['time_in' => $timeIn, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $timeOut];
}

/** @return array{time_in: bool, break_out: bool, break_in: bool, time_out: bool} */
function ndcTicks(bool $timeIn, bool $breakOut, bool $breakIn, bool $timeOut): array
{
    return ['time_in' => $timeIn, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $timeOut];
}

/**
 * The employee's stored punches, oldest first, each as "TYPE Y-m-dTH:i" in
 * Manila — the day a punch is on is the point of every test here.
 *
 * @return list<string>
 */
function ndcStamps(EmployeeProfile $employee): array
{
    return array_values(AttendanceLog::query()
        ->where('employee_id', (string) $employee->getKey())
        ->orderBy('logged_at')
        ->get()
        ->map(static fn (AttendanceLog $row): string => $row->log_type.' '.PunchSlots::minuteKey(DomainLog::isoFromUtc($row->logged_at)))
        ->all());
}

/** @return list<string> the DTR_CORRECTED audit sentences */
function ndcAudits(): array
{
    return array_values(AuditLog::query()->where('action', 'DTR_CORRECTED')->pluck('details')->map(static fn (mixed $d): string => (string) $d)->all());
}

/**
 * The DTR as the screen computes it over `$start`..`$end`, keyed by date —
 * the engine's own output, never re-derived here.
 *
 * @return array<string, DayRecord>
 */
function ndcDays(Access $actor, EmployeeProfile $employee, string $start, string $end): array
{
    $inputs = app(AttendanceSource::class)->forDtr($actor, (string) $employee->getKey(), $start, $end);

    $days = [];
    foreach (DtrCalculator::compute($inputs->logs, $inputs->shift, $inputs->leaves, $start, $end, app(HolidayCalendar::class)->all()) as $day) {
        $days[$day->date] = $day;
    }

    return $days;
}

/** A stamp the engine reported, as its Manila minute (or null). */
function ndcMinute(?string $iso): ?string
{
    return $iso === null ? null : PunchSlots::minuteKey($iso);
}

/**
 * The page on the DTR tab with the employee picked.
 *
 * @return Testable<EdtrPage>
 */
function ndcPage(EmployeeProfile $employee): Testable
{
    return Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('employeePicked', 'dtr-employee', (string) $employee->getKey());
}

/** One date's DTR row, from its wire:key to the end of the row. */
function ndcRow(string $html, string $date): string
{
    return Str::before(Str::after($html, 'wire:key="dtr-'.$date.'"'), '</tr>');
}

/**
 * A pattern for a DTR time cell's time followed by the muted "next day" tag,
 * across the whitespace and Livewire's `<!--[if BLOCK]>` markers between them.
 */
function ndcTagged(string $time): string
{
    return '#<span>'.preg_quote($time, '#').'</span>(?:\s|<!--\[if (?:END)?BLOCK\]><!\[endif\]-->)*'
        .'<span class="whitespace-nowrap font-sans text-xs text-muted-foreground">next day</span>#';
}

/** Markup with every run of whitespace collapsed, for sentences Blade wraps. */
function ndcSquish(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', $html);
}

/**
 * The open dialog's detached view.
 *
 * @param  Testable<EdtrPage>  $page
 */
function ndcCorrection(Testable $page): DayCorrectionView
{
    $view = $page->viewData('correction');
    assert($view instanceof DayCorrectionView);

    return $view;
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-09-28T12:00:00+08:00'));
});

/* ── a save never takes or duplicates another date's punches ─────────────── */

it('keeps back-to-back nights intact whichever of them is corrected first', function (array $order) {
    $employee = ndcEmployee();
    ndcShift($employee);
    $actor = ndcActor(['EDTR'], allData: true);
    $id = (string) $employee->getKey();

    // Entered by hand, then saved again unchanged with a remark — a save
    // that must never delete the night before's 6:00 AM Time Out.
    foreach ([null, 'Night shift'] as $note) {
        foreach ($order as $date) {
            CorrectPunches::apply($actor, $id, $date, ndcSlots('22:00', null, null, '06:00'), ndcTicks(false, false, false, true), $note);
        }
    }

    expect(ndcStamps($employee))->toBe([
        'TIME_IN 2026-09-23T22:00',
        'TIME_OUT 2026-09-24T06:00',
        'TIME_IN 2026-09-24T22:00',
        'TIME_OUT 2026-09-25T06:00',
    ]);

    $days = ndcDays($actor, $employee, '2026-09-23', '2026-09-24');
    foreach (['2026-09-23' => '2026-09-24', '2026-09-24' => '2026-09-25'] as $night => $morning) {
        expect($days[$night]->status)->toBe(DayRecord::PRESENT)
            ->and(ndcMinute($days[$night]->timeIn))->toBe("{$night}T22:00")
            ->and(ndcMinute($days[$night]->timeOut))->toBe("{$morning}T06:00");
    }

    // The second pass found each night exactly as saved.
    $audits = ndcAudits();
    expect($audits)->toHaveCount(4)
        ->and(array_values(array_filter($audits, static fn (string $d): bool => str_contains($d, 'kept 2 as recorded'))))->toHaveCount(2);
})->with([
    'the earlier night first' => [['2026-09-23', '2026-09-24']],
    'the later night first' => [['2026-09-24', '2026-09-23']],
]);

it('keeps a night\'s Time Out when the rest day after it is saved', function (bool $clear, string $note) {
    $employee = ndcEmployee();
    ndcShift($employee);
    // Friday night at the station; Saturday is a rest day.
    $in = ndcPunch($employee, 'TIME_IN', '2026-09-25T22:00:04');
    $out = ndcPunch($employee, 'TIME_OUT', '2026-09-26T06:00:41');
    $actor = ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)
        ->call('openCorrection', '2026-09-26')
        ->assertSet('correctionForm.time_in', '')
        ->assertSet('correctionForm.time_out', '')
        ->assertSet('correctionNextDay', PunchSlots::SAME_DAY);
    if ($clear) {
        $page->call('clearAllSlots');
    }
    $page->set('correctionForm.note', $note)
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->assertSet('correctionOpen', false);

    expect(AttendanceLog::query()->whereKey($in->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->whereKey($out->getKey())->exists())->toBeTrue()
        ->and(ndcStamps($employee))->toHaveCount(2);

    $days = ndcDays($actor, $employee, '2026-09-25', '2026-09-26');
    expect($days['2026-09-25']->status)->toBe(DayRecord::PRESENT)
        ->and(ndcMinute($days['2026-09-25']->timeOut))->toBe('2026-09-26T06:00')
        ->and($days['2026-09-26']->status)->toBe(DayRecord::EXCUSED);

    expect(ndcAudits())->toHaveCount(1)
        ->and(ndcAudits()[0])->toContain('2026-09-26 · was no punches → now no punches');
    expect(AttendanceRemark::query()->count())->toBe($note === '' ? 0 : 1);
})->with([
    'cleared' => [true, ''],
    'given a remark' => [false, 'Rest day'],
]);

it('saves the break Night Shift unchanged twice through the dialog without duplicating a punch', function () {
    $employee = ndcEmployee();
    ndcBreakNight($employee);
    $ids = [
        (string) ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:12')->getKey(),
        (string) ndcPunch($employee, 'TIME_OUT', '2026-09-25T02:00:30')->getKey(),
        (string) ndcPunch($employee, 'TIME_IN', '2026-09-25T03:00:05')->getKey(),
        (string) ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:50')->getKey(),
    ];
    ndcActor(['EDTR'], allData: true);

    foreach ([1, 2] as $pass) {
        ndcPage($employee)
            ->call('openCorrection', '2026-09-24')
            ->assertSet('correctionForm.time_in', '22:00')
            ->assertSet('correctionForm.break_out', '02:00')
            ->assertSet('correctionForm.break_in', '03:00')
            ->assertSet('correctionForm.time_out', '06:00')
            ->assertSet('correctionNextDay', ndcTicks(false, true, true, true))
            ->call('saveCorrection')
            ->assertSet('correctionError', null)
            ->assertSet('correctionOpen', false);
    }

    expect(AttendanceLog::query()->where('employee_id', (string) $employee->getKey())->pluck('id')->sort()->values()->all())
        ->toBe(collect($ids)->sort()->values()->all())
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(0);

    $audits = ndcAudits();
    expect($audits)->toHaveCount(2)
        ->and($audits[0])->toContain('kept 4 as recorded')
        ->and($audits[1])->toContain('kept 4 as recorded');
});

/* ── the Next day tick ───────────────────────────────────────────────────── */

it('writes an after-midnight first Time In onto the next morning and reads it back ticked', function () {
    $employee = ndcEmployee();
    ndcBreakNight($employee);
    ndcActor(['EDTR'], allData: true);

    ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->set('correctionForm.time_in', '00:30')
        ->set('correctionForm.break_out', '02:00')
        ->set('correctionForm.break_in', '03:00')
        ->set('correctionForm.time_out', '06:00')
        ->set('correctionNextDay.time_in', true)
        ->set('correctionNextDay.break_out', true)
        ->set('correctionNextDay.break_in', true)
        ->set('correctionNextDay.time_out', true)
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->assertSet('correctionOpen', false);

    // Every punch on the morning after the row date — not the row date's own.
    expect(ndcStamps($employee))->toBe([
        'TIME_IN 2026-09-25T00:30',
        'TIME_OUT 2026-09-25T02:00',
        'TIME_IN 2026-09-25T03:00',
        'TIME_OUT 2026-09-25T06:00',
    ]);

    // Opened again, the dialog reads back exactly what was saved.
    $page = ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->assertSet('correctionForm.time_in', '00:30')
        ->assertSet('correctionForm.time_out', '06:00')
        ->assertSet('correctionNextDay', ndcTicks(true, true, true, true));

    // The table shows it on the night's row, tagged; the morning's row does not show it.
    $html = $page->html();
    expect(ndcRow($html, '2026-09-24'))
        ->toMatch(ndcTagged('12:30 AM'))
        ->and(ndcRow($html, '2026-09-25'))->not->toContain('12:30 AM');
});

it('accepts a flexible night whose Time Out is ticked Next day', function () {
    $employee = ndcEmployee();
    // Flexible rows store 08:00–17:00 filler: its clock never reads as overnight.
    ndcShift($employee, ['mode' => 'FLEXIBLE', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'required_hours' => 8]);
    $actor = ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)->call('openCorrection', '2026-09-24');
    expect(ndcSquish($page->html()))->toContain('Tick Next day beside any time after midnight.');

    $page->set('correctionForm.time_in', '22:00')
        ->set('correctionForm.time_out', '06:00')
        ->set('correctionNextDay.time_out', true)
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->assertSet('correctionOpen', false);

    expect(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00', 'TIME_OUT 2026-09-25T06:00']);
    $days = ndcDays($actor, $employee, '2026-09-24', '2026-09-25');
    expect($days['2026-09-24']->status)->toBe(DayRecord::PRESENT)
        ->and(ndcMinute($days['2026-09-24']->timeOut))->toBe('2026-09-25T06:00')
        ->and($days['2026-09-25']->timeIn)->toBeNull()
        ->and($days['2026-09-25']->timeOut)->toBeNull();
});

it('refuses a Next day tick on a shift that ends the same day, writing nothing', function (bool $rostered, string $message) {
    $employee = ndcEmployee();
    if ($rostered) {
        ndcShift($employee, ['start_time' => '10:00:00', 'end_time' => '17:00:00', 'grace_minutes' => 10]);
    }
    $ids = [
        (string) ndcPunch($employee, 'TIME_IN', '2026-09-24T10:00:00')->getKey(),
        (string) ndcPunch($employee, 'TIME_OUT', '2026-09-24T17:00:00')->getKey(),
    ];
    ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)->call('openCorrection', '2026-09-24');
    expect(ndcSquish($page->html()))->toContain('This shift ends the same day. A time after midnight needs an overnight shift in Shift Roster first.');

    $page->set('correctionForm.time_in', '22:00')
        ->set('correctionForm.time_out', '06:00')
        ->set('correctionNextDay.time_out', true)
        ->call('saveCorrection')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctionError', $message);

    expect(AttendanceLog::query()->pluck('id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all())
        ->and(ndcAudits())->toBe([]);
})->with([
    'a fixed day shift' => [true, 'The shift on Sep 24, 2026 (10:00 AM – 5:00 PM) ends the same day, so a Next day time would show on Sep 25, 2026 instead. Assign an overnight shift in Shift Roster first.'],
    'no shift in Shift Roster' => [false, 'This employee has no shift in Shift Roster, and the default 8:00 AM – 5:00 PM day ends the same day, so a Next day time would show on Sep 25, 2026 instead. Assign an overnight shift in Shift Roster first.'],
]);

it('refuses a Time Out earlier than the Time In when Next day is not ticked', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcActor(['EDTR'], allData: true);

    ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->set('correctionForm.time_in', '22:00')
        ->set('correctionForm.time_out', '06:00')
        ->call('saveCorrection')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctionError', 'Final Time Out must be later than First Time In. If it was after midnight, tick Next day beside it.');

    expect(ndcStamps($employee))->toBe([])
        ->and(ndcAudits())->toBe([]);
});

it('refuses a night left on an open next-day Time In and keeps the rest day after it EXCUSED', function () {
    $employee = ndcEmployee();
    ndcBreakNight($employee);
    $actor = ndcActor(['EDTR'], allData: true);

    ndcPage($employee)
        ->call('openCorrection', '2026-09-25')
        ->set('correctionForm.time_in', '22:00')
        ->set('correctionForm.break_out', '02:00')
        ->set('correctionForm.break_in', '03:00')
        ->set('correctionNextDay.break_out', true)
        ->set('correctionNextDay.break_in', true)
        ->call('saveCorrection')
        ->assertSet('correctionOpen', true)
        ->assertSet('correctionError', 'Record the Final Time Out too — without it the DTR reads Second Time In 3:00 AM next day as the Sep 26, 2026 record\'s Time In.');

    expect(ndcStamps($employee))->toBe([])
        ->and(ndcAudits())->toBe([])
        ->and(ndcDays($actor, $employee, '2026-09-25', '2026-09-26')['2026-09-26']->status)->toBe(DayRecord::EXCUSED);

    // Why: the same three punches, recorded at the station, turn Saturday INCOMPLETE.
    ndcPunch($employee, 'TIME_IN', '2026-09-25T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-26T02:00:00');
    ndcPunch($employee, 'TIME_IN', '2026-09-26T03:00:00');
    expect(ndcDays($actor, $employee, '2026-09-25', '2026-09-26')['2026-09-26']->status)->toBe(DayRecord::INCOMPLETE);
});

/* ── what the dialog lists and prefills ──────────────────────────────────── */

it('names a Time In the table borrows from an unfinished previous night and leaves its slot blank', function () {
    $employee = ndcEmployee();
    ndcBreakNight($employee);
    // Thursday's night at the station, never given its Final Time Out.
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T02:00:00');
    $borrowed = ndcPunch($employee, 'TIME_IN', '2026-09-25T03:00:00');
    ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)
        ->call('openCorrection', '2026-09-25')
        ->assertSet('correctionForm.time_in', '')
        ->assertSet('correctionForm.time_out', '')
        ->assertSet('correctionNextDay', PunchSlots::SAME_DAY);

    $correction = ndcCorrection($page);
    expect(array_keys($correction->borrowed))->toBe(['time_in'])
        ->and(ndcMinute($correction->borrowed['time_in']))->toBe('2026-09-25T03:00')
        ->and($correction->punches)->toBe([])
        ->and($correction->previous_day_punches)->toBe(2);

    $html = $page->html();
    // The table shows it on Friday's row, as that row's own calendar day…
    expect(ndcRow($html, '2026-09-25'))->toContain('<span>3:00 AM</span>')->not->toContain('next day');
    // …and the dialog says whose it is instead of offering it for replacement.
    expect(ndcSquish($html))
        ->toContain('The DTR shows 3:00 AM as this date\'s First Time In, but that punch belongs to the Sep 24, 2026 record, which has no Final Time Out. Correct that date first — it is left out of the boxes below.')
        ->toContain('2 punches on this date belong to the Sep 24, 2026 record. Open that day to change them.');

    // Saving the blank Friday leaves Thursday's punches where they are.
    $page->call('saveCorrection')->assertSet('correctionError', null);
    expect(AttendanceLog::query()->whereKey($borrowed->getKey())->exists())->toBeTrue()
        ->and(ndcStamps($employee))->toHaveCount(3);
});

it('tags an owned punch that no DTR slot shows', function () {
    $employee = ndcEmployee();
    // Not rostered: the default 08:00–17:00 day. A double tap at the station.
    $first = ndcPunch($employee, 'TIME_IN', '2026-09-24T08:00:10');
    $double = ndcPunch($employee, 'TIME_IN', '2026-09-24T08:00:40');
    $out = ndcPunch($employee, 'TIME_OUT', '2026-09-24T17:00:00');
    ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)->call('openCorrection', '2026-09-24');

    $inSlot = [];
    foreach (ndcCorrection($page)->punches as $punch) {
        $inSlot[$punch->id] = $punch->in_dtr_slot;
    }
    expect($inSlot)->toBe([
        (string) $first->getKey() => true,
        (string) $double->getKey() => false,
        (string) $out->getKey() => true,
    ]);

    $tag = 'not in a DTR slot — a save removes it unless entered';
    $dialog = Str::after($page->html(), 'Recorded punches');
    expect(substr_count($dialog, $tag))->toBe(1)
        ->and(Str::before(Str::after($dialog, 'wire:key="correct-punch-'.$double->getKey().'"'), '</tr>'))->toContain($tag);
});

it('lists only the row\'s own punches and points at the night before for the rest', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-23T22:00:00');
    $previousOut = ndcPunch($employee, 'TIME_OUT', '2026-09-24T06:00:00');
    $in = ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    $out = ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    ndcActor(['EDTR'], allData: true);

    $page = ndcPage($employee)->call('openCorrection', '2026-09-24');

    $correction = ndcCorrection($page);
    expect(array_map(static fn (CorrectionPunchView $p): string => $p->id, $correction->punches))
        ->toBe([(string) $in->getKey(), (string) $out->getKey()])
        ->and(array_map(static fn (CorrectionPunchView $p): bool => $p->is_carried, $correction->punches))->toBe([false, true])
        ->and($correction->previous_day_punches)->toBe(1);

    $html = $page->html();
    $dialog = Str::after($html, 'Recorded punches');
    expect($dialog)->not->toContain('wire:key="correct-punch-'.$previousOut->getKey().'"')
        ->and($dialog)->toContain('data-previous-day-punches')
        ->and(ndcSquish($dialog))->toContain('1 punch on this date belongs to the Sep 23, 2026 record. Open that day to change it.');
});

it('prefills the Next day ticks from the saved punches and unticks them on Clear all', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    ndcActor(['EDTR'], allData: true);

    // There is no clear per slot: a slot emptied by hand keeps its tick,
    // which counts for nothing beside an empty box
    // (PunchSlotsTest, 'ignores a tick beside an empty slot').
    ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->assertSet('correctionForm.time_out', '06:00')
        ->assertSet('correctionNextDay', ndcTicks(false, false, false, true))
        ->set('correctionNextDay.time_in', true)
        ->call('clearAllSlots')
        ->assertSet('correctionForm.time_in', '')
        ->assertSet('correctionForm.time_out', '')
        ->assertSet('correctionNextDay', PunchSlots::SAME_DAY);

    expect(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00', 'TIME_OUT 2026-09-25T06:00'])
        ->and(ndcAudits())->toBe([]);
});

it('saves a slot emptied by hand as empty, whatever its Next day tick still says', function () {
    // With no clear per slot, deleting a typed time leaves its tick set. Only
    // a tick beside a filled box counts (PunchSlots::nextDayFlags), so the
    // save drops that punch and writes nothing on the next day.
    $employee = ndcEmployee();
    ndcShift($employee);
    $in = ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    $out = ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    ndcActor(['EDTR'], allData: true);

    ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->set('correctionForm.time_out', '')
        ->assertSet('correctionNextDay.time_out', true)
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->assertSet('correctionOpen', false);

    expect(AttendanceLog::query()->whereKey($in->getKey())->exists())->toBeTrue()
        ->and(AttendanceLog::query()->whereKey($out->getKey())->exists())->toBeFalse()
        ->and(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00']);
});

it('reads a forged tick as no tick at all', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcActor(['EDTR'], allData: true);

    // An earlier time with a forged tick is refused as earlier…
    $page = ndcPage($employee)
        ->call('openCorrection', '2026-09-24')
        ->set('correctionForm.time_in', '22:00')
        ->set('correctionForm.time_out', '06:00')
        ->set('correctionNextDay.time_out', 'yes')
        ->call('saveCorrection')
        ->assertSet('correctionError', 'Final Time Out must be later than First Time In. If it was after midnight, tick Next day beside it.');
    expect(ndcStamps($employee))->toBe([]);

    // …and a later one is written on the row date, never the next morning.
    $page->set('correctionForm.time_out', '23:30')
        ->call('saveCorrection')
        ->assertSet('correctionError', null)
        ->assertSet('correctionOpen', false);
    expect(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00', 'TIME_OUT 2026-09-24T23:30']);
});

/* ── who may correct ─────────────────────────────────────────────────────── */

it('corrects a night through the command alone for an all-data EDTR actor', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    $actor = ndcActor(['EDTR'], allData: true);

    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-24', ndcSlots('22:00', null, null, '06:00'), ndcTicks(false, false, false, true), null);

    expect(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00', 'TIME_OUT 2026-09-25T06:00'])
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(2)
        ->and(ndcAudits())->toHaveCount(1)
        ->and(ndcDays($actor, $employee, '2026-09-24', '2026-09-24')['2026-09-24']->status)->toBe(DayRecord::PRESENT);
});

it('refuses the command to an actor without all-data EDTR, with no effect', function (string $module, bool $allData) {
    $employee = ndcEmployee();
    ndcShift($employee);
    $in = ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    $out = ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    $actor = ndcActor([$module], $allData);

    expect(fn () => CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-24', ndcSlots('21:00', null, null, '07:00'), ndcTicks(false, false, false, true), 'Forged'))
        ->toThrow(RuntimeException::class, 'You are not allowed to correct punches.');

    expect(AttendanceLog::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([(string) $in->getKey(), (string) $out->getKey()])->sort()->values()->all())
        ->and(ndcAudits())->toBe([])
        ->and(AttendanceRemark::query()->count())->toBe(0);
})->with([
    'EDTR without all-data' => ['EDTR', false],
    'all-data without EDTR' => ['PORTAL', true],
]);

it('gives a self-only viewer\'s saveCorrection no effect', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    ndcActor(['EDTR'], allData: false, employeeId: (string) $employee->getKey());

    Livewire::test(EdtrPage::class)
        ->set('tab', 'dtr')
        ->call('openCorrection', '2026-09-24')
        ->assertSet('correctionOpen', false)
        ->set('correctionForm.time_in', '21:00')
        ->set('correctionForm.time_out', '07:00')
        ->set('correctionNextDay.time_out', true)
        ->call('saveCorrection');

    expect(ndcStamps($employee))->toBe(['TIME_IN 2026-09-24T22:00', 'TIME_OUT 2026-09-25T06:00'])
        ->and(AttendanceLog::query()->where('source', AttendanceLog::SOURCE_CORRECTION)->count())->toBe(0)
        ->and(ndcAudits())->toBe([]);
});

/* ── the DTR table and the audit sentence ────────────────────────────────── */

it('tags only the next-day times in the DTR table, on the row that owns them', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-23T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-24T06:00:00');
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:00:00');
    ndcActor(['EDTR'], allData: true);

    $html = ndcPage($employee)->html();

    foreach (['2026-09-23', '2026-09-24'] as $night) {
        $row = ndcRow($html, $night);
        // Eleven cells and no OT approved column; five of them are numeric.
        expect(substr_count($row, '<td'))->toBe(11);
        preg_match_all('/<td class="num text-right ?([a-z-]*)">([^<]*)</', $row, $cells, PREG_SET_ORDER);
        expect($cells)->toHaveCount(5);

        // One tag, beside the Time Out; the night's own Time In has none.
        expect($row)->toContain('>10:00 PM<')
            ->toContain('>6:00 AM<')
            ->toMatch(ndcTagged('6:00 AM'))
            ->not->toMatch(ndcTagged('10:00 PM'))
            ->and(substr_count($row, '>next day<'))->toBe(1);
    }

    // The morning the last night ends on shows nothing of it.
    $friday = ndcRow($html, '2026-09-25');
    expect(substr_count($friday, '<td'))->toBe(11)
        ->and($friday)->not->toContain('6:00 AM')
        ->not->toContain('next day');
});

it('says next day in the audit sentence of a night\'s Time Out', function () {
    $employee = ndcEmployee();
    ndcShift($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:12');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T06:03:40');
    $actor = ndcActor(['EDTR'], allData: true);

    CorrectPunches::apply($actor, (string) $employee->getKey(), '2026-09-24', ndcSlots('22:00', null, null, '06:00'), ndcTicks(false, false, false, true), null);

    expect(ndcAudits())->toHaveCount(1)
        ->and(ndcAudits()[0])->toContain(
            'Liza Domingo · 2026-09-24 · was TIME_IN 10:00:12 PM, TIME_OUT 6:03:40 AM next day'
            .' → now TIME_IN 10:00:12 PM, TIME_OUT 6:00:00 AM next day · kept 1 as recorded'
        );
});

/* ── the dialog's layout ─────────────────────────────────────────────────── */

it('draws the Next day ticks only where the shift can hold one, or while one is still set', function () {
    $night = ndcEmployee();
    ndcShift($night);
    $day = ndcEmployee('Rosa', 'Lim');
    ndcShift($day, ['start_time' => '08:00:00', 'end_time' => '17:00:00']);
    ndcActor(['EDTR'], allData: true);

    $ticks = static fn (string $html): int => substr_count($html, 'wire:model="correctionNextDay.');

    expect($ticks(ndcPage($night)->call('openCorrection', '2026-09-24')->html()))->toBe(4)
        ->and($ticks(ndcPage($day)->call('openCorrection', '2026-09-24')->html()))->toBe(0);

    // A tick still set on a same-day shift keeps the ticks, so it can be
    // unticked. Beside an empty box it counts for nothing, so nothing warns.
    $idle = ndcPage($day)->call('openCorrection', '2026-09-24')->set('correctionNextDay.time_out', true)->html();
    expect($ticks($idle))->toBe(4)
        ->and(ndcSquish($idle))->not->toContain('Untick Next day before you save.');

    // Beside a filled box it is the tick CorrectPunches refuses, and the dialog says so first.
    $stale = ndcPage($day)->call('openCorrection', '2026-09-24')
        ->set('correctionForm.time_out', '06:00')
        ->set('correctionNextDay.time_out', true);
    expect($ticks($stale->html()))->toBe(4)
        ->and(ndcSquish($stale->html()))->toContain('This shift ends the same day. A time after midnight needs an overnight shift in Shift Roster first. Untick Next day before you save.')
        ->and((string) $stale->call('saveCorrection')->get('correctionError'))->toContain('Assign an overnight shift in Shift Roster first.')
        ->and(AttendanceLog::query()->where('employee_id', (string) $day->getKey())->count())->toBe(0)
        ->and(ndcAudits())->toBeEmpty();
});

it('names an unslotted punch above the boxes and keeps the anchor before the rows and notes', function () {
    $employee = ndcEmployee();
    ndcPunch($employee, 'TIME_IN', '2026-09-24T08:00:10');
    ndcPunch($employee, 'TIME_IN', '2026-09-24T08:00:40');
    ndcPunch($employee, 'TIME_OUT', '2026-09-24T17:00:00');
    ndcActor(['EDTR'], allData: true);

    $html = ndcPage($employee)->call('openCorrection', '2026-09-24')->html();

    expect(ndcSquish(Str::before($html, 'Recorded punches')))
        ->toContain('1 recorded punch (8:00 AM) is in none of the slots below.')
        ->and(substr_count($html, 'Recorded punches'))->toBe(1)
        // The list opens by itself when a punch sits in no slot.
        ->and($html)->toContain('data-off-slot="1"');
});

it('keeps the anchor on a day with no punches of its own', function () {
    $employee = ndcEmployee();
    ndcBreakNight($employee);
    ndcPunch($employee, 'TIME_IN', '2026-09-24T22:00:00');
    ndcPunch($employee, 'TIME_OUT', '2026-09-25T02:00:00');
    ndcPunch($employee, 'TIME_IN', '2026-09-25T03:00:00');
    ndcActor(['EDTR'], allData: true);

    $html = ndcPage($employee)->call('openCorrection', '2026-09-25')->html();

    expect(substr_count($html, 'Recorded punches'))->toBe(1)
        ->and(Str::after($html, 'Recorded punches'))->toContain('data-previous-day-punches')
        ->and(Str::before($html, 'Recorded punches'))->toContain('it is left out of the boxes below');
});
