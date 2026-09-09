<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of `model Holiday` (prisma/schema.prisma:688-704).
 *
 * A paid non-working day, and how payroll should treat it.
 *
 * TWO unique indexes (code, and name+date), so this is one of the seven tables
 * where Laravel's upsert() is unsafe — $uniqueBy is ignored on MySQL and an
 * upsert meaning to key on (name, date) can collide on `code` and rewrite an
 * unrelated row. Seed with updateOrCreate on the intended natural unique.
 *
 * The two PostgreSQL native enums become varchar plus CHECK plus
 * App\Domain\Enums\HolidaySchedule / HolidayType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $t): void {
            $t->engine = 'InnoDB';

            $t->char('id', 26)->primary();
            $t->string('code', 40)->unique();
            $t->string('name', 160);
            $t->date('date');

            // ONE_TIME falls on that date only; ANNUAL repeats every year.
            $t->string('schedule', 20)->default('ANNUAL');

            // 'SPECIAL_NON_WORKING' is 19 characters — 30 leaves headroom.
            $t->string('type', 30)->default('REGULAR');

            // Percent of the daily rate paid for the day. (5,2) is max 999.99,
            // matching HolidayInput's .max(999).
            $t->decimal('percent', 5, 2)->default(100);

            $t->dateTime('created_at', 3);
            $t->dateTime('updated_at', 3)->nullable();

            // 160 x 4 + 3 = 643 bytes, well under the 3,072-byte DYNAMIC limit.
            $t->unique(['name', 'date'], 'holidays_name_date_unq');
            $t->index('date', 'holidays_date_idx');
        });

        $this->dynamicRowFormat('holidays');
        $this->check('holidays', 'holidays_schedule_chk', "`schedule` IN ('ONE_TIME','ANNUAL')");
        $this->check('holidays', 'holidays_type_chk', "`type` IN ('REGULAR','SPECIAL_NON_WORKING')");
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
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
