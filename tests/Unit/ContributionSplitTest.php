<?php

declare(strict_types=1);

use App\Domain\Attendance\AttendanceLog as DomainLog;
use App\Domain\Attendance\Shift as DomainShift;
use App\Domain\Payroll\PayslipCalculator;
use App\Domain\Payroll\StatutoryLines;
use App\Modules\Payroll\Domain\Deductions\GovDeductionSettings;

/**
 * DELIBERATE DIVERGENCE 19: two more ways to take the month's SSS,
 * PhilHealth or Pag-IBIG share — half on the first cut-off and the REST
 * on the second (HALF_THEN_REST), or the whole on the first and the rest
 * on the second (WHOLE_FIRST) — each reading what the month's posted
 * payslips already took. The reference's two schedules (half each; whole
 * on the month-ending payslip) keep their operations and never read it.
 */
it('takes half then the rest, or the whole first, from what the month already posted', function () {
    // The reference's schedules, untouched by what was posted.
    expect(StatutoryLines::contribution(true, true, null, 1000.0, 'SEMI_MONTHLY', false, 400.0))->toBe(500.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, null, true, 400.0))->toBe(500.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'MONTHLY', false, 400.0))->toBe(0.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'MONTHLY', true, 400.0))->toBe(1000.0)
        // Half then the rest: 500 on the first, 1,000 − 500 on the second; a changed table pays the difference.
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'HALF_THEN_REST', false, 0.0))->toBe(500.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'HALF_THEN_REST', true, 500.0))->toBe(500.0)
        ->and(StatutoryLines::contribution(true, true, null, 1100.0, 'HALF_THEN_REST', true, 500.0))->toBe(600.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'HALF_THEN_REST', true, 1200.0))->toBe(0.0)
        // Whole first: all on the first, nothing more on the second once it posted — the rest when the first was skipped.
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'WHOLE_FIRST', false, 0.0))->toBe(1000.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'WHOLE_FIRST', true, 1000.0))->toBe(0.0)
        ->and(StatutoryLines::contribution(true, true, null, 1000.0, 'WHOLE_FIRST', true, 0.0))->toBe(1000.0)
        // A fixed figure replaces the table's month, and the switches still gate.
        ->and(StatutoryLines::contribution(true, true, 800.0, 1000.0, 'HALF_THEN_REST', true, 300.0))->toBe(500.0)
        ->and(StatutoryLines::contribution(false, true, null, 1000.0, 'WHOLE_FIRST', false, 0.0))->toBe(0.0)
        ->and(StatutoryLines::contribution(true, false, null, 1000.0, 'WHOLE_FIRST', false, 0.0))->toBe(0.0)
        ->and(GovDeductionSettings::modeOf('HALF_THEN_REST'))->toBe('HALF_THEN_REST')
        ->and(GovDeductionSettings::modeOf('WHOLE_FIRST'))->toBe('WHOLE_FIRST')
        ->and(GovDeductionSettings::modeOf('QUARTERLY'))->toBe('MONTHLY')
        ->and(GovDeductionSettings::error([...GovDeductionSettings::STANDARD, 'sss_mode' => 'HALF_THEN_REST']))->toBeNull()
        ->and(GovDeductionSettings::error([...GovDeductionSettings::STANDARD, 'sss_mode' => 'WEEKLY']))->toBe('SSS schedule: choose when it is deducted.');
});

it('prices a payslip with the posted shares handed in, and reports the contribution base', function () {
    $shift = DomainShift::fromArray(['mode' => 'FIXED', 'work_days' => [1, 2, 3, 4, 5], 'start_time' => '08:00:00', 'end_time' => '17:00:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'break_start' => '12:00:00', 'break_end' => '13:00:00']);
    $slip = static fn (string $mode, array $posted, string $start, string $end): array => PayslipCalculator::compute(
        employee: (object) ['id' => 'E1', 'monthly_rate' => 30000.0, 'daily_rate' => null, 'hourly_rate' => 0,
            'allow_sss' => true, 'allow_philhealth' => true, 'allow_pagibig' => false, 'allow_withholding_tax' => false,
            'sss_mode' => $mode, 'philhealth_mode' => 'SEMI_MONTHLY'],
        logs: DomainLog::listFromArrays([
            ['log_type' => 'TIME_IN', 'logged_at' => "{$start}T00:00:00.000Z"], ['log_type' => 'TIME_OUT', 'logged_at' => "{$start}T09:00:00.000Z"],
        ]),
        shift: $shift, leaves: [], periodStart: $start, periodEnd: $end, bonus: 0.0,
        settings: ['formulas' => [], 'holidays' => []],
        contributionPosted: $posted,
    )->toArray();

    $first = $slip('HALF_THEN_REST', [], '2026-09-01', '2026-09-15');
    $second = $slip('HALF_THEN_REST', ['sss' => $first['sss'], 'sss_er' => $first['sss_er'], 'philhealth' => $first['philhealth'], 'philhealth_er' => $first['philhealth_er'], 'pagibig' => 0.0, 'pagibig_er' => 0.0], '2026-09-16', '2026-09-30');
    $reference = $slip('SEMI_MONTHLY', [], '2026-09-01', '2026-09-15');

    expect($first['sss'])->toBe($reference['sss'])
        ->and($first['contribution_base'])->toBe(30000.0)
        // The second takes exactly the month's remainder: the two together are the whole month.
        ->and($first['sss'] + $second['sss'])->toBe($reference['sss'] * 2)
        ->and($first['sss_er'] + $second['sss_er'])->toBe($reference['sss_er'] * 2)
        // The half-each line beside it is untouched by what was posted.
        ->and($second['philhealth'])->toBe($reference['philhealth']);
});
