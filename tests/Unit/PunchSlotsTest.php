<?php

declare(strict_types=1);

use App\Livewire\Edtr\Support\Hris;
use App\Modules\Timekeeping\Domain\Attendance\PunchSlots;

/**
 * PunchSlots — turning a corrected DTR row's four slots back into punches.
 * Each slot carries its own "Next day" tick and NOTHING is inferred from the
 * order of the times (the deliberate divergence from the TS reference, which
 * rolls an earlier time a day forward):
 *
 *   - a ticked slot is stamped on the next calendar day, as an offset-less
 *     Manila wall-clock stamp; an unticked one stays on the corrected date;
 *   - a tick counts only as a literal `true` beside a FILLED slot — the
 *     ticks are client state and untrusted;
 *   - once the ticks apply, every slot is STRICTLY later than the one before
 *     it, and the message says whether to tick Next day or to assign an
 *     overnight shift first;
 *   - the 20-hour ceiling is measured after the ticks;
 *   - the time-format, middle-pair and time-in-first messages are the
 *     reference's own and do not read the ticks.
 *
 * isNextDay() is THE definition of "next day" (the table tag, the prefilled
 * ticks and the audit sentence), read on the Manila calendar day, and the
 * Edtr Hris helper forwards to it.
 */

/**
 * @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}
 */
function pslotSlots(?string $in = null, ?string $breakOut = null, ?string $breakIn = null, ?string $out = null): array
{
    return ['time_in' => $in, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $out];
}

/**
 * @return array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}
 */
function pslotTicks(bool $in = false, bool $breakOut = false, bool $breakIn = false, bool $out = false): array
{
    return ['time_in' => $in, 'break_out' => $breakOut, 'break_in' => $breakIn, 'time_out' => $out];
}

/**
 * A dataset row's four slots as one string, `|` between slots and `-` for an
 * empty one: `'22:00|02:00|03:00|06:00'`, `'08:00|-|-|17:00'`.
 *
 * @return array{time_in: ?string, break_out: ?string, break_in: ?string, time_out: ?string}
 */
function pslotRow(string $row): array
{
    [$in, $breakOut, $breakIn, $out] = array_pad(array_map(
        static fn (string $cell): ?string => $cell === '-' ? null : $cell,
        explode('|', $row),
    ), 4, null);

    return pslotSlots($in, $breakOut, $breakIn, $out);
}

/**
 * A dataset row's four ticks as one string, `T` for ticked: `'FTTT'`.
 *
 * @return array{time_in: bool, break_out: bool, break_in: bool, time_out: bool}
 */
function pslotTickRow(string $ticks): array
{
    $t = array_pad(str_split($ticks), 4, 'F');

    return pslotTicks($t[0] === 'T', $t[1] === 'T', $t[2] === 'T', $t[3] === 'T');
}

/** The refusal's first sentence plus the tick hint a shift that may cross midnight gets. */
function pslotAllowed(string $sentence): string
{
    return $sentence.' If it was after midnight, tick Next day beside it.';
}

/** The refusal's first sentence plus the Shift Roster pointer a same-day shift gets. */
function pslotRefused(string $sentence): string
{
    return $sentence.' This shift ends the same day — a time after midnight needs an overnight shift in Shift Roster first.';
}

/**
 * Stamps either side of the Manila midnight that ends the row date
 * 2026-09-23 (16:00Z), stored `Z` stamps and offset-less Manila ones alike.
 *
 * @return array<string, array{0: ?string, 1: bool}>
 */
function pslotNextDayCases(): array
{
    return [
        'a Z stamp a millisecond before 16:00Z is still the row date' => ['2026-09-23T15:59:59.999Z', false],
        'a Z stamp at 16:00Z is Manila midnight, the next day' => ['2026-09-23T16:00:00.000Z', true],
        'a 06:00 Manila Time Out stored as 22:00Z of the row date' => ['2026-09-23T22:00:00.000Z', true],
        'the 22:00 Manila Time In stored as 14:00Z' => ['2026-09-23T14:00:00.000Z', false],
        'a Z stamp at 16:00Z the day before is the row date itself' => ['2026-09-22T16:00:00.000Z', false],
        'an offset-less Manila stamp after midnight' => ['2026-09-24T02:00:00', true],
        'an offset-less Manila stamp before midnight' => ['2026-09-23T23:59:00', false],
        'an explicit +08:00 midnight' => ['2026-09-24T00:00:00+08:00', true],
        'the previous day is never next day' => ['2026-09-22T23:00:00', false],
        'no stamp' => [null, false],
        'an empty stamp' => ['', false],
    ];
}

/*
|--------------------------------------------------------------------------
| Ticked slots are stamped on the next calendar day
|--------------------------------------------------------------------------
*/

