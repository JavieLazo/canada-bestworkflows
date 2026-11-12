<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Modules\Timekeeping\Domain\Roster\RestBetweenShifts;

/**
 * The roster's short-rest warning measures the SCHEDULED gap from the end of
 * one date's shift to the start of the next date's: an overnight shift ends
 * on the next morning, less than eight hours is short, and nothing is judged
 * across a flexible shift.
 */
function rrestShift(string $start, string $end, string $mode = DomainShift::MODE_FIXED): DomainShift
{
    return DomainShift::fromArray(['start_time' => $start, 'end_time' => $end, 'mode' => $mode, 'required_hours' => $mode === DomainShift::MODE_FLEXIBLE ? 8 : null]);
}

it('measures no rest between a night that ends at six and a morning that starts at six', function () {
    expect(RestBetweenShifts::minutes(rrestShift('22:00:00', '06:00:00'), rrestShift('06:00:00', '14:00:00')))->toBe(0.0)
        ->and(RestBetweenShifts::shortfall(rrestShift('22:00:00', '06:00:00'), rrestShift('06:00:00', '14:00:00')))->toBe(0.0);
});

it('flags a gap under eight hours and passes eight hours exactly', function () {
    // PM ends 22:00; AM at 06:00 the next day is eight hours later.
    expect(RestBetweenShifts::shortfall(rrestShift('14:00:00', '22:00:00'), rrestShift('06:00:00', '14:00:00')))->toBeNull()
        ->and(RestBetweenShifts::shortfall(rrestShift('14:00:00', '22:00:00'), rrestShift('05:00:00', '13:00:00')))->toBe(420.0)
        ->and(RestBetweenShifts::minutes(rrestShift('08:00:00', '17:00:00'), rrestShift('08:00:00', '17:00:00')))->toBe(900.0);
});

it('reads an overlap as no rest at all, never a negative gap', function () {
    // A night to 07:00, then an AM from 06:00: they overlap by an hour.
    expect(RestBetweenShifts::minutes(rrestShift('23:00:00', '07:00:00'), rrestShift('06:00:00', '14:00:00')))->toBe(-60.0)
        ->and(RestBetweenShifts::shortfall(rrestShift('23:00:00', '07:00:00'), rrestShift('06:00:00', '14:00:00')))->toBe(0.0);
});

it('judges nothing across a flexible shift', function () {
    expect(RestBetweenShifts::minutes(rrestShift('08:00:00', '17:00:00', DomainShift::MODE_FLEXIBLE), rrestShift('06:00:00', '14:00:00')))->toBeNull()
        ->and(RestBetweenShifts::shortfall(rrestShift('22:00:00', '06:00:00'), rrestShift('08:00:00', '17:00:00', DomainShift::MODE_FLEXIBLE)))->toBeNull();
});
