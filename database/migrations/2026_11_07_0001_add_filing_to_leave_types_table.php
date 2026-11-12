<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOW A LEAVE TYPE IS FILED, set by HR on Leave Settings for every employee:
 *
 *  - `filing` VARCHAR(8): CREDITS (deducted from the employee's earned
 *    credits of the type — the default, every type before this column),
 *    YEARLY (a set number of days each calendar year), OCCASION (a set
 *    number of days each time it is filed) or UNPAID (always without pay,
 *    no credits). Whatever the credits or the set days do not cover is
 *    approved without pay. `lt_filing_chk` pins the four values.
 *  - `allowance_days` DECIMAL(10,4): the set number of days of a YEARLY or
 *    OCCASION type; NULL for the other two (`lt_allowance_chk`).
 *  - `day_count` VARCHAR(8): WORKING (the employee's scheduled working days,
 *    holidays out — the default) or CALENDAR (every calendar day of the
 *    range), the days a request is counted in (`lt_day_count_chk`).
 *
 * Every type whose name reads "without pay" is set to UNPAID, the reading
 * the name check gave it until now; every other type stays CREDITS, so
 * nothing changes on upgrade. Columns appended without ->after(), so
 * MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column, the backfill (an UPDATE that matches nothing the
 * second time) and each CHECK stand behind their own guard.
 *
 * ROLLBACK LOSES how each type is filed and its set days: every type is
 * read again as earned credits, and "without pay" by its name.
 */
return new class extends Migration
{
    private const array CHECKS = [
        'lt_filing_chk' => "`filing` IN ('CREDITS','YEARLY','OCCASION','UNPAID')",
        'lt_day_count_chk' => "`day_count` IN ('WORKING','CALENDAR')",
        'lt_allowance_chk' => "(`filing` IN ('YEARLY','OCCASION') AND `allowance_days` > 0 AND `allowance_days` <= 999999.9999) OR (`filing` IN ('CREDITS','UNPAID') AND `allowance_days` IS NULL)",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if (! Schema::hasColumn('leave_types', 'filing')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('filing', 8)->default('CREDITS'));
        }
        if (! Schema::hasColumn('leave_types', 'allowance_days')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->decimal('allowance_days', 10, 4)->nullable());
        }
        if (! Schema::hasColumn('leave_types', 'day_count')) {
            Schema::table('leave_types', static fn (Blueprint $t) => $t->string('day_count', 8)->default('WORKING'));
        }
        DB::table('leave_types')->where('filing', 'CREDITS')->whereRaw('LOWER(name) LIKE ?', ['%without pay%'])->update(['filing' => 'UNPAID']);
        if ($this->isMySql()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_types` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_types')) {
            return;
        }
        if ($this->isMySql()) {
            foreach (array_keys(self::CHECKS) as $name) {
                if ($this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_types` DROP CONSTRAINT `{$name}`");
                }
            }
        }
        foreach (['day_count', 'allowance_days', 'filing'] as $column) {
            if (Schema::hasColumn('leave_types', $column)) {
                Schema::table('leave_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_types' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
