<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE RECOMMENDATION ON A PENDING REQUEST (the leave application's section
 * 7.B): a leave manager records "For approval" or "For disapproval due to
 * …" before anyone decides it. `RecommendAbsence` is the one writer; the
 * request stays PENDING, and it may be decided with or without one.
 *
 *  - `recommendation` VARCHAR(16): FOR_APPROVAL or FOR_DISAPPROVAL; NULL
 *    while none is recorded, and on every request before this column
 *    (`ltr_recommendation_chk`).
 *  - `recommendation_reason` VARCHAR(1000): why disapproval is recommended;
 *    NULL on a recommendation for approval.
 *  - `recommended_by` (users, SET NULL, `ltr_recommended_by_fk`, like
 *    `reviewed_by`; Timekeeping's AccountReferences keeps the account) and
 *    `recommended_at` DATETIME(3), UTC: who recorded it and when. The CHECK
 *    does not name `recommended_by`: MariaDB 11.4 refuses a CHECK on a SET
 *    NULL key column.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column, the foreign key and the CHECK stand behind their
 * own guard.
 *
 * ROLLBACK LOSES every recommendation recorded and who recorded it; the
 * decisions themselves stay. The audit trail keeps each recommendation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if (! Schema::hasColumn('leave_travel_requests', 'recommendation')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('recommendation', 16)->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'recommendation_reason')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('recommendation_reason', 1000)->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'recommended_by')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->unsignedBigInteger('recommended_by')->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'recommended_at')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dateTime('recommended_at', 3)->nullable());
        }
        if (! Schema::hasForeignKey('leave_travel_requests', ['recommended_by'])) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->foreign('recommended_by', 'ltr_recommended_by_fk')->references('id')->on('users')->nullOnDelete());
        }
        if ($this->isMySql() && ! $this->hasCheck('ltr_recommendation_chk')) {
            DB::statement("ALTER TABLE `leave_travel_requests` ADD CONSTRAINT `ltr_recommendation_chk` CHECK (`recommendation` IS NULL OR `recommendation` IN ('FOR_APPROVAL','FOR_DISAPPROVAL'))");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if ($this->isMySql() && $this->hasCheck('ltr_recommendation_chk')) {
            DB::statement('ALTER TABLE `leave_travel_requests` DROP CONSTRAINT `ltr_recommendation_chk`');
        }
        if (Schema::hasForeignKey('leave_travel_requests', ['recommended_by'])) {
            // MySQL/MariaDB drop the key by its name; sqlite only by its column.
            $mysql = $this->isMySql();
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropForeign($mysql ? 'ltr_recommended_by_fk' : ['recommended_by']));
        }
        foreach (['recommended_at', 'recommended_by', 'recommendation_reason', 'recommendation'] as $column) {
            if (Schema::hasColumn('leave_travel_requests', $column)) {
                Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn($column));
            }
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
