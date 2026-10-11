<?php

declare(strict_types=1);

use App\Livewire\Edtr\Support\Hris as EdtrHris;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Leaves\LeaveLabels;
use App\Livewire\Payroll\Support\PayrollData;
use App\Livewire\Portal\Support\PortalLabels;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Records\Support\Hris as RecordsHris;
use App\Livewire\Roster\Hris as RosterHris;
use App\Livewire\Tasks\MyTasksPage;
use App\Modules\Timekeeping\Contracts\Views\ShiftTypeView;
use App\Modules\Timekeeping\Contracts\Views\ShiftView;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;
use App\Support\HrisFormat;
use App\Support\ManilaClock;
use Carbon\CarbonImmutable;

/**
 * HrisFormat is the ONE copy of the hris.ts / attachments.ts formatting slice,
 * and every module class's formatting wrapper forwards to it.
 *
 * The first half pins the shared behaviour against the TypeScript. The second
 * half calls every wrapper and expects the shared answer, so a module method
 * that stops forwarding cannot drift unnoticed — to an unpadded day in
 * `PayrollData::fmtDate`, say, or a thousands separator in a `humanSize` copy.
 *
 * `hoursPerDay()` is the one spelling of a FLEXIBLE pattern's daily hours;
 * the roster's Hours cell and dialog summary forward to it so they cannot
 * drift from the audit sentence the Timekeeping command writes.
 *
 * `requestTypeMatches()` is the one body of the `_LEAVE` tolerance;
 * `requestDescription()`'s loop and leave credits
 * (`LeaveCreditPolicy::matches`) both forward to it, so a request is counted
 * against a credit by the same rule that labels it.
 *
 * Pure PHP; no container, so this runs under tests/Unit.
 */

/* |---| The shared behaviour |---| */

it('formats a stored clock time as a 12-hour label', function (?string $stored, string $label) {
    expect(HrisFormat::hm($stored))->toBe($label);
})->with([
    'afternoon' => ['17:00', '5:00 PM'],
    'with seconds' => ['09:05:30', '9:05 AM'],
    'noon' => ['12:00', '12:00 PM'],
    'midnight' => ['00:15', '12:15 AM'],
    'padded' => [' 8:30 ', '8:30 AM'],
    'null' => [null, "\u{2014}"],
    'blank' => ['', "\u{2014}"],
    'unparseable' => ['noon', 'noon'],
    'hour past 23' => ['24:00', '24:00'],
]);

it('formats a calendar day with a two-digit day, from a key or a Carbon', function () {
    expect(HrisFormat::date('2026-08-05'))->toBe('Aug 05, 2026')
        ->and(HrisFormat::date('2026-08-05 00:00:00'))->toBe('Aug 05, 2026')
        ->and(HrisFormat::date(CarbonImmutable::parse('2026-12-25', 'UTC')))->toBe('Dec 25, 2026')
        ->and(HrisFormat::date(null))->toBe("\u{2014}")
        ->and(HrisFormat::date(''))->toBe("\u{2014}");
});

it('prints bytes the way toFixed(1) and Math.round do', function (int $bytes, string $label) {
    expect(HrisFormat::bytes($bytes))->toBe($label);
})->with([
    'zero is still one kilobyte' => [0, '1 KB'],
    'rounds half up' => [1_500, '2 KB'],
    'rounds down' => [1_499, '1 KB'],
    'just under a megabyte' => [999_999, '1000 KB'],
    'one megabyte' => [1_000_000, '1.0 MB'],
    'one decimal' => [1_234_567, '1.2 MB'],
    'no thousands separator' => [1_000_000_000, '1000.0 MB'],
]);

it('labels a built-in employment status and passes an HR-defined one through', function () {
    expect(HrisFormat::employmentStatus('REGULAR'))->toBe('Regular')
        ->and(HrisFormat::employmentStatus('JOB_ORDER'))->toBe('Job Order')
        ->and(HrisFormat::employmentStatus('Consultant'))->toBe('Consultant');
});

it('normalises a leave-type name to its stored token', function (string $name, string $token) {
    expect(HrisFormat::normalizeRequestType($name))->toBe($token);
})->with([
    'plain' => ['Sick Leave', 'SICK_LEAVE'],
    'padding and runs' => [' vacation--leave ', 'VACATION_LEAVE'],
    'slash' => ['Business Travel / OB', 'BUSINESS_TRAVEL_OB'],
    'already a token' => ['BUSINESS_TRAVEL', 'BUSINESS_TRAVEL'],
]);

it('describes a request type from the configured names, then the built-ins, then the token', function () {
    $names = ['Sick Leave', 'Paternity Leave'];

    expect(HrisFormat::requestDescription('SICK', $names))->toBe('Sick Leave')
        ->and(HrisFormat::requestDescription('PATERNITY', $names))->toBe('Paternity Leave')
        ->and(HrisFormat::requestDescription('PATERNITY_LEAVE', $names))->toBe('Paternity Leave')
        ->and(HrisFormat::requestDescription('BUSINESS_TRAVEL', $names))->toBe('Business Travel / OB')
        ->and(HrisFormat::requestDescription('VACATION'))->toBe('Vacation Leave')
        ->and(HrisFormat::requestDescription('EMERGENCY_LEAVE'))->toBe('Emergency Leave');
});

