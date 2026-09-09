<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model ShiftType` (prisma/schema.prisma:708-735).
 *
 * Two constructs change:
 *
 *  - Clock times are char(8) holding 'HH:MM:SS', NOT MySQL TIME. src/lib/dtr.ts
 *    splits these on ":" and does its arithmetic in minutes, and it has to keep
 *    working unchanged on both sides of the API. MySQL TIME would also
 *    normalise input and accept -838:59:59..838:59:59, and the seeded night
 *    shift legitimately wraps 22:00:00 -> 06:00:00.
 *  - `work_days Int[] @default([1,2,3,4,5])` has no MySQL array type. It becomes
 *    a JSON column, which is safe here because nothing ever queries INTO it:
 *    the only reads are whole-column (src/lib/data/attendance.ts:93 and
 *    src/app/api/shifts/[employeeId]/route.ts:63). It carries NO database
 *    default — MySQL before 8.0.13 and MariaDB before 10.2.1 reject a literal
 *    default on a JSON column outright — so [1,2,3,4,5] must be supplied by the
 *    Eloquent model's $attributes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_types', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 160)->unique();

            // FIXED clocks against start/end; FLEXIBLE only owes required_hours.
            $t->string('mode', 20)->default('FIXED');

            // Scheduled days of the week, 0 Sunday to 6 Saturday.
            $t->json('work_days');

            $t->char('start_time', 8)->nullable();
            $t->char('end_time', 8)->nullable();
            $t->integer('grace_minutes')->default(15);
            $t->decimal('required_hours', 5, 2)->nullable();

            // Unpaid break window, e.g. 12:00-13:00 lunch.
            $t->char('break_start', 8)->nullable();
            $t->char('break_end', 8)->nullable();

            // Minutes deducted when the break is not punched.
            $t->integer('break_minutes')->default(60);

            // Employees must clock out and back in over the break.
            $t->boolean('requires_break_punch')->default(true);

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();
        });

        $this->dynamicRowFormat('shift_types');
        $this->check('shift_types', 'shift_types_mode_chk', "`mode` IN ('FIXED','FLEXIBLE')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_types');
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
