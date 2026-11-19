<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AN EARNING CATEGORY ALWAYS SAYS HOW IT IS TAXED. `pac_tax_chk`
 * (2026_11_02_0005) was written to refuse an EARNING category with no tax
 * treatment, but its clause compared the empty value with `IN (...)`: for
 * a NULL that comparison is UNKNOWN, not false, and a CHECK refuses a row
 * only when its clause is false. So the row was let in. This migration
 * re-adds the CHECK with `tax IS NOT NULL` spelled out; nothing else in
 * it changes.
 *
 * The app never saved such a category (`AdjustmentRule::categoryError`
 * refuses one first), so on an installation this is a rule for writes
 * that go around the app. A row that does exist is settled first, or the
 * new CHECK could not be added: its `tax` becomes NOT_TAXED, which is how
 * payroll already read it (only TAXABLE joins the taxable pay), so no pay
 * figure moves. `updated_at` is left as it was.
 *
 * RETRY-SAFE: the backfill touches only rows still without a tax; the
 * CHECK is dropped only while its clause is still the old one and added
 * only while absent, each in its own guarded step, so a run killed
 * between the two resumes at the add. The CHECK steps are mysql/mariadb
 * only: sqlite never had the CHECK. down() restores the earlier clause and
 * rewrites no row — a backfilled NOT_TAXED stays, and is valid under both.
 */
return new class extends Migration
{
    private const string TABLE = 'payroll_adjustment_categories';

    private const string NAME = 'pac_tax_chk';

    private const string CLAUSE = "(`kind` = 'EARNING' AND `tax` IS NOT NULL AND `tax` IN ('TAXABLE','NOT_TAXED')) OR (`kind` = 'DEDUCTION' AND `tax` IS NULL)";

    private const string PREVIOUS_CLAUSE = "(`kind` = 'EARNING' AND `tax` IN ('TAXABLE','NOT_TAXED')) OR (`kind` = 'DEDUCTION' AND `tax` IS NULL)";

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        DB::table(self::TABLE)->where('kind', 'EARNING')->whereNull('tax')->update(['tax' => 'NOT_TAXED']);

        if (! $this->isMySql()) {
            return;
        }
        if ($this->hasCheck() && ! $this->clauseRefusesEmptyTax()) {
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
        if ($this->hasCheck() && $this->clauseRefusesEmptyTax()) {
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

    /** Whether the CHECK in place already spells out that an EARNING's tax is not empty. */
    private function clauseRefusesEmptyTax(): bool
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [self::TABLE, self::NAME],
        );
        $clause = strtolower(str_replace(['`', ' ', '"'], '', (string) ($row->c ?? '')));

        return str_contains($clause, 'taxisnotnull');
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
