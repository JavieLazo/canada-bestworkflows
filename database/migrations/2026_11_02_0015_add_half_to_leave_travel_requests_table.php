<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW COLUMN — `leave_travel_requests.half` (DtrCalculator divergence 21):
 * WHICH half of a HALF_DAY request is off, AM (the morning; the employee
 * comes in for the afternoon, so only lateness is forgiven) or PM (the
 * afternoon; only undertime is forgiven). NULL — every request filed
 * before the column, and a half day filed without saying — keeps the
 * reference's reading, where either half may be the one off and both are
 * forgiven. `ltr_half_chk` keeps the value NULL, AM or PM.
 *
 * RETRY-SAFE: the column and the CHECK each stand behind their own guard;
 * the column is appended (no `after()`) so MariaDB takes ALGORITHM=INSTANT.
 *
 * ROLLBACK LOSES which half was off: every half day reads as the
 * reference's again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if (! Schema::hasColumn('leave_travel_requests', 'half')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('half', 2)->nullable());
        }
        if ($this->isMySql() && ! $this->hasCheck('ltr_half_chk')) {
            DB::statement("ALTER TABLE `leave_travel_requests` ADD CONSTRAINT `ltr_half_chk` CHECK (`half` IS NULL OR `half` IN ('AM','PM'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('ltr_half_chk')) {
            DB::statement('ALTER TABLE `leave_travel_requests` DROP CONSTRAINT `ltr_half_chk`');
        }
        if (Schema::hasColumn('leave_travel_requests', 'half')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn('half'));
        }
    }

    private function hasCheck(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_travel_requests' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
