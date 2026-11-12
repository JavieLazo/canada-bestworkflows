<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a named deduction line be ₱0.00: `prmd_amount_chk` and
 * `psd_amount_chk` become `amount >= 0`.
 *
 * A percentage deduction can carry an employer share with no employee share:
 * a row with only an employer share, or a member whose share was already
 * collected for the cut-off and has 0 typed on the payroll. The line has to
 * post to keep the employer's figure, and the old `amount > 0` refused it, so
 * the whole finalize failed. SavePayroll still takes a typed 0 only on a
 * percentage row. No rows are rewritten.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * that can be killed between any two statements, and MySQL DDL does not roll
 * back. Each table's change is two ALTERs with two guards: the DROP only
 * while the constraint still says `> 0`, the ADD only while no constraint of
 * that name exists. A run killed between them resumes at the ADD; a finished
 * run does nothing. sqlite creates no CHECK, so there it is a no-op.
 *
 * ROLLBACK LOSES NOTHING, AND MAY NOT TIGHTEN. down() restores `> 0` only on
 * a table with no ₱0 line: MariaDB would refuse the constraint, and deleting
 * a posted line would rewrite finalized evidence. A table that has one keeps
 * `>= 0`, and the reason goes to the error log.
 */
return new class extends Migration
{
    /** @var array<string, string> table => constraint */
    private const array CHECKS = [
        'payroll_run_member_deductions' => 'prmd_amount_chk',
        'payslip_deductions' => 'psd_amount_chk',
    ];

    public function up(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::CHECKS as $table => $name) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $clause = $this->clause($name);
            if ($clause !== null && ! str_contains($clause, '>=')) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
            }
            if ($this->clause($name) === null) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK (`amount` >= 0)");
            }
        }
    }

    public function down(): void
    {
        if (! $this->isMySql()) {
            return;
        }

        foreach (self::CHECKS as $table => $name) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->where('amount', 0)->exists()) {
                Log::error("Migration rollback kept {$name} at amount >= 0: {$table} holds a zero-amount line.");

                continue;
            }
            $clause = $this->clause($name);
            if ($clause !== null && str_contains($clause, '>=')) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
            }
            if ($this->clause($name) === null) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK (`amount` > 0)");
            }
        }
    }

    /** The constraint's text as the server holds it, or null while it is absent. */
    private function clause(string $name): ?string
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS clause_text FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$name],
        );

        return $row === null ? null : (string) $row->clause_text;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
