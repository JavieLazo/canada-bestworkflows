<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Shift` (prisma/schema.prisma:291-317).
 *
 * One row per employee — the shift they are rostered on. The pattern's hours
 * are COPIED here when it is assigned, so a later edit to the pattern does not
 * change posted payroll.
 *
 * No created_at and no updated_at: the source model has neither, so the
 * Eloquent model must carry `public $timestamps = false`.
 *
 * The defaults on start_time / end_time and the [1,2,3,4,5] on work_days differ
 * in kind: the char(8) columns can carry a real database default, the JSON
 * column cannot (see the shift_types migration), so work_days must be supplied
 * by the Eloquent model's $attributes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->char('employee_id', 26);

            // The Shifting Settings pattern this was assigned from, when there
            // is one. SET NULL so deleting a pattern does not unroster anyone.
            $t->char('shift_type_id', 26)->nullable();

            // Copied from the pattern so later pattern edits do not change
            // posted payroll.
            $t->string('mode', 20)->default('FIXED');
            $t->decimal('required_hours', 5, 2)->nullable();

            // Scheduled days of the week, 0 Sunday to 6 Saturday. Everything
            // else is a rest day, and this is what the monthly rate is divided
            // by to get a daily rate — so a Mon-Sat employee's day is worth less
            // than a Mon-Fri one's.
            $t->json('work_days');

            $t->char('start_time', 8)->default('08:00:00');
            $t->char('end_time', 8)->default('17:00:00');
            $t->integer('grace_minutes')->default(15);
            $t->char('break_start', 8)->nullable();
            $t->char('break_end', 8)->nullable();

            // Deducted from worked time when the break was never punched.
            $t->integer('break_minutes')->default(0);
            $t->boolean('requires_break_punch')->default(false);

            $t->unique('employee_id', 'shifts_employee_unq');

            $t->foreign('employee_id')->references('id')->on('employee_profiles')->cascadeOnDelete();
            $t->foreign('shift_type_id')->references('id')->on('shift_types')->nullOnDelete();
        });

        $this->dynamicRowFormat('shifts');
        $this->check('shifts', 'shifts_mode_chk', "`mode` IN ('FIXED','FLEXIBLE')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
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

    private function check(string $table, string $name, string $expression): void
    {
        if (! $this->isMySql()) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
