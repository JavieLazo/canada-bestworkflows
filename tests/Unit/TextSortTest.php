<?php

declare(strict_types=1);

use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Employees\EmployeeFields;
use App\Livewire\Leaves\LeaveLabels;
use App\Livewire\Payroll\Support\PayrollData;
use App\Livewire\Projects\ProjectsData;
use App\Livewire\Records\Support\Hris as RecordsHris;
use App\Livewire\Roster\Hris as RosterHris;
use App\Livewire\Tasks\MyTasksPage;
use App\Support\TextSort;

/**
 * Every sorted table in the app orders text through ONE comparator, and that
 * comparator is the same on every host.
 *
 * It was eight copies of useSort.ts `compareText`, and two of them disagreed:
 * My Tasks broke ties between names differing only in case, the other seven
 * did not; Employee Records reached for ICU `Collator` when ext-intl happened
 * to be installed and fell back to `strcasecmp` when it was not, so the list
 * order changed with a PHP extension the shared host controls. No test pinned
 * any ordering, so the suite stayed green either way.
 *
 * These pin the canonical order — blanks last, case-insensitive primary,
 * case-sensitive tiebreak, byte order beyond ASCII — and that all eight module
 * wrappers still forward to it.
 */

/* |---| The canonical order |---| */

it('sorts blanks last in an ascending pass, whichever side they are on', function () {
    expect(TextSort::compare('', 'Abad'))->toBe(1)
        ->and(TextSort::compare(null, 'Abad'))->toBe(1)
        ->and(TextSort::compare('   ', 'Abad'))->toBe(1)
        ->and(TextSort::compare('Abad', ''))->toBe(-1)
        ->and(TextSort::compare('Abad', null))->toBe(-1)
        ->and(TextSort::compare('Abad', "\t"))->toBe(-1);
});

it('treats two blanks as equal, so a stable sort keeps their input order', function () {
    expect(TextSort::compare('', ''))->toBe(0)
        ->and(TextSort::compare(null, null))->toBe(0)
        ->and(TextSort::compare(null, ' '))->toBe(0)
        ->and(TextSort::compare("\n", ''))->toBe(0);
});

it('compares case-insensitively first', function () {
    expect(TextSort::compare('abad', 'Bautista'))->toBeLessThan(0)
        ->and(TextSort::compare('Bautista', 'abad'))->toBeGreaterThan(0)
        ->and(TextSort::compare('ZAMORA', 'santos'))->toBeGreaterThan(0)
        ->and(TextSort::compare('santos', 'ZAMORA'))->toBeLessThan(0);
});

it('breaks a case-insensitive tie by byte order, uppercase first', function () {
    // "Dela Cruz" and "dela cruz" are the same name folded; the tiebreak keeps
    // them in one deterministic order rather than whichever the sort happens
    // to visit first. Uppercase sorts first because its bytes are smaller.
    expect(TextSort::compare('Dela Cruz', 'dela cruz'))->toBeLessThan(0)
        ->and(TextSort::compare('dela cruz', 'Dela Cruz'))->toBeGreaterThan(0)
        ->and(TextSort::compare('DELA CRUZ', 'Dela Cruz'))->toBeLessThan(0);
});

it('returns 0 only for identical text', function () {
    expect(TextSort::compare('Dela Cruz', 'Dela Cruz'))->toBe(0)
        ->and(TextSort::compare(' Dela Cruz ', 'Dela Cruz'))->toBe(0)
        ->and(TextSort::compare('Dela Cruz', 'dela cruz'))->not->toBe(0);
});

it('never reaches for a locale — order is byte order beyond ASCII', function () {
    // The ICU Collator branch that Employee Records had would put Álvarez
    // before Bautista. The canonical order is host-independent: UTF-8 bytes
    // above 0x7F sort after every ASCII letter, on every host, with or
    // without ext-intl.
    expect(TextSort::compare('Álvarez', 'zamora'))->toBeGreaterThan(0)
        ->and(TextSort::compare('Ñuñez', 'Nunez'))->toBeGreaterThan(0);
});

it('orders a fixed list of ten names the same way every time', function () {
    $names = [
        'Dela Cruz',
        'dela cruz',
        'Abad',
        'zamora',
        null,
        'Bautista',
        'Álvarez',
        ' ',
        'de la Cruz',
        'Santos',
    ];

    usort($names, TextSort::compare(...));

    expect($names)->toBe([
        'Abad',
        'Bautista',
        'de la Cruz',   // "de " < "del": the space byte is below every letter
        'Dela Cruz',    // tie with "dela cruz" on the fold, uppercase first
        'dela cruz',
        'Santos',
        'zamora',
        'Álvarez',      // non-ASCII bytes sort after z
        null,           // the two blanks are last, in input order (usort is stable)
        ' ',
    ]);
});

it('reverses cleanly, so a screen that flips the array for desc puts blanks first', function () {
    // Every list screen sorts ascending and then reverses the whole array for
    // descending, exactly as the source did — blanks land FIRST on desc. The
    // comparator itself never knows the direction.
    $names = ['Bautista', null, 'Abad'];
    usort($names, TextSort::compare(...));

    expect(array_reverse($names))->toBe([null, 'Bautista', 'Abad']);
});

/* |---| Every module wrapper forwards to it |---| */

/**
 * The eight `compareText` wrappers, callable by name. Audit and My Tasks keep
 * their compatibility wrappers non-public, so reflection reaches them; the
 * rest are the public static helpers the module
 * screens and Blade views already call.
 *
 * @return array<string, callable(?string, ?string): int>
 */
function textsortWrappers(): array
{
    $private = static function (string $class): Closure {
        $method = new ReflectionMethod($class, 'compareText');

        return static fn (?string $a, ?string $b): int => (int) $method->invoke(null, $a, $b);
    };

    return [
        'Audit' => $private(AuditIndex::class),
        'Leaves' => LeaveLabels::compareText(...),
        'Payroll' => PayrollData::compareText(...),
        'Records' => RecordsHris::compareText(...),
        'Roster' => RosterHris::compareText(...),
        'Projects' => ProjectsData::compareText(...),
        'Tasks' => $private(MyTasksPage::class),
        'Employees' => EmployeeFields::compareText(...),
    ];
}

it('sorts identically in every module', function () {
    // The pairs that told the eight copies apart: a case-only tie (seven of
    // them returned 0, My Tasks did not), an accented name (Employee Records
    // answered differently with and without ext-intl), and the blank preamble.
    $pairs = [
        ['Dela Cruz', 'dela cruz'],
        ['dela cruz', 'Dela Cruz'],
        ['Álvarez', 'Bautista'],
        ['abad', 'Bautista'],
        ['Bautista', 'abad'],
        ['', 'Abad'],
        ['Abad', null],
        [null, ''],
        ['Same', 'Same'],
    ];

    $wrappers = textsortWrappers();
    expect($wrappers)->toHaveCount(8);

    foreach ($pairs as [$a, $b]) {
        $expected = TextSort::compare($a, $b);
        foreach ($wrappers as $module => $compare) {
            expect($compare($a, $b))->toBe($expected, "{$module}::compareText diverged from TextSort::compare on [".var_export($a, true).', '.var_export($b, true).']');
        }
    }
});
