<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * One kind of incentive or bonus a payroll may pay (payroll_incentive_types,
 * 2026_09_26_0007) — the catalog under Records → Payroll Settings.
 *
 * The reference has no counterpart: it pays no bonus at all. `is_taxable`
 * decides where a line goes on the payslip: TRUE adds it to compensation
 * before withholding; FALSE makes it tax-exempt up to the PHP 90,000 yearly
 * ceiling (Payroll's ExemptBenefitCeiling), the excess taxed. Payroll lines
 * refer to a type by id; a posted payslip copies what it was paid under
 * (PayslipIncentive), so editing a type never rewrites posted pay.
 *
 * Owner: Payroll. Written only by `Modules\Payroll\Application\Commands\
 * SaveIncentiveType` / `DeleteIncentiveType` (RECORDS or PAYROLL all-data)
 * and the IncentiveTypeSeeder. No employee column, so no row scoping.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_taxable
 * @property string $amount_type
 * @property float|null $amount
 * @property string $frequency
 * @property int|null $month
 * @property list<string>|null $statuses
 * @property int $min_service_months
 * @property string $proration
 * @property string $tax
 * @property float|null $cap
 * @property string|null $approval_reference
 * @property bool $counts_toward_13th
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollIncentiveType extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_incentive_types';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_taxable',
        'amount_type',
        'amount',
        'frequency',
        'month',
        'statuses',
        'min_service_months',
        'proration',
        'tax',
        'cap',
        'approval_reference',
        'counts_toward_13th',
    ];

    /**
     * The flag and the category are one fact: a row saved with only the
     * flag (a test fixture, an older caller) gets the category the flag
     * describes, and a row saved with a category gets the flag it implies.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::saving(static function (PayrollIncentiveType $type): void {
            if (! array_key_exists('tax', $type->getAttributes()) || ($type->isDirty('is_taxable') && ! $type->isDirty('tax'))) {
                // Only once the catalog carries the column (the window before the cron migrates).
                if (Schema::hasColumn('payroll_incentive_types', 'tax')) {
                    $type->setAttribute('tax', $type->is_taxable ? 'TAXABLE' : 'EXEMPT');
                }

                return;
            }
            $type->setAttribute('is_taxable', $type->tax === 'TAXABLE');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'amount' => 'float',
            'month' => 'integer',
            'statuses' => 'array',
            'min_service_months' => 'integer',
            'cap' => 'float',
            'counts_toward_13th' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
