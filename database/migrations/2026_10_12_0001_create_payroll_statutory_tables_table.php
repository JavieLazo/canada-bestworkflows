<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * NEW TABLE — it has no counterpart in prisma/schema.prisma.
 *
 * The Contribution & Tax Tables: the SSS, PhilHealth, Pag-IBIG and withholding
 * figures payroll computes from, kept as dated VERSIONS instead of constants in
 * code. One row is one version of one table (`kind`): the day it takes effect
 * (`effective_from`), the last day it is known to be good for
 * (`covered_through`), an optional reference to where the figures came from,
 * and the figures themselves (`params`, the shape
 * `App\Domain\Payroll\StatutorySchedule` reads). A payroll period is priced by
 * the version in force on its first day, and refused when no version covers
 * it or a version changes inside it; a finalized payroll keeps the figures it
 * was computed with in its evidence, never a pointer here.
 *
 * `created_by` is the account that added the version (nullable, SET NULL on
 * account deletion) and `created_by_name` its name at the time, denormalised
 * like `audit_logs.actor_name` so the version keeps its attribution after the
 * account is gone. `params` is JSON, the shape `payroll_formulas.params` set.
 *
 * DATA: four rows, one per kind, are INSERTED with the figures the engine
 * carried as constants — effective 2025-01-01 and covered through 2026-12-31,
 * the dates `Statutory::EFFECTIVE_FROM` / `VERIFIED_THROUGH` held — so every
 * installation keeps computing exactly what it computed before this table
 * existed. A migration must never change payroll money on its own; the
 * figures below are literal copies of the engine's, and
 * StatutoryTablesTest replays every golden statutory vector through them.
 * Each row is inserted only while its kind has no row at all, so a re-run
 * and an installation where someone already added versions are left alone.
 *
 * RETRY-SAFE, STEP BY STEP. Production migrates from a temporary cPanel cron
 * with no SSH and MySQL DDL does not roll back, so a run can stop between any
 * two statements and is simply run again. The create carries only the columns
 * and the primary key; the unique index, the foreign key, the row format and
 * each CHECK are their own guarded ALTERs, and the seeded rows are inserted
 * per kind only while absent. On SQLite (tests) the foreign key is a table
 * rebuild and no CHECK is created.
 *
 * ROLLBACK LOSES every table version, including the seeded ones: payroll then
 * computes from the engine's built-in figures again and refuses periods past
 * 2026-12-31, exactly as before this migration.
 */
return new class extends Migration
{
    /** Each kind's built-in figures, as the engine carried them. Literal on purpose: a migration never reads live code. */
    private const array SEEDED = [
        'SSS' => ['msc_min' => 5000.0, 'msc_max' => 35000.0, 'step' => 500.0, 'offset' => 250.0, 'ee_rate' => 0.05, 'er_rate' => 0.1, 'ec_threshold' => 14500.0, 'ec_low' => 10.0, 'ec_high' => 30.0],
        'PHILHEALTH' => ['floor' => 10000.0, 'cap' => 100000.0, 'ee_rate' => 0.025, 'er_rate' => 0.025],
        'PAGIBIG' => ['cap' => 10000.0, 'low_line' => 1500.0, 'ee_low_rate' => 0.01, 'ee_rate' => 0.02, 'er_rate' => 0.02],
        'WITHHOLDING_TAX' => [
            'semi_monthly' => [[10417.0, 0.0, 0.15], [16667.0, 937.5, 0.2], [33333.0, 4270.7, 0.25], [83333.0, 16770.7, 0.3], [333333.0, 91770.7, 0.35]],
            'annual' => [[250000.0, 0.0, 0.15], [400000.0, 22500.0, 0.2], [800000.0, 102500.0, 0.25], [2000000.0, 402500.0, 0.3], [8000000.0, 2202500.0, 0.35]],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payroll_statutory_tables')) {
            Schema::create('payroll_statutory_tables', function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();

                // SSS | PHILHEALTH | PAGIBIG | WITHHOLDING_TAX — free text plus pst_kind_chk below.
                $t->string('kind', 20);

                $t->date('effective_from');
                $t->date('covered_through');

                $t->string('reference', 160)->nullable();

                $t->json('params');

                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 200)->nullable();

                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }

        // Every step below is checked on its own, not under the table check:
        // a run that stopped after the create has the table and none of them.
        if (! Schema::hasIndex('payroll_statutory_tables', 'pst_kind_from_unq')) {
            Schema::table('payroll_statutory_tables', function (Blueprint $t): void {
                $t->unique(['kind', 'effective_from'], 'pst_kind_from_unq');
            });
        }

        if (! Schema::hasForeignKey('payroll_statutory_tables', ['created_by'])) {
            Schema::table('payroll_statutory_tables', function (Blueprint $t): void {
                $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Idempotent: a retry restates the same row format.
        $this->dynamicRowFormat('payroll_statutory_tables');

        if ($this->isMySql() && ! $this->hasCheck('payroll_statutory_tables', 'pst_kind_chk')) {
            DB::statement("ALTER TABLE `payroll_statutory_tables` ADD CONSTRAINT `pst_kind_chk` CHECK (`kind` IN ('SSS','PHILHEALTH','PAGIBIG','WITHHOLDING_TAX'))");
        }

        if ($this->isMySql() && ! $this->hasCheck('payroll_statutory_tables', 'pst_span_chk')) {
            DB::statement('ALTER TABLE `payroll_statutory_tables` ADD CONSTRAINT `pst_span_chk` CHECK (`covered_through` >= `effective_from`)');
        }

        $this->seedBuiltInVersions();
    }

    public function down(): void
    {
        // The CHECKs, the index and the foreign key all go with the table.
        if (Schema::hasTable('payroll_statutory_tables')) {
            Schema::drop('payroll_statutory_tables');
        }
    }

    /**
     * One version per kind with the engine's built-in figures, only while
     * that kind has no version at all.
     */
    private function seedBuiltInVersions(): void
    {
        foreach (self::SEEDED as $kind => $figures) {
            if (DB::table('payroll_statutory_tables')->where('kind', $kind)->exists()) {
                continue;
            }

            DB::table('payroll_statutory_tables')->insert([
                'id' => (string) Str::ulid(),
                'kind' => $kind,
                'effective_from' => '2025-01-01',
                'covered_through' => '2026-12-31',
                'reference' => null,
                'params' => json_encode($figures, JSON_THROW_ON_ERROR),
                'created_by' => null,
                'created_by_name' => null,
                'created_at' => now('UTC')->format('Y-m-d H:i:s.v'),
                'updated_at' => null,
            ]);
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
