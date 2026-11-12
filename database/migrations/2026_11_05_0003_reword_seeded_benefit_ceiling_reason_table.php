<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tax-free ceiling's first version was seeded with a reason written for
 * the people who built it ("as the engine carried it"). The Incentives &
 * Bonuses page shows that reason to payroll officers, so it is put in their
 * words. Only the seeded sentence is replaced; the figure, the date and any
 * reason somebody typed are left alone, and no payroll's evidence reads the
 * reason, so no preview goes stale.
 *
 * RETRY-SAFE: one UPDATE that matches the seeded sentence exactly; a second
 * run matches nothing. Rollback puts the seeded sentence back on the rows
 * still carrying the new one.
 */
return new class extends Migration
{
    private const string SEEDED = 'The yearly tax-free ceiling as the engine carried it';

    private const string PLAIN = 'The standard yearly tax-free ceiling';

    public function up(): void
    {
        $this->reword(self::SEEDED, self::PLAIN);
    }

    public function down(): void
    {
        $this->reword(self::PLAIN, self::SEEDED);
    }

    private function reword(string $from, string $to): void
    {
        if (Schema::hasTable('payroll_benefit_ceilings') && Schema::hasColumn('payroll_benefit_ceilings', 'reason')) {
            DB::table('payroll_benefit_ceilings')->where('reason', $from)->update(['reason' => $to]);
        }
    }
};
