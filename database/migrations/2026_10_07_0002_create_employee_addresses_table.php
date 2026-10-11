<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * An employee's addresses in parts, as the Personal Data Sheet asks for them
 * (DELIBERATE DIVERGENCE: the reference record has one free-text address):
 * House/Block/Lot No., Street, Subdivision/Village, Barangay,
 * City/Municipality, Province and ZIP code. `type` is RESIDENTIAL | PERMANENT
 * (varchar plus `eaddr_type_chk`), and `eaddr_employee_type_unq` allows at
 * most one row of each type per employee. Every part is optional.
 *
 * A type's row is created when it gets its first part, updated in place when
 * its parts change, and deleted when every part is blank. PERMANENT has no
 * row while the employee's `employee_pds_details.permanent_address_same`
 * (2026_10_07_0001) says "same as residential". The legacy one-line
 * `employee_profiles.address` is never copied, split or rewritten: reads show
 * it until the address is typed in parts.
 *
 * The rows are written only by the Workforce commands, inside the transaction
 * that saves the employee's record, and read only through `EmployeeRecords`,
 * behind the record's own visibility rule; nothing else joins, counts or
 * reports on them. So they carry no actor scope, like `employee_children`. A
 * writer locks the employee's `employee_profiles` row first, reads the rows
 * here plainly and writes by difference — an update or delete by primary key,
 * an insert for a type that has no row — never a locking read or a delete
 * that may match nothing: on InnoDB either one gap-locks
 * `eaddr_employee_type_unq`, and two first saves for different employees
 * would then deadlock.
 *
 * `employee_id` cascades like `employee_children`: the addresses are part of
 * the record and go with it. `eaddr_employee_type_unq` also serves every
 * per-employee read by its prefix; it is declared BEFORE the foreign key so
 * MySQL/MariaDB reuse it for the constraint.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can die between statements, and MariaDB DDL does not roll back, so the
 * create carries only the columns and the primary key, and every other piece
 * is its own ALTER behind its own guard: the unique only while hasIndex()
 * does not list its name, the foreign key only while hasForeignKey() finds
 * none on its column, and the CHECK only while
 * information_schema.TABLE_CONSTRAINTS lists no constraint of that name.
 * ROW_FORMAT=DYNAMIC restates the same format on a retry and needs no guard.
 * On sqlite (tests) the foreign key is a table rebuild that carries the index
 * over, and no CHECK is created. down() drops the table, and the index, key
 * and CHECK with it, only if it exists.
 *
 * ROLLBACK LOSES every address typed in parts since: nothing else in the
 * system holds them. The legacy one-line `employee_profiles.address` is
 * untouched either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_addresses')) {
            Schema::create('employee_addresses', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);

                // RESIDENTIAL | PERMANENT — free text plus eaddr_type_chk.
                $t->string('type', 12);

                $t->string('house_no', 60)->nullable();
                $t->string('street', 120)->nullable();
                $t->string('subdivision', 120)->nullable();
                $t->string('barangay', 120)->nullable();
                $t->string('city', 120)->nullable();
                $t->string('province', 120)->nullable();
                $t->string('zip_code', 10)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Each step below is checked on its own, not under the table check: a
        // run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('employee_addresses', 'eaddr_employee_type_unq')) {
            Schema::table('employee_addresses', function (Blueprint $t): void {
                $t->unique(['employee_id', 'type'], 'eaddr_employee_type_unq');
            });
        }

        if (! Schema::hasForeignKey('employee_addresses', ['employee_id'])) {
            Schema::table('employee_addresses', function (Blueprint $t): void {
                $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            });
        }

        if ($this->isMySql() && ! $this->hasCheck('employee_addresses', 'eaddr_type_chk')) {
            DB::statement("ALTER TABLE `employee_addresses` ADD CONSTRAINT `eaddr_type_chk` CHECK (`type` IN ('RESIDENTIAL','PERMANENT'))");
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('employee_addresses');
    }

    public function down(): void
    {
        // The index, the foreign key and the CHECK go with the table. Guarded
        // like every step in up(), so a repeated rollback runs no DDL at all.
        if (Schema::hasTable('employee_addresses')) {
            Schema::drop('employee_addresses');
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
