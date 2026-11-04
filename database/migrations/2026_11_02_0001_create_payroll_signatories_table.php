<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * The signatories printed on payroll documents: who signs the payslip and
 * the payroll register as "Prepared by", "Checked by" and "Certified correct
 * by". One row per signature slot (`slot`, the constants of
 * `App\Modules\Payroll\Domain\Signatories\SignatorySlot`), holding the name
 * and designation to print; a slot with no row, or an empty one, prints as a
 * blank line to be filled by hand. Nothing here approves a payroll: the
 * signatures are collected on paper, and the system only prints who signs.
 *
 * `updated_by` is the account that last set the slot (nullable, SET NULL on
 * account deletion) and `updated_by_name` its name at the time, denormalised
 * like `audit_logs.actor_name`.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. The create carries only the columns
 * and the primary key; the unique index, the foreign key, the row format and
 * the CHECK are their own guarded steps. On SQLite (tests) no CHECK is created.
 *
 * ROLLBACK LOSES the three names and designations; the documents then print
 * blank signature lines, exactly as before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_signatories')) {
            Schema::create('payroll_signatories', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // PREPARED | CHECKED | CERTIFIED — free text plus psig_slot_chk below.
                $t->string('slot', 20);

                $t->string('name', 200)->nullable();
                $t->string('designation', 200)->nullable();

                $t->unsignedBigInteger('updated_by')->nullable();
                $t->string('updated_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        if (! Schema::hasIndex('payroll_signatories', 'psig_slot_unq')) {
            Schema::table('payroll_signatories', function (Blueprint $t): void {
                $t->unique('slot', 'psig_slot_unq');
            });
        }

        if (! Schema::hasForeignKey('payroll_signatories', ['updated_by'])) {
            Schema::table('payroll_signatories', function (Blueprint $t): void {
                $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('payroll_signatories');

        if ($this->isMySql() && ! $this->hasCheck('payroll_signatories', 'psig_slot_chk')) {
            DB::statement("ALTER TABLE `payroll_signatories` ADD CONSTRAINT `psig_slot_chk` CHECK (`slot` IN ('PREPARED','CHECKED','CERTIFIED'))");
        }
    }

    public function down(): void
    {
        // The CHECK, the index and the foreign key all go with the table.
        if (Schema::hasTable('payroll_signatories')) {
            Schema::drop('payroll_signatories');
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

    private function dynamicRowFormat(string ...$tables): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
