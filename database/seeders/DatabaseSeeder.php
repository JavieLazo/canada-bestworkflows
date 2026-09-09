<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestrates the port of the reference demo dataset (`src/lib/seed.ts`).
 *
 * Order is dependency order: roles before the accounts that point at them,
 * employees before everything keyed by employee_id, shifts before the punches
 * the DTR reads against them, leave requests before the audit entry that
 * names one.
 *
 * Idempotent throughout — every seeder keys on natural uniques and re-running
 * updates rather than duplicates. NO WithoutModelEvents here: BaseModel mints
 * ULIDs in a creating hook, and suppressing events would insert empty keys.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ReferenceListSeeder::class,
            HolidaySeeder::class,
            PayrollFormulaSeeder::class,
            EmployeeSeeder::class,
            ShiftSeeder::class,
            AttendanceSeeder::class,
            LeaveSeeder::class,
            ProjectSeeder::class,
            PayrollRunSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
