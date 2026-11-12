<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE EMPLOYEE WHO SIGNS A SLOT of the Application for Leave. HR picks a
 * Certified by or Approved by from Employee Records, and the form reads the
 * name and the position from that record as it stands.
 *
 *  - `employee_id` CHAR(26), nullable, to `employee_profiles`
 *    (`lsig_employee_idx`, `lsig_employee_fk`): NULL on every row on upgrade.
 *    SET NULL when the record is deleted, so the slot falls to a blank line
 *    instead of disappearing back to the officer printed on the paper.
 *
 * A row already typed keeps its `name` and `designation` until HR picks an
 * employee for it; no row is rewritten here. Appended without ->after(), so
 * MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: the column, the index and the foreign key stand behind their
 * own guards, the index before the key so MariaDB reuses it.
 *
 * ROLLBACK LOSES who was picked: those slots show a blank line until they
 * are set again. The audit trail keeps each pick.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_signatories')) {
            return;
        }
        if (! Schema::hasColumn('leave_signatories', 'employee_id')) {
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->char('employee_id', 26)->nullable());
        }
        if (! Schema::hasIndex('leave_signatories', 'lsig_employee_idx')) {
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->index('employee_id', 'lsig_employee_idx'));
        }
        if (! Schema::hasForeignKey('leave_signatories', ['employee_id'])) {
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->foreign('employee_id', 'lsig_employee_fk')->references('id')->on('employee_profiles')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_signatories')) {
            return;
        }
        if (Schema::hasForeignKey('leave_signatories', ['employee_id'])) {
            // MySQL/MariaDB drop the key by its name; sqlite only by its column.
            $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->dropForeign($mysql ? 'lsig_employee_fk' : ['employee_id']));
        }
        if (Schema::hasIndex('leave_signatories', 'lsig_employee_idx')) {
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->dropIndex('lsig_employee_idx'));
        }
        if (Schema::hasColumn('leave_signatories', 'employee_id')) {
            Schema::table('leave_signatories', static fn (Blueprint $t) => $t->dropColumn('employee_id'));
        }
    }
};
