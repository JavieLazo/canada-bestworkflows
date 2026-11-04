<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT AN APPROVED LEAVE COSTS IN CREDITS IS FROZEN ON THE REQUEST. Until
 * now the ledger derived a request's cost at read time — every calendar day
 * of its range, charged to the year it starts in — so a closed year's
 * balance kept moving after its carry-forward was posted, and a leave over a
 * rest day cost a day the DTR never paid. From here on `ReviewAbsence`
 * writes, on approval with pay, what the request costs and where:
 *
 *  - `credits_year` SMALLINT: the year whose credits the request draws on —
 *    its start year moved past every year the earning job had already closed
 *    when it was approved (LeaveCharges::liveYear). NULL on rows approved
 *    before this column existed, and on unpaid leave, official business and
 *    pending rows: the ledger then reads the start year, as before.
 *  - `credit_days` DECIMAL(10,4): the scheduled working days the request
 *    covers (rest days and holidays out, 0.5 each for a half-day request),
 *    less the dates a posted payroll had already charged to credits as an
 *    absence, less any date cancelled later while the year was open. NULL
 *    means "count calendar days", the rule every older row was approved under.
 *  - `credit_dates` JSON: the dates `credit_days` counts, so a cancellation
 *    knows what to give back and a cancelled date is never counted twice.
 *
 * `ltr_credit_days_chk` (credit_days IS NULL OR credit_days >= 0) is created
 * on mysql/mariadb only. Appended without ->after(), so MariaDB 11.4 takes
 * ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column and the CHECK has its own guard. down() drops the
 * three columns and the CHECK: every approval goes back to calendar-day,
 * start-year counting, and the cancellations recorded after a year closed
 * keep their ADJUSTMENT ledger entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_travel_requests', 'credits_year')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->unsignedSmallInteger('credits_year')->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'credit_days')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->decimal('credit_days', 10, 4)->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'credit_dates')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->json('credit_dates')->nullable());
        }
        if ($this->isMySql() && ! $this->hasCheck('leave_travel_requests', 'ltr_credit_days_chk')) {
            DB::statement('ALTER TABLE `leave_travel_requests` ADD CONSTRAINT `ltr_credit_days_chk` CHECK (`credit_days` IS NULL OR `credit_days` >= 0)');
        }
    }

    public function down(): void
    {
        if ($this->isMySql() && $this->hasCheck('leave_travel_requests', 'ltr_credit_days_chk')) {
            DB::statement('ALTER TABLE `leave_travel_requests` DROP CONSTRAINT `ltr_credit_days_chk`');
        }
        foreach (['credit_dates', 'credit_days', 'credits_year'] as $column) {
            if (Schema::hasColumn('leave_travel_requests', $column)) {
                Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
