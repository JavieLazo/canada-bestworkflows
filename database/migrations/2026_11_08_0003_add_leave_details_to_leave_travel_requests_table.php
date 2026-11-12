<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE EMPLOYEE'S PART OF THE LEAVE APPLICATION, kept on the request
 * (sections 6.B and 6.D of the leave application form). `FileAbsence`
 * writes them on a leave; official business carries none.
 *
 *  - `leave_detail` VARCHAR(12): the details of leave picked for the leave
 *    type's section — WITHIN_PH or ABROAD (vacation / special privilege),
 *    IN_HOSPITAL or OUT_PATIENT (sick), ILLNESS (special leave benefits for
 *    women), MASTERS or BAR_BOARD (study). NULL for a type that asks
 *    nothing and on every request filed before this column
 *    (`ltr_leave_detail_chk`).
 *  - `leave_detail_note` VARCHAR(255): what the choice asks to be
 *    specified — where abroad, or the illness; NULL otherwise.
 *  - `commutation` VARCHAR(13): NOT_REQUESTED or REQUESTED on a leave; NULL
 *    on official business and on every request filed before this column
 *    (`ltr_commutation_chk`). It is recorded only: it changes no pay and no
 *    credits.
 *
 * Appended without ->after(), so MariaDB 11.4 takes ALGORITHM=INSTANT.
 *
 * RETRY-SAFE: each column and each CHECK stand behind their own guard.
 *
 * ROLLBACK LOSES every detail and commutation choice filed; the requests
 * themselves stay as they are.
 */
return new class extends Migration
{
    private const array CHECKS = [
        'ltr_leave_detail_chk' => "`leave_detail` IS NULL OR `leave_detail` IN ('WITHIN_PH','ABROAD','IN_HOSPITAL','OUT_PATIENT','ILLNESS','MASTERS','BAR_BOARD')",
        'ltr_commutation_chk' => "`commutation` IS NULL OR `commutation` IN ('NOT_REQUESTED','REQUESTED')",
    ];

    public function up(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if (! Schema::hasColumn('leave_travel_requests', 'leave_detail')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('leave_detail', 12)->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'leave_detail_note')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('leave_detail_note', 255)->nullable());
        }
        if (! Schema::hasColumn('leave_travel_requests', 'commutation')) {
            Schema::table('leave_travel_requests', static fn (Blueprint $t) => $t->string('commutation', 13)->nullable());
        }
        if ($this->isMySql()) {
            foreach (self::CHECKS as $name => $expression) {
                if (! $this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_travel_requests` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_travel_requests')) {
            return;
        }
        if ($this->isMySql()) {
            foreach (array_keys(self::CHECKS) as $name) {
                if ($this->hasCheck($name)) {
                    DB::statement("ALTER TABLE `leave_travel_requests` DROP CONSTRAINT `{$name}`");
                }
            }
        }
        foreach (['commutation', 'leave_detail_note', 'leave_detail'] as $column) {
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
