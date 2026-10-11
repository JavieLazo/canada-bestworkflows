<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Shift;
use App\Models\ShiftType;

/**
 * The seven shift patterns (seed.ts:1575-1598) and the per-employee roster
 * (seed.ts:1505-1523): Morning for every third employee, Regular Day for the
 * rest, hours COPIED from the pattern exactly as assignment does. This seeder
 * writes the rows directly and BYPASSES `SaveShiftType`, whose edit path
 * re-copies a pattern onto its linked rows; a re-seed can therefore leave a
 * row drifted from its pattern until that pattern is next edited. Posted
 * payroll is protected by `PayrollInputs`, not by the copy.
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

        // Both spellings: ShiftTypeSeeder's codeMatch deliberately KEEPS an
        // unconverted SFT-001 row rather than renumbering it, so looking only
        // for '1' would leave every seeded shift with shift_type_id => null —
        // the quiet failure, not a loud one.
        $typeIds = ShiftType::query()
            ->whereIn('code', ['1', '2', 'SFT-001', 'SFT-002'])
            ->pluck('id', 'code')->all();

        foreach ($this->employeeIdsByIndex() as $i => $employeeId) {
            // Morning for every third employee, Regular Day for the rest.
            $morning = $i % 3 === 0;

            // The baseline row (NULL effective_from): a walkthrough's dated
            // changes are history and stay.
            $this->upsert(Shift::class, ['employee_id' => $employeeId, 'effective_from' => null, 'change_id' => null], [
                'id' => 'shift-'.($i + 1),
                'shift_type_id' => $morning
                    ? self::shiftTypeId($typeIds, '1', 'SFT-001')
                    : self::shiftTypeId($typeIds, '2', 'SFT-002'),
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

    /**
     * The shift type's id under either spelling of its code, or NULL — never
     * the empty string.
     *
     * `(string) null` is `''`, and `shifts.shift_type_id` is a NULLABLE
     * `char(26)` with a real foreign key (2026_09_01_0008): `''` is a
     * constraint violation on MariaDB, while NULL is exactly what the column
     * is for. `ShiftTypeSeeder` runs first and guarantees one of the two
     * spellings, so this cannot return null today — it is the guard for the
     * day that stops being true, which is the day the cast would otherwise
     * write `''` into an FK.
     *
     * @param  array<string, mixed>  $typeIds
     */
    private static function shiftTypeId(array $typeIds, string $bare, string $legacy): ?string
    {
        $id = $typeIds[$bare] ?? $typeIds[$legacy] ?? null;

        return is_string($id) ? $id : null;
    }
}