it('stamps a ticked slot on the next calendar day as an offset-less Manila stamp', function () {
    $punches = PunchSlots::punches('2026-09-23', pslotSlots('22:00', '02:00', '03:00', '06:00'), pslotTicks(false, true, true, true));

    expect($punches)->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-23T22:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-24T02:00:00'],
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-24T03:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-24T06:00:00'],
    ]);

    // No `Z`, no offset: ManilaClock::instant() reads these as Manila wall clock.
    foreach ($punches as $punch) {
        expect($punch['logged_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00$/');
    }
});

it('rolls a ticked slot across a month end and a year end', function () {
    expect(PunchSlots::punches('2025-12-31', pslotSlots('22:00', null, null, '06:00'), pslotTicks(out: true)))->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2025-12-31T22:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-01-01T06:00:00'],
    ])->and(PunchSlots::punches('2026-02-28', pslotSlots('23:15', null, null, '07:05'), pslotTicks(out: true)))->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-02-28T23:15:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-03-01T07:05:00'],
    ]);
});

it('keeps an unticked slot on the corrected date and never infers a roll from the order', function () {
    // The reference would have rolled 06:00 onto the next morning because it
    // reads earlier than 22:00. Without a tick it stays on the date — error()
    // refuses the pair before it can be saved.
    expect(PunchSlots::punches('2026-09-23', pslotSlots('22:00', null, null, '06:00'), PunchSlots::SAME_DAY))->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-23T22:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-23T06:00:00'],
    ]);

    // A night that starts after midnight ticks every slot, the first included.
    expect(PunchSlots::punches('2026-09-23', pslotSlots('00:30', '02:00', '03:00', '06:00'), pslotTicks(true, true, true, true)))->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-24T00:30:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-24T02:00:00'],
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-24T03:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-24T06:00:00'],
    ]);

    // Only filled slots become punches, in slot order.
    expect(PunchSlots::punches('2026-09-23', pslotSlots('08:00', null, null, '17:00'), PunchSlots::SAME_DAY))->toBe([
        ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-23T08:00:00'],
        ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-23T17:00:00'],
    ])->and(PunchSlots::punches('2026-09-23', pslotSlots(), PunchSlots::SAME_DAY))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Ticks the rules ignore
|--------------------------------------------------------------------------
*/

it('ignores a tick beside an empty slot', function () {
    $slots = pslotSlots('22:00', null, null, '06:00');
    $flags = PunchSlots::nextDayFlags(['time_in' => false, 'break_out' => true, 'break_in' => true, 'time_out' => true], $slots);

    expect($flags)->toBe(['time_in' => false, 'break_out' => false, 'break_in' => false, 'time_out' => true])
        ->and(PunchSlots::punches('2026-09-23', $slots, $flags))->toBe([
            ['log_type' => 'TIME_IN', 'logged_at' => '2026-09-23T22:00:00'],
            ['log_type' => 'TIME_OUT', 'logged_at' => '2026-09-24T06:00:00'],
        ]);

    // Every slot empty: every tick is dropped.
    expect(PunchSlots::nextDayFlags(pslotTicks(true, true, true, true), pslotSlots()))->toBe(PunchSlots::SAME_DAY);
});

it('counts only a literal true as a tick', function (mixed $raw) {
    $slots = pslotSlots('22:00', null, null, '06:00');
    $flags = PunchSlots::nextDayFlags(['time_in' => $raw, 'time_out' => $raw], $slots);

    expect($flags)->toBe(PunchSlots::SAME_DAY)
        ->and(PunchSlots::punches('2026-09-23', $slots, $flags)[1]['logged_at'])->toBe('2026-09-23T06:00:00');
})->with([
    'the string yes' => ['yes'],
    'the integer 1' => [1],
    'the string true' => ['true'],
    'the string 1' => ['1'],
    'the string on' => ['on'],
    'the float 1.0' => [1.0],
    'a list holding true' => [[true]],
    'false' => [false],
    'null' => [null],
]);

it('reads missing or unknown tick keys as not ticked', function () {
    $slots = pslotSlots('08:00', '12:00', '13:00', '17:00');

    expect(PunchSlots::nextDayFlags([], $slots))->toBe(PunchSlots::SAME_DAY)
        ->and(PunchSlots::nextDayFlags(['junk' => true, 0 => true, 'TIME_OUT' => true], $slots))->toBe(PunchSlots::SAME_DAY);
});

/*
|--------------------------------------------------------------------------
| Strictly later, once the ticks apply
|--------------------------------------------------------------------------
*/

