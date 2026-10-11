<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestrates the port of the reference demo dataset (`src/lib/seed.ts`).
 *
 * Order is dependency order: roles before the accounts that point at them,
 * employees before everything keyed by employee_id, shifts before the punches
 * the DTR reads against them, weekly locations before the punches that follow
 * them (AttendanceSeeder reads roster_locations to decide where each pair was
 * made), leave requests before the audit entry that names one, and the leave
 * credits those requests draw on right after them.
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
            IncentiveTypeSeeder::class,
            EmployeeSeeder::class,
            ShiftSeeder::class,
            RosterLocationSeeder::class,
            AttendanceSeeder::class,
            LeaveSeeder::class,
            LeaveCreditSeeder::class,
            // After the punches and the leave it prices: approves the demo
            // overtime so the demo payroll is not held up (demo only).
            OvertimeApprovalSeeder::class,
            ProjectSeeder::class,
            PayrollRunSeeder::class,
            AuditLogSeeder::class,
            SocialSeeder::class,
        ]);
    }
}