it('matches a stored token to a leave-type key exactly or through its _LEAVE spelling, and leave credits forward to it', function () {
    // The ONE body of requestDescription's tolerance: legacy rows hold SICK
    // where a filing today stores SICK_LEAVE, so the key SICK_LEAVE names both.
    expect(HrisFormat::requestTypeMatches('SICK_LEAVE', 'SICK'))->toBeTrue()
        ->and(HrisFormat::requestTypeMatches('SICK_LEAVE', 'SICK_LEAVE'))->toBeTrue()
        ->and(HrisFormat::requestTypeMatches('SICK', 'SICK'))->toBeTrue()
        ->and(HrisFormat::requestTypeMatches('SICK', 'SICK_LEAVE'))->toBeFalse()
        ->and(HrisFormat::requestTypeMatches('VACATION_LEAVE', 'SICK'))->toBeFalse()
        ->and(HrisFormat::requestTypeMatches('SICK_LEAVE', 'sick'))->toBeFalse()
        ->and(HrisFormat::requestTypeMatches('LEAVE_WITHOUT_PAY', 'LEAVE'))->toBeFalse();

    // requestDescription's loop calls it: a configured name wins with or without the suffix.
    expect(HrisFormat::requestDescription('SICK', ['Sick Leave']))->toBe('Sick Leave')
        ->and(HrisFormat::requestDescription('SICK_LEAVE', ['Sick Leave']))->toBe('Sick Leave')
        ->and(HrisFormat::requestDescription('SICK_LEAVE', ['Sick']))->toBe('Sick Leave');

    foreach ([
        ['SICK_LEAVE', 'SICK'], ['SICK_LEAVE', 'SICK_LEAVE'], ['SICK', 'SICK_LEAVE'],
        ['VACATION_LEAVE', 'VACATION'], ['EMERGENCY_LEAVE', 'SICK'], ['LEAVE_WITHOUT_PAY', 'LEAVE'],
    ] as [$key, $token]) {
        expect(LeaveCreditPolicy::matches($key, $token))
            ->toBe(HrisFormat::requestTypeMatches($key, $token), "LeaveCreditPolicy::matches({$key}, {$token}) does not forward");
    }
});

it('prints daily hours the way the reference template literal did', function (float|int|null $hours, string $label) {
    expect(HrisFormat::hoursPerDay($hours))->toBe($label);
})->with([
    'whole float drops its .0' => [8.0, '8 h per day'],
    'whole int' => [6, '6 h per day'],
    'half keeps its half' => [7.5, '7.5 h per day'],
    'null reads as zero' => [null, '0 h per day'],
    'zero' => [0.0, '0 h per day'],
]);

it('names a weekday short or long, 0 Sunday to 6 Saturday, and refuses one outside the week', function () {
    expect(HrisFormat::weekday(0))->toBe('Sun')
        ->and(HrisFormat::weekday(0, short: false))->toBe('Sunday')
        ->and(HrisFormat::weekday(1))->toBe('Mon')
        ->and(HrisFormat::weekday(1, short: false))->toBe('Monday')
        ->and(HrisFormat::weekday(3, short: false))->toBe('Wednesday')
        ->and(HrisFormat::weekday(6))->toBe('Sat')
        ->and(HrisFormat::weekday(6, short: false))->toBe('Saturday');

    expect(fn () => HrisFormat::weekday(7))->toThrow(InvalidArgumentException::class)
        ->and(fn () => HrisFormat::weekday(-1))->toThrow(InvalidArgumentException::class);
});

/* |---| Every module wrapper forwards |---| */

it('reads a flexible roster row as its daily hours and every other row as its clock', function () {
    $flexible = new ShiftView(['mode' => 'FLEXIBLE', 'required_hours' => 8.0, 'start_time' => '08:00:00', 'end_time' => '17:00:00']);
    $fixed = new ShiftView(['mode' => 'FIXED', 'required_hours' => null, 'start_time' => '09:00:00', 'end_time' => '18:00:00']);
    // A null mode is the engine's FIXED (DomainShift::isFlexible tests only MODE_FLEXIBLE).
    $unknown = new ShiftView(['mode' => null, 'required_hours' => 8.0, 'start_time' => '08:00:00', 'end_time' => '17:00:00']);

    expect(RosterHris::hoursOf($flexible))->toBe('Flexible · '.HrisFormat::hoursPerDay(8.0))
        ->and(RosterHris::hoursOf($flexible))->toBe('Flexible · 8 h per day')
        ->and(RosterHris::hoursOf($fixed))->toBe('9:00 AM–6:00 PM')
        ->and(RosterHris::hoursOf($unknown))->toBe('8:00 AM–5:00 PM')
        ->and(RosterHris::hoursOf(null))->toBe('—');
});

