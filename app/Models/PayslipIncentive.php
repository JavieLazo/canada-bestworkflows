<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One incentive or bonus line a posted payslip paid (payslip_incentives,
 * 2026_09_26_0009). Append-only, like the payslip itself: FinalizePayroll
 * inserts it, nothing updates it, and it has no `updated_at`.
 *
 * It COPIES the type's code, name and taxable flag as they were at
 * finalization — no foreign key to the catalog — so a later rename, flag
 * change or delete never rewrites what was paid. `over_ceiling` is the part
 * of a tax-exempt line taxed because it went over the PHP 90,000 yearly
 * ceiling; 0 on a taxable line.
 *
 * Owner: Payroll. Read only through its payslip, after that payslip was
 * scoped (PayslipRecords), so it carries no employee column of its own.
 *
 * @property string $id
 * @property string $payslip_id
 * @property int $position
 * @property string $type_code
 * @property string $name
 * @property bool $is_taxable
 * @property float $amount
 * @property float $over_ceiling
 * @property string|null $tax
 * @property bool $counts_toward_13th
 * @property CarbonImmutable $created_at
 * @property-read Payslip|null $payslip
 */
class PayslipIncentive extends BaseModel
{
    /** Append-only: created_at is filled on insert, updated_at never written. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'payslip_incentives';

    /** @var list<string> */
    protected $fillable = [
        'payslip_id',
        'position',
        'type_code',
        'name',
        'is_taxable',
        'amount',
        'over_ceiling',
        'tax',
        'counts_toward_13th',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_taxable' => 'boolean',
            'counts_toward_13th' => 'boolean',
            'amount' => 'float',
            'over_ceiling' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }
}
