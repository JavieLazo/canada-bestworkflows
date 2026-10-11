<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PayrollIncentiveType;
use App\Modules\Payroll\Application\PayrollData;
use App\Modules\Payroll\Domain\Benefits\BenefitRule;
use App\Support\CodeSequence;

/**
 * The starting catalog of incentives and bonuses — reached from
 * ProductionSeeder AND DatabaseSeeder, like the other configuration seeders.
 *
 * Six types: Performance Bonus, Christmas Bonus and Productivity Incentive
 * TAX-EXEMPT (they count toward the PHP 90,000 yearly ceiling, NIRC
 * Sec. 32(B)(7)(e)); Commission, Attendance Incentive and Other Incentive
 * TAXABLE. No de minimis item is seeded: its per-item ceilings cannot be
 * expressed by one taxable flag.
 *
 * A RELEASE RUNS ONLY MIGRATIONS, never a seeder (docs/DEPLOY.md). On an
 * existing host the catalog is filled ONCE, by the release note's one-off
 * `php artisan db:seed --class='Database\Seeders\IncentiveTypeSeeder' --force`
 * from the temporary cron — NEVER by ProductionSeeder there, whose
 * PayrollFormulaSeeder resets every saved formula value.
 *
 * ADDS ONLY, so any re-run is harmless to HR's edits: a type is skipped when
 * its stable seed identity, legacy id OR name already exists, and nothing here ever updates a
 * row. A new type's code is the next free number over the whole table, so it
 * never takes over a code HR's own types hold. One consequence, accepted: a
 * seeded type HR DELETED comes back on the next run of this seeder (or of
 * ProductionSeeder on a fresh install), so the one-off cron line must be
 * removed once it has run.
 */
final class IncentiveTypeSeeder extends PortSeeder
{
    /** Stable seed key (also the legacy id) => [name, taxable, description]. */
    private const TYPES = [
        'ity-1' => ['Performance Bonus', false, 'A bonus for performance over a period.'],
        'ity-2' => ['Christmas Bonus', false, 'The year-end bonus, apart from the 13th month pay.'],
        'ity-3' => ['Productivity Incentive', false, 'An incentive for meeting productivity targets.'],
        'ity-4' => ['Commission', true, 'A commission on sales or placements.'],
        'ity-5' => ['Attendance Incentive', true, 'An incentive for perfect or near-perfect attendance.'],
        'ity-6' => ['Other Incentive', true, 'Any other taxable incentive.'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $id => [$name, $taxable, $description]) {
            $this->upsert(PayrollIncentiveType::class, ['name' => $name], [
                'id' => $id,
                'code' => CodeSequence::next('INC', PayrollIncentiveType::query()->pluck('code')->all()),
                'name' => $name,
                'description' => $description,
                'is_taxable' => $taxable,
                // The typed shape: an amount typed per payroll, never scheduled.
                ...(PayrollData::benefitRulesReady() ? BenefitRule::typed($taxable)->toArray() : []),
            ], addOnly: true);
        }
    }
}
