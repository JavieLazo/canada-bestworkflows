<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sentinel row of the named mutex `copems:org-structure`
 * (codes-and-locks guide, "How to add a named mutex"). Every write of the
 * department and unit structure, of a position, and of an employee's
 * `employee_profiles.position_id` takes it first through
 * `TransactionMutex::acquire()`, so the name rules no unique index can hold —
 * department names among departments, titles among Not placed positions, a
 * unit's parent being a department — are checked and written by one writer at
 * a time. `TransactionMutex` refuses with a readable sentence while the row is
 * missing, so this migration runs before any of the structure tables.
 *
 * RETRY-SAFE: inserts the row only while it is absent; a second run writes
 * nothing.
 *
 * ROLLBACK LOSES no data: it deletes the sentinel, after which every
 * structure, position and employee-position write refuses until the row is
 * back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('locks')->where('name', 'copems:org-structure')->exists()) {
            DB::table('locks')->insert(['name' => 'copems:org-structure', 'created_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        }
    }

    public function down(): void
    {
        DB::table('locks')->where('name', 'copems:org-structure')->delete();
    }
};