it('summarises a flexible pattern through hoursPerDay', function () {
    $pattern = new ShiftTypeView(['mode' => 'FLEXIBLE', 'required_hours' => 7.5, 'grace_minutes' => 0]);

    expect(RosterHris::patternSummary($pattern))->toBe(HrisFormat::hoursPerDay(7.5))
        ->and(RosterHris::patternSummary($pattern))->toBe('7.5 h per day');
});

it('formats the clock identically in Roster, Records and eDTR', function () {
    foreach (['17:00', '00:15', '', null, 'noon'] as $value) {
        $expected = HrisFormat::hm($value);

        expect(RosterHris::fmtHm($value))->toBe($expected)
            ->and(RecordsHris::fmtHm($value))->toBe($expected)
            ->and(EdtrHris::fmtHm($value))->toBe($expected);
    }
});

it('formats a day identically in eDTR, Portal, Records, Projects and Payroll', function () {
    $day = CarbonImmutable::parse('2026-08-05', 'UTC');

    $spellings = [
        'eDTR' => EdtrHris::fmtDate('2026-08-05'),
        'Portal' => PortalLabels::fmtDate('2026-08-05'),
        'Records' => RecordsHris::fmtDate($day),
        'Projects' => ProjectsData::fmtDate($day),
        'Payroll' => PayrollData::fmtDate($day),
        'Payroll key' => PayrollData::fmtDate('2026-08-05'),
    ];

    expect(array_unique(array_values($spellings)))->toBe(['Aug 05, 2026']);
});

it('spells a duration identically on the DTR and in the overtime audit and notices', function () {
    foreach ([0.0, 0.4, 45.0, 60.0, 150.0, 457.0, 1440.0] as $minutes) {
        $expected = HrisFormat::duration($minutes);

        expect(EdtrHris::fmtDuration($minutes))->toBe($expected)
            ->and(OvertimeApprovalPolicy::duration((int) $minutes))->toBe(HrisFormat::duration((float) (int) $minutes));
    }

    expect(HrisFormat::duration(457.0))->toBe('7h 37m')
        ->and(HrisFormat::duration(120.0))->toBe('2h')
        ->and(HrisFormat::duration(-45.0))->toBe('-45m')
        ->and(HrisFormat::duration(NAN))->toBe('0m');
});

it('prints a size identically in Employees, Projects and My Tasks', function () {
    foreach ([0, 1_500, 1_234_567, 1_000_000_000] as $bytes) {
        $expected = HrisFormat::bytes($bytes);

        expect(EmployeeFields::humanSize($bytes))->toBe($expected)
            ->and(ProjectsData::humanSize($bytes))->toBe($expected)
            ->and(MyTasksPage::humanSize($bytes))->toBe($expected);
    }
});

it('labels an employment status identically in Roster, eDTR and Employees', function () {
    foreach (['REGULAR', 'PROBATIONARY', 'Consultant'] as $value) {
        $expected = HrisFormat::employmentStatus($value);

        expect(RosterHris::employmentStatusLabel($value))->toBe($expected)
            ->and(EdtrHris::employmentStatusLabel($value))->toBe($expected)
            ->and(EmployeeFields::employmentStatusLabel($value))->toBe($expected);
    }
});

it('describes a request type identically in Records, eDTR, Portal and Leaves', function () {
    $names = ['Sick Leave', 'Paternity Leave'];
    $shaped = array_map(static fn (string $name): array => ['name' => $name], $names);

    foreach (['SICK', 'PATERNITY', 'BUSINESS_TRAVEL', 'EMERGENCY_LEAVE'] as $token) {
        $expected = HrisFormat::requestDescription($token, $names);

        expect(RecordsHris::requestDescription($token, $names))->toBe($expected)
            ->and(PortalLabels::requestDescription($token, $names))->toBe($expected)
            ->and(LeaveLabels::requestDescription($token, $names))->toBe($expected)
            ->and(EdtrHris::requestDescription($token, $shaped))->toBe($expected);
    }

    foreach ([' Sick  Leave ', 'Business Travel / OB'] as $name) {
        $expected = HrisFormat::normalizeRequestType($name);

        expect(RecordsHris::normalizeRequestType($name))->toBe($expected)
            ->and(PortalLabels::normalizeRequestType($name))->toBe($expected)
            ->and(LeaveLabels::normalizeRequestType($name))->toBe($expected)
            ->and(EdtrHris::normalizeRequestType($name))->toBe($expected);
    }
});

it('reads today from ManilaClock in every module', function () {
    // 20:00 UTC is already the 18th in Manila; a copy on the UTC clock would say the 17th.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 20:00:00', 'UTC'));

    try {
        expect(ManilaClock::todayKey())->toBe('2026-09-18');

        foreach ([
            'eDTR' => EdtrHris::todayKey(),
            'Employees' => EmployeeFields::todayKey(),
            'Payroll' => PayrollData::todayKey(),
            'Portal' => PortalLabels::todayKey(),
            'Projects' => ProjectsData::todayKey(),
            'Records' => RecordsHris::todayKey(),
        ] as $module => $key) {
            expect($key)->toBe('2026-09-18', "{$module} does not read today through ManilaClock");
        }
    } finally {
        CarbonImmutable::setTestNow();
    }
});
