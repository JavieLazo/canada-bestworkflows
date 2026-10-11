<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW TABLE — `payroll_run_member_incentives`: the incentive and bonus
 * amounts a payroll officer typed for one employee on one payroll
 * (2026-09-26, D4). One row per (member, type); the amount is always
 * positive — no row is no incentive.
 *
 * It hangs off `payroll_run_members`, like every other per-employee draft
 * figure, so it follows the member: SavePayroll deletes and re-creates the
 * members on every save and writes these lines again inside the same locked,
 * audited transaction, and the FK CASCADEs when a member (or its run) goes.
 *
 * `type_id` RESTRICTs, deliberately — the THIRD domain RESTRICT after
 * `pfs_run_fk` and `roster_locations.site_id`: a type a payroll still names
 * must not vanish from under it, and CASCADE would silently drop money from
 * a draft. DeleteIncentiveType refuses first with a readable sentence; the
 * RESTRICT is the backstop for a delete that bypasses it.
 *
 * `prmi_amount_chk` (MySQL/MariaDB only) pins amount > 0; sqlite creates no
 * CHECK and SavePayroll refuses a non-positive amount everywhere.
 *
 * RETRY-SAFE, STEP BY STEP: the create holds the columns and the primary key;
 * the unique (member, type) comes BEFORE the member FK so MySQL reuses it,
 * the type index before the type FK, and each FK and the CHECK sit behind
 * their own guard. down() drops the table only if it exists.
 *
 * ROLLBACK LOSES every incentive typed on an open payroll. Posted payslips
 * keep theirs in `payslip_incentives` and in the finalization evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_run_member_incentives')) {
            Schema::create('payroll_run_member_incentives', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->char('member_id', 26);
                $t->char('type_id', 26);
                $t->decimal('amount', 12, 2);

                // Re-created with its member on every save: no updated_at.
                $t->dateTime('created_at', 3);
            });
        }

        if (! Schema::hasIndex('payroll_run_member_incentives', 'prmi_member_type_unq')) {
            Schema::table('payroll_run_member_incentives', function (Blueprint $t): void {
                $t->unique(['member_id', 'type_id'], 'prmi_member_type_unq');
            });
        }

        if (! Schema::hasIndex('payroll_run_member_incentives', 'prmi_type_idx')) {
            Schema::table('payroll_run_member_incentives', function (Blueprint $t): void {
                $t->index('type_id', 'prmi_type_idx');
            });
        }

        if (! Schema::hasForeignKey('payroll_run_member_incentives', ['member_id'])) {
            Schema::table('payroll_run_member_incentives', function (Blueprint $t): void {
                $t->foreign('member_id')->references('id')->on('payroll_run_members')->cascadeOnDelete();
            });
        }

        if (! Schema::hasForeignKey('payroll_run_member_incentives', ['type_id'])) {
            Schema::table('payroll_run_member_incentives', function (Blueprint $t): void {
                $t->foreign('type_id')->references('id')->on('payroll_incentive_types')->restrictOnDelete();
            });
        }

        $this->dynamicRowFormat('payroll_run_member_incentives');

        if ($this->isMySql() && ! $this->hasCheck('payroll_run_member_incentives', 'prmi_amount_chk')) {
            DB::statement('ALTER TABLE `payroll_run_member_incentives` ADD CONSTRAINT `prmi_amount_chk` CHECK (`amount` > 0)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_run_member_incentives')) {
            Schema::drop('payroll_run_member_incentives');
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
