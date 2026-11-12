<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE. The officers named on the Application for Leave: who certifies
 * the leave credits and who approves the application. One row per slot
 * (`slot`, the constants of
 * `App\Modules\Timekeeping\Domain\Absence\LeaveSignatorySlot`), holding the
 * name and designation shown. A slot with no row shows the officer printed on
 * the paper form; a row with an empty name shows a blank line. The head of
 * office differs by office and is kept on each department or unit
 * (`2026_11_11_0002`), not here.
 *
 * `updated_by` is the account that last set the slot (nullable, SET NULL on
 * account deletion) and `updated_by_name` its name at the time.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * and MariaDB DDL does not roll back, so a run can stop between any two
 * statements and is simply run again. The create carries only the columns and
 * the primary key; the unique index, the foreign key, the row format and the
 * CHECK are their own guarded steps. On SQLite (tests) no CHECK is created.
 *
 * ROLLBACK LOSES what HR set: the form shows the printed officers again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_signatories')) {
            Schema::create('leave_signatories', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // CERTIFIED | APPROVED — free text plus lsig_slot_chk below.
                $t->string('slot', 20);

                $t->string('name', 200)->nullable();
                $t->string('designation', 200)->nullable();

                $t->unsignedBigInteger('updated_by')->nullable();
                $t->string('updated_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('leave_signatories', 'lsig_slot_unq')) {
            Schema::table('leave_signatories', function (Blueprint $t): void {
                $t->unique('slot', 'lsig_slot_unq');
            });
        }

        if (! Schema::hasForeignKey('leave_signatories', ['updated_by'])) {
            Schema::table('leave_signatories', function (Blueprint $t): void {
                $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `leave_signatories` ROW_FORMAT=DYNAMIC');
        }

        if ($this->isMySql() && ! $this->hasCheck('leave_signatories', 'lsig_slot_chk')) {
            DB::statement("ALTER TABLE `leave_signatories` ADD CONSTRAINT `lsig_slot_chk` CHECK (`slot` IN ('CERTIFIED','APPROVED'))");
        }
    }

    public function down(): void
    {
        // The CHECK, the index and the foreign key all go with the table.
        if (Schema::hasTable('leave_signatories')) {
            Schema::drop('leave_signatories');
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
