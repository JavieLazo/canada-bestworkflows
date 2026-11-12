<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Roster\CustomHours;

/**
 * Custom hours typed on the Shift Roster become a fixed shift whose break
 * window is its unpaid break, and keep the shape rules a pattern passes. A
 * saved day is custom hours only when it has no pattern and the name
 * CustomHours::NAME, which tells it from a deleted pattern's copy.
 */
it('turns custom hours into a fixed shift whose break window is its unpaid break', function () {
    $input = ['start' => '19:00', 'end' => '07:00', 'break_start' => '00:00', 'break_end' => '01:00', 'grace' => ''];

    $shift = CustomHours::attributes($input);

    expect(CustomHours::error($input))->toBeNull()
        ->and($shift['mode'])->toBe('FIXED')
        ->and($shift['start_time'])->toBe('19:00:00')
        ->and($shift['break_minutes'])->toBe(60)
        ->and($shift['grace_minutes'])->toBe(CustomHours::DEFAULT_GRACE)
        ->and(CustomHours::input($shift))->toBe(['start' => '19:00', 'end' => '07:00', 'break_start' => '00:00', 'break_end' => '01:00', 'grace' => '15'])
        ->and(CustomHours::isCustom(null, CustomHours::NAME, $shift))->toBeTrue()
        ->and(CustomHours::isCustom(null, 'Night (deleted)', $shift))->toBeFalse();
});

it('refuses custom hours the shape rules refuse', function (mixed $input) {
    expect(CustomHours::error($input))->not->toBeNull();
})->with([
    'not an array' => ['07:00'],
    'no start' => [['start' => '', 'end' => '15:00']],
    'half a break' => [['start' => '07:00', 'end' => '15:00', 'break_start' => '12:00', 'break_end' => '']],
    'grace too long' => [['start' => '07:00', 'end' => '15:00', 'grace' => '601']],
    'not a time' => [['start' => '25:00', 'end' => '15:00']],
]);
