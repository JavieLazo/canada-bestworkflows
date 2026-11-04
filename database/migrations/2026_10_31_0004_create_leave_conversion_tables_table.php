<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MINUTES TO FRACTIONS OF A DAY, as a dated table HR keeps. Each version
 * holds the hours a day has, the decimals and rounding rule the formula
 * uses, and every minute (1 to 60) and hour row as a fraction of a day in
 * `rows` (JSON: `{"minutes": {"1": "0.0020", ...}, "hours": {...}}`),
 * with a reference. A version is prepared as a DRAFT by one person and
 * APPROVED by another; only approved versions convert anything, from
 * their effective date on, and an approved version never changes.
 *
 * RETRY-SAFE: CREATE, each index and the MariaDB/MySQL CHECKs have their
 * own guard. down() drops the table and with it every version, which an
 * attendance charge already posted no longer needs (the charge keeps the
 * days it converted to).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_conversion_tables')) {
            Schema::create('leave_conversion_tables', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';

                $t->char('id', 26)->primary();
                $t->date('effective_from');
                $t->decimal('hours_per_day', 4, 2);
                $t->unsignedTinyInteger('decimals');
                $t->string('rounding', 10);
                $t->json('rows');
                $t->string('reference', 160)->nullable();
                $t->string('status', 10);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->string('created_by_name', 350)->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->string('approved_by_name', 350)->nullable();
                $t->dateTime('approved_at', 3)->nullable();
                $t->dateTime('created_at', 3);
                $t->dateTime('updated_at', 3)->nullable();
            });
        }
        if (! Schema::hasIndex('leave_conversion_tables', 'lct_from_unq')) {
            Schema::table('leave_conversion_tables', static fn (Blueprint $t) => $t->unique('effective_from', 'lct_from_unq'));
        }
        if (! Schema::hasIndex('leave_conversion_tables', 'lct_status_idx')) {
            Schema::table('leave_conversion_tables', static fn (Blueprint $t) => $t->index(['status', 'effective_from'], 'lct_status_idx'));
        }
        if ($this->isMySql()) {
            DB::statement('ALTER TABLE `leave_conversion_tables` ROW_FORMAT=DYNAMIC');
            foreach ([
                'lct_status_chk' => "`status` IN ('DRAFT','APPROVED')",
                'lct_rounding_chk' => "`rounding` IN ('HALF_UP','HALF_DOWN','UP','DOWN')",
                'lct_hours_chk' => '`hours_per_day` > 0 AND `hours_per_day` <= 24',
                'lct_decimals_chk' => '`decimals` BETWEEN 0 AND 6',
                'lct_approval_chk' => "(`status` = 'DRAFT' AND `approved_at` IS NULL) OR (`status` = 'APPROVED' AND `approved_at` IS NOT NULL)",
            ] as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_conversion_tables` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_conversion_tables')) {
            Schema::drop('leave_conversion_tables');
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_conversion_tables' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