it('requires each slot to be strictly later than the one before it', function (string $slots, string $ticks, string $whenAllowed, string $whenSameDayShift) {
    expect(PunchSlots::error(pslotRow($slots), pslotTickRow($ticks), true))->toBe($whenAllowed)
        ->and(PunchSlots::error(pslotRow($slots), pslotTickRow($ticks), false))->toBe($whenSameDayShift);
})->with([
    'an equal time' => [
        '08:00|-|-|08:00', 'FFFF',
        pslotAllowed('Final Time Out must be later than First Time In.'),
        pslotRefused('Final Time Out must be later than First Time In.'),
    ],
    'an earlier time left unticked' => [
        '22:00|-|-|06:00', 'FFFF',
        pslotAllowed('Final Time Out must be later than First Time In.'),
        pslotRefused('Final Time Out must be later than First Time In.'),
    ],
    'an earlier middle pair left unticked names the first slot out of order' => [
        '22:00|02:00|03:00|06:00', 'FFFF',
        pslotAllowed('First Time Out must be later than First Time In.'),
        pslotRefused('First Time Out must be later than First Time In.'),
    ],
    'an unticked slot after a ticked one' => [
        '22:00|02:00|03:00|06:00', 'FTFF',
        pslotAllowed('Second Time In must be later than First Time Out.'),
        pslotRefused('Second Time In must be later than First Time Out.'),
    ],
    // A ticked slot already says "after midnight": no tick or roster hint to add.
    'a ticked slot earlier than its ticked predecessor' => [
        '22:00|02:00|01:30|06:00', 'FTTT',
        'Second Time In must be later than First Time Out.',
        'Second Time In must be later than First Time Out.',
    ],
    'a ticked slot equal to its ticked predecessor' => [
        '22:00|02:00|03:00|03:00', 'FTTT',
        'Final Time Out must be later than Second Time In.',
        'Final Time Out must be later than Second Time In.',
    ],
]);

