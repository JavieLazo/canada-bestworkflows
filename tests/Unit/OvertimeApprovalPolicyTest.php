<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Domain\Attendance\OvertimeApprovalPolicy;

/**
 * What the Daily Time Record may decide about a day's overtime: a day is
 * decidable from one whole minute, the most an approver may approve is the
 * engine's own min(computed, Formulas cap), and a stored decision reads as
 * pending, approved, partial, rejected or outdated. The dialog and the
 * command both apply these rules, so neither can offer what the other refuses.
 */
it('counts overtime in whole minutes and never below zero', function () {
    expect(OvertimeApprovalPolicy::wholeMinutes(0.0))->toBe(0)
        ->and(OvertimeApprovalPolicy::wholeMinutes(0.4))->toBe(0)
        ->and(OvertimeApprovalPolicy::wholeMinutes(0.5))->toBe(1)
        ->and(OvertimeApprovalPolicy::wholeMinutes(150.49))->toBe(150)
        ->and(OvertimeApprovalPolicy::wholeMinutes(-30.0))->toBe(0)
        ->and(OvertimeApprovalPolicy::wholeMinutes(NAN))->toBe(0)
        ->and(OvertimeApprovalPolicy::wholeMinutes(INF))->toBe(0);
});

it('makes a day decidable from the first whole minute of overtime', function () {
    expect(OvertimeApprovalPolicy::decidable(0.0))->toBeFalse()
        ->and(OvertimeApprovalPolicy::decidable(0.4))->toBeFalse()
        ->and(OvertimeApprovalPolicy::decidable(0.5))->toBeTrue()
        ->and(OvertimeApprovalPolicy::decidable(95.0))->toBeTrue();
});

it('caps what may be approved at the Formulas overtime maximum', function () {
    expect(OvertimeApprovalPolicy::ceiling(150.0, 8.0))->toBe(150)
        ->and(OvertimeApprovalPolicy::ceiling(150.0, 2.0))->toBe(120)
        // The cap is whole minutes, like the engine's Minutes::round(max_ot * 60).
        ->and(OvertimeApprovalPolicy::ceiling(150.0, 1.999))->toBe(120)
        ->and(OvertimeApprovalPolicy::ceiling(150.0, 0.0))->toBe(0)
        ->and(OvertimeApprovalPolicy::ceiling(150.0, -1.0))->toBe(0)
        ->and(OvertimeApprovalPolicy::ceiling(150.0, NAN))->toBe(0)
        ->and(OvertimeApprovalPolicy::ceiling(0.0, 8.0))->toBe(0);
});

it('reads a day with no decision as pending only when it has overtime', function () {
    expect(OvertimeApprovalPolicy::status(null, null, null, 45.0, 45))->toBe(OvertimeApprovalPolicy::PENDING)
        ->and(OvertimeApprovalPolicy::status(null, null, null, 0.2, 0))->toBeNull()
        ->and(OvertimeApprovalPolicy::status(null, null, null, 0.0, 0))->toBeNull();
});

it('reads a stored decision against what the day computes now', function () {
    // Rejected stays rejected, whatever the day computes since.
    expect(OvertimeApprovalPolicy::status('REJECTED', 0, 45, 90.0, 90))->toBe(OvertimeApprovalPolicy::REJECTED)
        // The whole approvable figure is APPROVED; less is PARTIAL.
        ->and(OvertimeApprovalPolicy::status('APPROVED', 45, 45, 45.0, 45))->toBe(OvertimeApprovalPolicy::APPROVED)
        ->and(OvertimeApprovalPolicy::status('APPROVED', 30, 45, 45.0, 45))->toBe(OvertimeApprovalPolicy::PARTIAL)
        // Approving the capped figure of a day longer than the cap is the whole of it.
        ->and(OvertimeApprovalPolicy::status('APPROVED', 120, 150, 150.0, 120))->toBe(OvertimeApprovalPolicy::APPROVED)
        // A corrected punch changed the day: the decision no longer describes it.
        ->and(OvertimeApprovalPolicy::status('APPROVED', 45, 45, 60.0, 60))->toBe(OvertimeApprovalPolicy::OUTDATED)
        ->and(OvertimeApprovalPolicy::status('APPROVED', 45, 45, 0.0, 0))->toBe(OvertimeApprovalPolicy::OUTDATED);
});

it('accepts only whole-number minutes as typed', function () {
    expect(OvertimeApprovalPolicy::minutesOf(90))->toBe(90)
        ->and(OvertimeApprovalPolicy::minutesOf('90'))->toBe(90)
        ->and(OvertimeApprovalPolicy::minutesOf(' 90 '))->toBe(90)
        ->and(OvertimeApprovalPolicy::minutesOf('90.5'))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf('1h'))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf(''))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf('-5'))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf('12345'))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf(90.0))->toBeNull()
        ->and(OvertimeApprovalPolicy::minutesOf(null))->toBeNull();
});

it('refuses an approval outside one minute to the ceiling, never a rejection', function () {
    expect(OvertimeApprovalPolicy::error('APPROVED', '45', 45))->toBeNull()
        ->and(OvertimeApprovalPolicy::error('APPROVED', '1', 45))->toBeNull()
        ->and(OvertimeApprovalPolicy::error('APPROVED', '46', 45))->toBe('Approve between 1m and 45m of overtime.')
        ->and(OvertimeApprovalPolicy::error('APPROVED', '0', 150))->toBe('Approve between 1m and 2h 30m of overtime.')
        ->and(OvertimeApprovalPolicy::error('APPROVED', 'abc', 45))->toBe('Approve between 1m and 45m of overtime.')
        ->and(OvertimeApprovalPolicy::error('APPROVED', '30', 0))->toBe('Nothing is approvable under the overtime formula; reject it instead.')
        ->and(OvertimeApprovalPolicy::error('REJECTED', 'anything', 0))->toBeNull()
        ->and(OvertimeApprovalPolicy::error('PENDING', '30', 45))->toBe('Choose whether to approve or reject the overtime.');
});

it('spells a duration the way the DTR does', function () {
    expect(OvertimeApprovalPolicy::duration(0))->toBe('0m')
        ->and(OvertimeApprovalPolicy::duration(45))->toBe('45m')
        ->and(OvertimeApprovalPolicy::duration(120))->toBe('2h')
        ->and(OvertimeApprovalPolicy::duration(150))->toBe('2h 30m')
        ->and(OvertimeApprovalPolicy::duration(-5))->toBe('0m');
});

it('pays an approval only up to what the day computes now and the cap allow', function () {
    expect(OvertimeApprovalPolicy::payable('APPROVED', 90, 150))->toBe(90)
        // A corrected, shorter day, or a lowered maximum: the ceiling wins.
        ->and(OvertimeApprovalPolicy::payable('APPROVED', 150, 60))->toBe(60)
        ->and(OvertimeApprovalPolicy::payable('APPROVED', 150, 0))->toBe(0)
        ->and(OvertimeApprovalPolicy::payable('REJECTED', 0, 150))->toBe(0)
        ->and(OvertimeApprovalPolicy::payable(null, null, 150))->toBe(0);
});
