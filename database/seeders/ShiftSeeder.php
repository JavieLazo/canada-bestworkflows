<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\ShiftType;

/**
 * The seven shift patterns (seed.ts:1575-1598) and the per-employee roster
 * (seed.ts:1505-1523): Morning for every third employee, Regular Day for the
 * rest, hours COPIED from the pattern exactly as assignment does — a later
 * pattern edit must not change posted payroll.
 *
 * The trailing work-days list is the scheduled week, 0 Sunday to 6 Saturday.
 * It sets the rest days and divides the monthly rate into a daily one, so the
 * Mon-Sat pattern deliberately earns less per day than the Mon-Fri ones on
 * the same monthly rate.
 */
final class ShiftSeeder extends PortSeeder
{
    private const MON_FRI = [1, 2, 3, 4, 5];

    public function run(): void
    {
        $this->call(ShiftTypeSeeder::class);

        $typeIds = ShiftType::query()
            ->whereIn('code', ['SFT-001', 'SFT-002'])
            ->pluck('id', 'code');

        foreach ($this->employeeIdsByIndex() as $i => $employeeId) {
            // Morning for every third employee, Regular Day for the rest.
            $morning = $i % 3 === 0;

            $this->upsert(Shift::class, ['employee_id' => $employeeId], [
                'id' => 'shift-'.($i + 1),
                'shift_type_id' => $morning
                    ? (isset($typeIds['SFT-001']) ? (string) $typeIds['SFT-001'] : null)
                    : (isset($typeIds['SFT-002']) ? (string) $typeIds['SFT-002'] : null),
                'mode' => 'FIXED',
                'required_hours' => null,
                'work_days' => self::MON_FRI,
                'start_time' => $morning ? '07:00:00' : '08:00:00',
                'end_time' => $morning ? '16:00:00' : '17:00:00',
                'grace_minutes' => $morning ? 10 : 15,
                'break_start' => $morning ? '11:30:00' : '12:00:00',
                'break_end' => $morning ? '12:30:00' : '13:00:00',
                'break_minutes' => 60,
                'requires_break_punch' => true,
            ]);
        }
    }
}
