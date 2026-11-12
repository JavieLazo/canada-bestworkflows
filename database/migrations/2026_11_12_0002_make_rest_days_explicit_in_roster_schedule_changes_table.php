<?php

declare(strict_types=1);

use App\Modules\Timekeeping\Application\RestDayConversion;
use App\Support\ManilaClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every day is a working day unless the employee's own schedule rests it
 * (the owner's decision, 2026-11-12): a shift pattern carries hours only.
 * The rest days a pattern's work week gave every employee on it (Monday–
 * Friday left Saturday and Sunday) are moved onto each employee as
 * explicit Rest day rows from the day this runs, so no date's schedule
 * changes — see `RestDayConversion`, which this runs with no actor.
 *
 * Steps:
 *   1. `rsc_source_chk` gains REST_DAYS, the source of the change each
 *      employee's move is recorded under (mysql/mariadb only: dropped and
 *      re-added with the full literal list while it lacks the value).
 *   2. Each employee is moved in a transaction of its own; one already
 *      moved (a default shift working every day) is skipped. Then every
 *      pattern's work week becomes all seven days.
 *
 * RETRY-SAFE: the CHECK step looks at the constraint's own text before it
 * drops or adds, and the move skips whatever a killed run already did.
 * ROLLBACK keeps the moved rows — they schedule every date exactly as
 * before — and restores the narrower CHECK only while no REST_DAYS change
 * exists; with one, it refuses, because the rows would break the CHECK.
 * Open payroll drafts whose month includes the run day go stale and need a
 * new review: the schedule rows changed although no amount does.
 */
return new class extends Migration
{
    private const SOURCES_WITH = "'BASELINE','MANUAL','IMPORT','PATTERN_EDIT','REST_DAYS'";

    private const SOURCES_WITHOUT = "'BASELINE','MANUAL','IMPORT','PATTERN_EDIT'";

    public function up(): void
    {
        if ($this->isMySql() && ! str_contains($this->checkClause('rsc_source_chk') ?? '', 'REST_DAYS')) {
            if ($this->checkClause('rsc_source_chk') !== null) {
                DB::statement('ALTER TABLE `roster_schedule_changes` DROP CONSTRAINT `rsc_source_chk`');
            }
            DB::statement('ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_source_chk` CHECK (`source` IN ('.self::SOURCES_WITH.'))');
        }
        if (Schema::hasTable('shifts') && Schema::hasTable('roster_shifts') && Schema::hasTable('roster_schedule_changes')) {
            RestDayConversion::run(ManilaClock::todayKey());
        }
    }

    public function down(): void
    {
        if (DB::table('roster_schedule_changes')->where('source', 'REST_DAYS')->exists()) {
            throw new RuntimeException('Rest days were already moved onto employees; their REST_DAYS changes would break the narrower source check. They schedule every date as before, so leave this migration in place.');
        }
        if ($this->isMySql() && str_contains($this->checkClause('rsc_source_chk') ?? '', 'REST_DAYS')) {
            DB::statement('ALTER TABLE `roster_schedule_changes` DROP CONSTRAINT `rsc_source_chk`');
            DB::statement('ALTER TABLE `roster_schedule_changes` ADD CONSTRAINT `rsc_source_chk` CHECK (`source` IN ('.self::SOURCES_WITHOUT.'))');
        }
    }

    /**
     * The CHECK's text as the server keeps it, or null when it is absent.
     * By name alone: MySQL 8's view has no TABLE_NAME column, and the name is
     * this table's own.
     */
    private function checkClause(string $name): ?string
    {
        $row = DB::selectOne('SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', [$name]);

        return $row === null ? null : (string) $row->clause;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
