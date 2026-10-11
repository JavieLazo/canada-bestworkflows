<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backfills one baseline employment-history row per current employee.
 *
 * The table migration creates the storage; this data-copy migration gives
 * existing records a starting point in the timeline. It copies the current
 * Employee Details snapshot only when the employee has no history row yet, so
 * retries and later deploys do not duplicate rows or rewrite HR-entered
 * timeline entries.
 *
 * RETRY-SAFE. The migration first reads the complete still-missing employee id
 * set, then inserts in chunks. Each row gets its own ULID and millisecond
 * timestamp because DB inserts bypass BaseModel. If a cron run stops partway
 * through, a retry reads only employees still missing history and finishes the
 * rest. down() deletes only these seed rows, identified by event_type BASELINE
 * and the System backfill actor name.
 *
 * ROLLBACK LOSES only generated baseline rows. Hire/update history rows remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_profiles') || ! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        $ids = DB::table('employee_profiles')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('employee_employment_histories')
                    ->whereColumn('employee_employment_histories.employee_id', 'employee_profiles.id');
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, 100) as $chunk) {
            $employees = DB::table('employee_profiles')
                ->whereIn('id', $chunk)
                ->orderBy('id')
                ->get([
                    'id',
                    'position',
                    'position_level',
                    'employment_status',
                    'work_site_id',
                    'date_hired',
                    'date_regular',
                    'date_resigned',
                    'is_active',
                    'monthly_rate',
                    'daily_rate',
                ]);

            $now = now()->format('Y-m-d H:i:s.v');
            $rows = [];
            foreach ($employees as $employee) {
                $data = (array) $employee;
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'employee_id' => $employee->id,
                    'event_type' => 'BASELINE',
                    'effective_date' => $this->effectiveDate($data),
                    'changed_fields' => 'position,position_level,employment_status,work_site_id,date_hired,date_regular,date_resigned,is_active,monthly_rate,daily_rate',
                    'position' => $employee->position,
                    'position_level' => $employee->position_level,
                    'employment_status' => $employee->employment_status,
                    'work_site_id' => $employee->work_site_id,
                    'date_hired' => $employee->date_hired,
                    'date_regular' => $employee->date_regular,
                    'date_resigned' => $employee->date_resigned,
                    'is_active' => (bool) $employee->is_active,
                    'monthly_rate' => $employee->monthly_rate,
                    'daily_rate' => $employee->daily_rate,
                    'recorded_by' => null,
                    'recorded_by_name' => 'System backfill',
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('employee_employment_histories')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_employment_histories')) {
            return;
        }

        DB::table('employee_employment_histories')
            ->where('event_type', 'BASELINE')
            ->where('recorded_by_name', 'System backfill')
            ->delete();
    }

    /** @param array<string, mixed> $employee */
    private function effectiveDate(array $employee): string
    {
        if ($employee['date_resigned'] !== null) {
            return (string) $employee['date_resigned'];
        }

        if ($employee['date_regular'] !== null) {
            return (string) $employee['date_regular'];
        }

        return (string) $employee['date_hired'];
    }
};
