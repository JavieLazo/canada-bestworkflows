<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Configuration-only seed for a PRODUCTION database.
 *
 * Roles + module grants, the reference lists, PH holidays, payroll formulas,
 * the shift-type catalogue and the starting incentive and bonus types - and
 * deliberately NOTHING else. No employees,
 * no accounts, no credentials, no work sites (those are the client's real
 * locations, entered through Records). The demo dataset lives in
 * DatabaseSeeder and must never run in production.
 *
 * Rows go through PortSeeder using stable seed identities and natural keys.
 * New rows receive the same BaseModel keys as normal application inserts.
 * IncentiveTypeSeeder and the Position list
 * only ADD missing rows and never update one, so HR's edits survive.
 * Never insertOrIgnore (INSERT IGNORE downgrades truncation,
 * FK and NOT NULL violations to warnings) and never upsert() (Laravel's
 * $uniqueBy is ignored on MySQL, unsafe on double-unique tables).
 *
 * First admin account: php artisan ems:create-admin (see that command).
 */
final class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ReferenceListSeeder::class,
            HolidaySeeder::class,
            PayrollFormulaSeeder::class,
        ]);

        // Shift-type catalogue (shared with the demo seeder; assignments are not seeded).
        $this->call(ShiftTypeSeeder::class);

        // The starting incentive and bonus types; adds only, never updates.
        $this->call(IncentiveTypeSeeder::class);
    }
}
