<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LeaveCredit;
use App\Models\LeaveCreditEntry;
use App\Modules\Timekeeping\Application\LeaveCreditWriter;
use App\Modules\Timekeeping\Domain\Absence\LeaveCreditPolicy;
use App\Support\HrisFormat;
use App\Support\LocalClock;
use Illuminate\Support\Facades\DB;

/**
 * A year of leave credits for every demo employee — DEMO ONLY, never reached
 * from ProductionSeeder: a real installation's entitlements are entered by
 * hand on Leave Management's Leave Credits tab, per employee and per year.
 *
 * The reference seed (`src/lib/seed.ts`) gives every employee 15 vacation and
 * 15 sick credits on the employee record; ems has no such columns and keeps a
 * row per Leave Settings type and calendar year instead (leave_credits,
 * 2026_09_26_0003). This seeder restates the same two numbers in that shape
 * so the feature can be walked through on the demo data without first typing
 * them in: Vacation Leave 15 and Sick Leave 15 for each of the eight demo
 * employees, in the CURRENT local year — the year the demo requests fall in,
 * since LeaveSeeder dates them from today.
 *
 * Keys are the same tokens requests store (`HrisFormat::normalizeRequestType`
 * of the Leave Settings name: `VACATION_LEAVE`, `SICK_LEAVE`), so the demo
 * balances draw on LeaveSeeder's rows by the one `_LEAVE` tolerance: emp-7's
 * approved paid `SICK` day (req-1) shows Sick Leave 14 of 15, while emp-6's
 * approved WITHOUT-pay vacation (req-6) leaves Vacation Leave at 15 of 15.
 *
 * Rows are keyed on the natural (employee_id, year, request_type) triple,
 * `lcr_employee_year_type_unq`, with NO literal id: BaseModel mints the ULID,
 * because a year-bearing literal would not fit char(26) and an existing row
 * keeps whichever id it already has anyway. Untracked fixture edits and
 * stray types are restored to the demo state. Once a type has ledger history,
 * its allocation belongs to that history: NEVER reset it, remove it, or
 * recreate a cleared row. Baselines count as history too. Other years remain
 * untouched. The shared sentinel serializes this check with HR's writers.
 */
final class LeaveCreditSeeder extends PortSeeder
{
    /** The Leave Settings names credited, as ReferenceListSeeder spells them. */
    private const TYPES = ['Vacation Leave', 'Sick Leave'];

    /** seed.ts's value for both: fifteen days each. */
    private const DAYS = 15.0;

    public function run(): void
    {
        $year = LeaveCreditPolicy::yearOf(LocalClock::todayKey());
        $keys = array_map(HrisFormat::normalizeRequestType(...), self::TYPES);

        foreach ($this->employeeIdsByIndex() as $employeeId) {
            DB::transaction(function () use ($employeeId, $year, $keys): void {
                LeaveCreditWriter::lock();
                $recorded = LeaveCreditEntry::query()->where('employee_id', $employeeId)->where('year', $year)->pluck('request_type')->all();
                foreach ($keys as $key) {
                    if (in_array($key, $recorded, true)) {
                        continue;
                    }
                    $this->upsert(LeaveCredit::class, [
                        'employee_id' => $employeeId,
                        'year' => $year,
                        'request_type' => $key,
                    ], [
                        'credits' => self::DAYS,
                    ]);
                }

                LeaveCredit::query()
                    ->where('employee_id', $employeeId)
                    ->where('year', $year)
                    ->whereNotIn('request_type', [...$keys, ...$recorded])
                    ->delete();
            });
        }
    }
}
