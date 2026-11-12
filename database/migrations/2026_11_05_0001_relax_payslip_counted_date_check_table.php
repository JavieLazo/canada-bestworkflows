<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PAYSLIP'S COUNTED DATE MAY BE THE DAY BEFORE ITS PERIOD. On an advance
 * payroll each member's counted date is the run's pulled back to the day
 * before their first unfinished day (PayrollAdvance::countedThrough), so a
 * member whose first counted day is a Time In without a Time Out is counted
 * through the day before the period: every day of theirs is presumed and
 * settled on their next payroll. `psl_through_chk` (2026_10_31_0001) held
 * the date inside the period and would refuse that payslip — on MariaDB
 * only, inside the finalize transaction, with nothing posted.
 *
 * The CHECK is replaced by one that allows exactly one day before the
 * period: `attendance_through >= period_start - INTERVAL 1 DAY`. The upper
 * bound is unchanged (strictly before `period_end`: the period's last day
 * means no advance and is stored as NULL). The run's own date
 * (`payroll_runs.attendance_through`) stays inside the period: PayrollAdvance
 * refuses anything else before a run is saved or finalized.
 *
 * RETRY-SAFE: the drop and the add are guarded on their own, so a run
 * stopped between them resumes at the add. Nothing rewrites rows. down()
 * puts the original CHECK back, which fails while a payslip counted through
 * the day before its period exists — that row is posted evidence and is not
 * rewritten here. mysql/mariadb only: sqlite never sees a CHECK.
 */
return new class extends Migration
{
    private const string CHECK = 'psl_through_chk';

    public function up(): void
    {
        if (! $this->isMySql() || ! Schema::hasColumn('payslips', 'attendance_through')) {
            return;
        }
        if ($this->hasCheck('payslips', self::CHECK) && ! $this->checkAllowsDayBefore()) {
            DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `'.self::CHECK.'`');
        }
        if (! $this->hasCheck('payslips', self::CHECK)) {
            DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `'.self::CHECK.'` CHECK (`attendance_through` IS NULL OR (`attendance_through` >= `period_start` - INTERVAL 1 DAY AND `attendance_through` < `period_end`))');
        }
    }

    public function down(): void
    {
        if (! $this->isMySql() || ! Schema::hasColumn('payslips', 'attendance_through')) {
            return;
        }
        if ($this->hasCheck('payslips', self::CHECK) && $this->checkAllowsDayBefore()) {
            DB::statement('ALTER TABLE `payslips` DROP CONSTRAINT `'.self::CHECK.'`');
        }
        if (! $this->hasCheck('payslips', self::CHECK)) {
            DB::statement('ALTER TABLE `payslips` ADD CONSTRAINT `'.self::CHECK.'` CHECK (`attendance_through` IS NULL OR (`attendance_through` >= `period_start` AND `attendance_through` < `period_end`))');
        }
    }

    /** Whether the CHECK in place is already the relaxed one (its clause names the interval). */
    private function checkAllowsDayBefore(): bool
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['payslips', self::CHECK],
        );

        return is_string($row->clause ?? null) && str_contains(strtolower($row->clause), 'interval 1 day');
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