it('accepts a day, and a night once each after-midnight slot is ticked', function () {
    $night = pslotSlots('22:00', '02:00', '03:00', '06:00');
    $afterMidnight = pslotSlots('00:30', '02:00', '03:00', '06:00');
    $day = pslotSlots('08:00', '12:00', '13:00', '17:00');

    // Whether the shift may hold a next-day time at all is CorrectionWindow's
    // question: $nextDayAllowed only words the strictly-later message.
    foreach ([true, false] as $allowed) {
        expect(PunchSlots::error($night, pslotTicks(false, true, true, true), $allowed))->toBeNull()
            ->and(PunchSlots::error(pslotSlots('22:00', null, null, '06:00'), pslotTicks(out: true), $allowed))->toBeNull()
            ->and(PunchSlots::error($afterMidnight, pslotTicks(true, true, true, true), $allowed))->toBeNull()
            ->and(PunchSlots::error($day, PunchSlots::SAME_DAY, $allowed))->toBeNull()
            // A tick beside an empty slot changes nothing.
            ->and(PunchSlots::error(pslotSlots('08:00', null, null, '17:00'), pslotTicks(false, true, true, false), $allowed))->toBeNull()
            // Clearing every slot is how a day punched by mistake is taken back.
            ->and(PunchSlots::error(pslotSlots(), PunchSlots::SAME_DAY, $allowed))->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| The 20-hour ceiling, measured after the ticks
|--------------------------------------------------------------------------
*/

it('measures the 20-hour ceiling after the ticks apply', function () {
    // 08:00 → 17:00 is a nine-hour day; ticked, the Out is 33 hours later.
    expect(PunchSlots::error(pslotSlots('08:00', null, null, '17:00'), PunchSlots::SAME_DAY, true))->toBeNull()
        ->and(PunchSlots::error(pslotSlots('08:00', null, null, '17:00'), pslotTicks(out: true), true))
        ->toBe('That day spans 33 hours, which is longer than any shift. Check the times.');

    // First slot to last, across a ticked middle pair: 22:00 → 19:00 next day.
    expect(PunchSlots::error(pslotSlots('22:00', '02:00', '03:00', '19:00'), pslotTicks(false, true, true, true), true))
        ->toBe('That day spans 21 hours, which is longer than any shift. Check the times.');

    // Unticked, the same 06:00 → 03:00 is an ordering error, not a long day.
    expect(PunchSlots::error(pslotSlots('06:00', null, null, '03:00'), PunchSlots::SAME_DAY, true))
        ->toBe(pslotAllowed('Final Time Out must be later than First Time In.'))
        ->and(PunchSlots::error(pslotSlots('06:00', null, null, '03:00'), pslotTicks(out: true), true))
        ->toBe('That day spans 21 hours, which is longer than any shift. Check the times.');
});

it('allows exactly 20 hours and refuses a minute more, rounding the hours half-up in the message', function () {
    expect(PunchSlots::error(pslotSlots('06:00', null, null, '02:00'), pslotTicks(out: true), true))->toBeNull()
        ->and(PunchSlots::error(pslotSlots('06:00', null, null, '02:01'), pslotTicks(out: true), true))->not->toBeNull()
        // 20.5 hours reads as 21 (Minutes::round, JS half-up).
        ->and(PunchSlots::error(pslotSlots('06:00', null, null, '02:30'), pslotTicks(out: true), true))
        ->toBe('That day spans 21 hours, which is longer than any shift. Check the times.');
});

/*
|--------------------------------------------------------------------------
| The messages kept from the reference
|--------------------------------------------------------------------------
*/

it('keeps the time format message for each slot', function (string $slots, string $message) {
    // Checked before anything else, and the same whatever the ticks say.
    expect(PunchSlots::error(pslotRow($slots), PunchSlots::SAME_DAY, true))->toBe($message)
        ->and(PunchSlots::error(pslotRow($slots), pslotTicks(true, true, true, true), false))->toBe($message);
})->with([
    'First Time In without its leading zero' => ['8:00|-|-|17:00', 'First Time In must be a time like 08:00.'],
    'First Time Out at 24:00' => ['08:00|24:00|13:00|17:00', 'First Time Out must be a time like 08:00.'],
    'Second Time In at minute 60' => ['08:00|12:00|13:60|17:00', 'Second Time In must be a time like 08:00.'],
    'Final Time Out with no colon' => ['08:00|-|-|1700', 'Final Time Out must be a time like 08:00.'],
    'an empty string' => ['|-|-|17:00', 'First Time In must be a time like 08:00.'],
    'a format error before the middle-pair rule' => ['08:00|12:0|-|17:00', 'First Time Out must be a time like 08:00.'],
]);

it('keeps the middle-pair and time-in-first messages', function (string $slots, string $message) {
    expect(PunchSlots::error(pslotRow($slots), PunchSlots::SAME_DAY, true))->toBe($message)
        ->and(PunchSlots::error(pslotRow($slots), PunchSlots::SAME_DAY, false))->toBe($message);
})->with([
    'a First Time Out alone' => [
        '08:00|12:00|-|17:00',
        'Record both middle punches, or neither - First Time Out with no Second Time In never closes.',
    ],
    'a Second Time In alone' => [
        '08:00|-|13:00|17:00',
        'Record both middle punches, or neither - First Time Out with no Second Time In never closes.',
    ],
    'the middle-pair rule before the time-in rule' => [
        '-|12:00|-|-',
        'Record both middle punches, or neither - First Time Out with no Second Time In never closes.',
    ],
    'a Final Time Out with no time in' => [
        '-|-|-|17:00',
        'A day needs a time in before anything else can be recorded against it.',
    ],
    'a middle pair with no time in' => [
        '-|12:00|13:00|-',
        'A day needs a time in before anything else can be recorded against it.',
    ],
]);

it('reads the dataset shorthand as the slots and ticks it spells', function () {
    // Guards the helpers the tables above lean on.
    expect(pslotRow('22:00|-|03:00|'))->toBe(pslotSlots('22:00', null, '03:00', ''))
        ->and(pslotTickRow('FTFT'))->toBe(pslotTicks(false, true, false, true));
});

/*
|--------------------------------------------------------------------------
| filledKeys
|--------------------------------------------------------------------------
*/

it('lists the filled slots in slot order', function () {
    expect(PunchSlots::filledKeys(pslotSlots('08:00', null, null, '17:00')))->toBe(['time_in', 'time_out'])
        ->and(PunchSlots::filledKeys(pslotSlots(null, '12:00', '13:00', null)))->toBe(['break_out', 'break_in'])
        ->and(PunchSlots::filledKeys(pslotSlots('08:00', '12:00', '13:00', '17:00')))->toBe(PunchSlots::KEYS)
        ->and(PunchSlots::filledKeys(pslotSlots()))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| isNextDay — one definition, and the DTR table's forwarder
|--------------------------------------------------------------------------
*/

it('reads next day from the Manila calendar day, never the UTC one', function (?string $iso, bool $expected) {
    expect(PunchSlots::isNextDay($iso, '2026-09-23'))->toBe($expected);
})->with(pslotNextDayCases());

it('has the Edtr Hris helper forward isNextDay to the domain rule', function (?string $iso, bool $expected) {
    expect(Hris::isNextDay($iso, '2026-09-23'))->toBe($expected)
        ->and(Hris::isNextDay($iso, '2026-09-23'))->toBe(PunchSlots::isNextDay($iso, '2026-09-23'));
})->with(pslotNextDayCases());
