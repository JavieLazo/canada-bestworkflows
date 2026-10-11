<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incentives & Bonuses as a benefits catalog: each type gains the figures
 * `App\Modules\Payroll\Domain\Benefits\BenefitRule` reads — how the amount
 * is set (`amount_type`, `amount`), how often it is due (`frequency`,
 * `month`), who qualifies (`statuses`, the Employment list ids as JSON;
 * `min_service_months`), the `proration`, the tax category (`tax`, with a
 * `cap` for a de minimis item), an `approval_reference` and whether the
 * line `counts_toward_13th`. The former `is_taxable` flag stays, derived:
 * TRUE exactly when `tax` is TAXABLE, so every reader of the flag (posted
 * copies, the dialog's pill) keeps working.
 *
 * Posted copies (`payslip_incentives`) gain `tax` and `counts_toward_13th`,
 * copied at posting like the name and flag, so the 13th month basis and
 * the payslip read what was paid, never the catalog. `payslips` gains
 * `ceiling_benefits`: the part of `exempt_benefits` that spent the yearly
 * ceiling — NULL on a payslip posted before this column, where the whole
 * exempt figure did (`ExemptBenefitLedger` reads COALESCE), so no backfill
 * is needed and a re-run changes nothing.
 *
 * DATA: existing types become TYPED / ONCE (what they were: an amount typed
 * per payroll, never scheduled), `tax` TAXABLE or EXEMPT from the flag.
 * The backfill touches only rows whose `tax` the column default left at
 * TAXABLE while the flag says exempt, which the command never produces, so
 * a re-run is a no-op.
 *
 * RETRY-SAFE, STEP BY STEP: every column, CHECK and the backfill stands
 * behind its own guard. ROLLBACK LOSES the rules (every type reverts to a
 * typed amount), the posted copies' categories and the ceiling split.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->column('payroll_incentive_types', 'amount_type', static fn (Blueprint $t) => $t->string('amount_type', 10)->default('TYPED'));
        $this->column('payroll_incentive_types', 'amount', static fn (Blueprint $t) => $t->decimal('amount', 12, 2)->nullable());
        $this->column('payroll_incentive_types', 'frequency', static fn (Blueprint $t) => $t->string('frequency', 10)->default('ONCE'));
        $this->column('payroll_incentive_types', 'month', static fn (Blueprint $t) => $t->unsignedTinyInteger('month')->nullable());
        $this->column('payroll_incentive_types', 'statuses', static fn (Blueprint $t) => $t->json('statuses')->nullable());
        $this->column('payroll_incentive_types', 'min_service_months', static fn (Blueprint $t) => $t->unsignedSmallInteger('min_service_months')->default(0));
        $this->column('payroll_incentive_types', 'proration', static fn (Blueprint $t) => $t->string('proration', 10)->default('NONE'));
        $this->column('payroll_incentive_types', 'tax', static fn (Blueprint $t) => $t->string('tax', 12)->default('TAXABLE'));
        $this->column('payroll_incentive_types', 'cap', static fn (Blueprint $t) => $t->decimal('cap', 12, 2)->nullable());
        $this->column('payroll_incentive_types', 'approval_reference', static fn (Blueprint $t) => $t->string('approval_reference', 160)->nullable());
        $this->column('payroll_incentive_types', 'counts_toward_13th', static fn (Blueprint $t) => $t->boolean('counts_toward_13th')->default(false));

        $this->check('payroll_incentive_types', 'pit_amount_type_chk', "`amount_type` IN ('FIXED','PERCENT','MONTH','DAILY','TYPED')");
        $this->check('payroll_incentive_types', 'pit_frequency_chk', "`frequency` IN ('EVERY','FIRST','SECOND','YEARLY','ONCE')");
        $this->check('payroll_incentive_types', 'pit_proration_chk', "`proration` IN ('NONE','MONTHS','DAYS')");
        $this->check('payroll_incentive_types', 'pit_tax_chk', "`tax` IN ('TAXABLE','EXEMPT','DE_MINIMIS','NOT_INCOME')");

        // The former flag, as a category: an exempt type was the ceiling's.
        DB::table('payroll_incentive_types')->where('is_taxable', false)->where('tax', 'TAXABLE')->update(['tax' => 'EXEMPT']);

        $this->column('payslip_incentives', 'tax', static fn (Blueprint $t) => $t->string('tax', 12)->nullable());
        $this->column('payslip_incentives', 'counts_toward_13th', static fn (Blueprint $t) => $t->boolean('counts_toward_13th')->default(false));

        $this->column('payslips', 'ceiling_benefits', static fn (Blueprint $t) => $t->decimal('ceiling_benefits', 12, 2)->nullable());
    }

    public function down(): void
    {
        if (Schema::hasColumn('payslips', 'ceiling_benefits')) {
            Schema::table('payslips', static fn (Blueprint $t) => $t->dropColumn('ceiling_benefits'));
        }
        foreach (['counts_toward_13th', 'tax'] as $column) {
            if (Schema::hasColumn('payslip_incentives', $column)) {
                Schema::table('payslip_incentives', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        foreach (['pit_amount_type_chk', 'pit_frequency_chk', 'pit_proration_chk', 'pit_tax_chk'] as $check) {
            if ($this->isMySql() && $this->hasCheck('payroll_incentive_types', $check)) {
                DB::statement("ALTER TABLE `payroll_incentive_types` DROP CONSTRAINT `{$check}`");
            }
        }
        foreach (['counts_toward_13th', 'approval_reference', 'cap', 'tax', 'proration', 'min_service_months', 'statuses', 'month', 'frequency', 'amount', 'amount_type'] as $column) {
            if (Schema::hasColumn('payroll_incentive_types', $column)) {
                Schema::table('payroll_incentive_types', static fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }

    /** One column behind its own guard. */
    private function column(string $table, string $name, Closure $define): void
    {
        if (! Schema::hasColumn($table, $name)) {
            Schema::table($table, static function (Blueprint $t) use ($define): void {
                $define($t);
            });
        }
    }

    /** One named CHECK behind its own guard — MySQL/MariaDB only, where CHECKs exist. */
    private function check(string $table, string $name, string $expression): void
    {
        if ($this->isMySql() && ! $this->hasCheck($table, $name)) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    /** Whether the named CHECK exists — information_schema, so MySQL/MariaDB only. */
    private function hasCheck(string $table, string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        );

        return $row !== null && (int) $row->n > 0;
    }

    private function isMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
