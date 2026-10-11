<?php

declare(strict_types=1);

use App\Domain\Attendance\Shift as DomainShift;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Modules\Timekeeping\Infrastructure\ShiftRoster;
use Tests\Support\LegacyShiftRoster;

/**
 * Payroll parity across the dated roster: every distinct shift the golden
 * payslip vectors use, stored as an employee's roster row, reaches the
 * calculation exactly as the single-version roster (LegacyShiftRoster, the
 * oracle) delivers it, for the vector's own payroll window. The golden suites
 * pin the engine and the fixtures; this pins where the schedule comes from.
 */
function goldparEmployee(): EmployeeProfile
{
    return EmployeeProfile::query()->create(['employee_code' => 'GOLD-PARITY', 'first_name' => 'Golden', 'last_name' => 'Parity',
        'employment_status' => 'Regular', 'date_hired' => '2020-01-01', 'is_active' => true]);
}

/**
 * A vector's shift as the roster row a person would have saved: the fields
 * `shifts` stores, with the column defaults standing in for absent ones.
 *
 * @param  array<string, mixed>  $shift
 * @return array<string, mixed>
 */
function goldparRow(array $shift): array
{
    return [
        'mode' => $shift['mode'] ?? 'FIXED',
        'required_hours' => $shift['required_hours'] ?? null,
        'work_days' => $shift['work_days'] ?? [1, 2, 3, 4, 5],
        'start_time' => $shift['start_time'] ?? '08:00:00',
        'end_time' => $shift['end_time'] ?? '17:00:00',
        'grace_minutes' => $shift['grace_minutes'] ?? 15,
        'break_start' => $shift['break_start'] ?? null,
        'break_end' => $shift['break_end'] ?? null,
        'break_minutes' => $shift['break_minutes'] ?? 0,
        'requires_break_punch' => $shift['requires_break_punch'] ?? false,
    ];
}

it('resolves every golden vector\'s shift exactly as the single-version roster did', function () {
    $path = __DIR__.'/../fixtures/golden-payslips.ndjson';
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Could not open fixture {$path}");
    }
    $employee = goldparEmployee();
    $roster = app(ShiftRoster::class);
    $legacy = new LegacyShiftRoster;
    $seen = [];
    $compared = 0;
    $mismatches = [];

    while (($line = fgets($handle)) !== false) {
        $vector = json_decode(trim($line), true);
        if (! is_array($vector)) {
            continue;
        }
        $shift = $vector['input']['shift'] ?? null;
        $key = json_encode($shift);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        Shift::query()->where('employee_id', $employee->id)->delete();
        if (is_array($shift)) {
            Shift::query()->create(goldparRow($shift) + ['employee_id' => $employee->id]);
        }
        $from = (string) $vector['input']['periodStart'];
        $to = (string) $vector['input']['periodEnd'];
        $new = $roster->scheduleForEmployee($employee->id, $from, $to)?->toArray();
        $old = $legacy->scheduleForEmployee($employee->id, $from, $to)?->toArray();
        if ($new !== $old) {
            $mismatches[] = $key;
        }
        // And the stored row is the vector's shift, so the engine sees it.
        if (is_array($shift) && $new !== null) {
            $expected = DomainShift::fromArray(goldparRow($shift))->toArray();
            if ($new !== $expected) {
                $mismatches[] = 'stored '.$key;
            }
        }
        $compared++;
    }
    fclose($handle);

    expect($mismatches)->toBe([])
        // Every distinct shift in the corpus, the no-roster case included.
        ->and($compared)->toBe(2216);
});
