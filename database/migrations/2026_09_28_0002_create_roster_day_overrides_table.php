<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A date-specific exception independently replaces weekly hours or place.
 * No existing records change. Copied hours and name survive pattern deletion
 * through SET NULL; employee deletion cascades this configuration. Site
 * RESTRICT avoids silently changing an onsite exception into an invalid or
 * different place; Timekeeping SiteReferences supplies the readable refusal.
 * MariaDB forbids SET NULL foreign-key columns in CHECK expressions. The
 * shift CHECK validates the optional snapshot and rest payload instead;
 * SaveRosterDate clears the live pattern link for REST and weekly fallback.
 *
 * RETRY-SAFE: every CREATE, index, foreign key and CHECK has its own guard,
 * while repeating ROW_FORMAT=DYNAMIC is harmless. Indexes precede foreign
 * keys. Rollback LOSES date exceptions only and restores weekly behavior;
 * captured punches and finalized payroll evidence stay unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_day_overrides')) {
            Schema::create('roster_day_overrides', function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 26)->primary();
                $t->char('employee_id', 26);
                $t->date('date');
                $t->char('shift_type_id', 26)->nullable();
                $t->string('shift_name', 160)->nullable();
                $t->boolean('is_rest_day')->default(false);
                $t->json('shift_data')->nullable();
                $t->string('location_kind', 10)->nullable();
                $t->char('site_id', 26)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        // STANDS DOWN once 2026_09_30_0007 has made the rows periods
        // (owner-approved edit, 2026-09-30): that migration retires this
        // unique, and a re-run here must not bring it back.
        if (! Schema::hasIndex('roster_day_overrides', 'rdate_employee_date_unq')
            && ! Schema::hasColumn('roster_day_overrides', 'end_date')) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->unique(['employee_id', 'date'], 'rdate_employee_date_unq'));
        }
        if (! Schema::hasIndex('roster_day_overrides', 'rdate_type_idx')) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->index('shift_type_id', 'rdate_type_idx'));
        }
        if (! Schema::hasIndex('roster_day_overrides', 'rdate_site_idx')) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->index('site_id', 'rdate_site_idx'));
        }
        if (! Schema::hasForeignKey('roster_day_overrides', ['employee_id'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete());
        }
        if (! Schema::hasForeignKey('roster_day_overrides', ['shift_type_id'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('shift_type_id')->references('id')->on('shift_types')->nullOnDelete());
        }
        if (! Schema::hasForeignKey('roster_day_overrides', ['site_id'])) {
            Schema::table('roster_day_overrides', static fn (Blueprint $t) => $t->foreign('site_id')->references('id')->on('work_sites')->restrictOnDelete());
        }
        $this->dynamicRowFormat();
        if ($this->isMySql() && ! $this->hasCheck('rdate_shift_chk')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_shift_chk` CHECK (
                    `is_rest_day` IN (0,1) AND (
                        (`shift_data` IS NULL AND `shift_name` IS NULL AND `is_rest_day` = 0)
                        OR (`shift_data` IS NOT NULL AND `shift_name` IS NOT NULL AND (`is_rest_day` = 0 OR (
                            COALESCE(JSON_EXTRACT(`shift_data`, '$.work_days') = JSON_ARRAY(-1), 0)
                            AND COALESCE(JSON_TYPE(JSON_EXTRACT(`shift_data`, '$.shift_type_id')) = 'NULL', 0)
                        )))
                    )
                )
                SQL);
        }
        if ($this->isMySql() && ! $this->hasCheck('rdate_location_chk')) {
            DB::statement("ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_location_chk` CHECK ((`location_kind` IS NULL AND `site_id` IS NULL) OR (`location_kind` IS NOT NULL AND ((`location_kind` = 'WFH' AND `site_id` IS NULL) OR (`location_kind` = 'ONSITE' AND `site_id` IS NOT NULL))))");
        }
        if ($this->isMySql() && ! $this->hasCheck('rdate_override_chk')) {
            DB::statement('ALTER TABLE `roster_day_overrides` ADD CONSTRAINT `rdate_override_chk` CHECK (`shift_data` IS NOT NULL OR `location_kind` IS NOT NULL)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('roster_day_overrides')) {
            Schema::drop('roster_day_overrides');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'roster_day_overrides' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name]);

        return (int) ($row->n ?? 0) > 0;
    }

    private function dynamicRowFormat(): void
    {
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `roster_day_overrides` ROW_FORMAT=DYNAMIC');
        }
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
