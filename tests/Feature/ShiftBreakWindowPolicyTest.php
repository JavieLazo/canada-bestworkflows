<?php

declare(strict_types=1);

use App\Livewire\Records\Support\ShiftTypePolicy;
use App\Livewire\Roster\ShiftPolicy;

/**
 * A break window has to describe a break somebody could actually take.
 *
 * Nothing validated either half of that, and both halves cost real money. A
 * window whose length disagrees with `break_minutes` is charged by the
 * calculator while `paidShiftMinutes` deducts the number instead, and a window
 * outside its own shift never overlaps the hours worked at all -- so the
 * allowance is never deducted and every ordinary day reads as overtime.
 *
 * The Add-shift-type dialog sets the second trap itself: it hard-defaults the
 * break to 12:00-13:00, so any night pattern saved without re-timing it was
 * exposed. Both policies carry the same rules, word for word, because the two
 * write paths reach the same tables.
 */
it('refuses a break window that does not match break_minutes', function () {
    $shape = [
        'mode' => 'FIXED', 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 30,
        'required_hours' => null, 'requires_break_punch' => false,
    ];

    expect(ShiftPolicy::shapeError($shape))->toBe('Break minutes must match the length of the break window.')
        ->and(ShiftTypePolicy::shapeError($shape))->toBe('Break minutes must match the length of the break window.');
});

it('refuses a break window that falls outside its own shift', function () {
    // The exact trap the Add-shift-type dialog sets: a night pattern left on
    // the form's default noon break.
    $shape = [
        'mode' => 'FIXED', 'start_time' => '22:00:00', 'end_time' => '06:00:00',
        'break_start' => '12:00:00', 'break_end' => '13:00:00', 'break_minutes' => 60,
        'required_hours' => null, 'requires_break_punch' => false,
    ];

    expect(ShiftPolicy::shapeError($shape))->toBe('The break window must fall inside the shift.')
        ->and(ShiftTypePolicy::shapeError($shape))->toBe('The break window must fall inside the shift.');
});

it('accepts every shift pattern the seeder ships', function () {
    foreach ([
        ['22:00:00', '06:00:00', '02:00:00', '03:00:00', 60],  // night, break inside the overnight span
        ['08:00:00', '17:00:00', '12:00:00', '13:00:00', 60],
        ['07:00:00', '16:00:00', '11:30:00', '12:30:00', 60],
        ['12:00:00', '21:00:00', '16:00:00', '17:00:00', 60],
        ['08:00:00', '12:00:00', null, null, 0],               // half day, no break
    ] as [$start, $end, $bs, $be, $bm]) {
        $shape = [
            'mode' => 'FIXED', 'start_time' => $start, 'end_time' => $end,
            'break_start' => $bs, 'break_end' => $be, 'break_minutes' => $bm,
            'required_hours' => null, 'requires_break_punch' => false,
        ];

        expect(ShiftPolicy::shapeError($shape))->toBeNull();
    }
});
