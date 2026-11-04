<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEAVE CREDITS NEVER LAPSE. At year end the earning job carries a type's
 * WHOLE closing balance into the new year — a balance below zero included,
 * because an attendance charge past the credits held is a debt the new
 * year must not forgive. `lce_days_chk` (2026_10_31_0003) allowed only a
 * positive CARRY_FORWARD; this migration re-adds it so a CARRY_FORWARD may
 * be any non-zero figure. Nothing else in the CHECK changes.
 *
 * RETRY-SAFE: the CHECK is dropped only while its clause is still the old
 * one and added only while absent, each in its own guarded step, so a run
 * killed between the two resumes at the add. mysql/mariadb only: sqlite
 * never had the CHECK. down() restores the positive-only clause, which a
 * database holding a negative carry refuses — roll back only before the
 * first negative year end.
 */
return new class extends Migration
{
    private const string TABLE = 'leave_credit_entries';

    private const string NAME = 'lce_days_chk';

    private const string CLAUSE = "`days` BETWEEN -999999.9999 AND 999999.9999 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` = 'ACCRUAL' AND `days` > 0) OR (`kind` = 'CARRY_FORWARD' AND `days` <> 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0) OR (`kind` IN ('CHARGE','LAPSE') AND `days` < 0))";

    private const string PREVIOUS_CLAUSE = "`days` BETWEEN -999999.9999 AND 999999.9999 AND ((`kind` IN ('BASELINE','OPENING') AND `days` >= 0) OR (`kind` IN ('ACCRUAL','CARRY_FORWARD') AND `days` > 0) OR (`kind` = 'ADJUSTMENT' AND `days` <> 0) OR (`kind` IN ('CHARGE','LAPSE') AND `days` < 0))";

    public function up(): void
    {
        if (! $this->isMySql() || ! Schema::hasTable(self::TABLE)) {
            return;
        }
        if ($this->hasCheck() && ! $this->clauseAllowsNegativeCarry()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP CONSTRAINT `'.self::NAME.'`');
        }
        if (! $this->hasCheck()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::NAME.'` CHECK ('.self::CLAUSE.')');
        }
    }

    public function down(): void
    {
        if (! $this->isMySql() || ! Schema::hasTable(self::TABLE)) {
            return;
        }
        if ($this->hasCheck() && $this->clauseAllowsNegativeCarry()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` DROP CONSTRAINT `'.self::NAME.'`');
        }
        if (! $this->hasCheck()) {
            DB::statement('ALTER TABLE `'.self::TABLE.'` ADD CONSTRAINT `'.self::NAME.'` CHECK ('.self::PREVIOUS_CLAUSE.')');
        }
    }

    private function hasCheck(): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [self::TABLE, self::NAME],
        );

        return (int) ($row->n ?? 0) > 0;
    }

    /** Whether the CHECK in place already admits a negative CARRY_FORWARD (its clause names the kind alone with `<> 0`). */
    private function clauseAllowsNegativeCarry(): bool
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [self::TABLE, self::NAME],
        );
        $clause = strtolower(str_replace(['`', ' ', '"'], '', (string) ($row->c ?? '')));

        return str_contains($clause, "kind='carry_forward'anddays<>0");
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
