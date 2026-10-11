<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incentive or bonus amount typed for one employee on one payroll
 * (payroll_run_member_incentives, 2026_09_26_0008): one row per member and
 * type, the amount always positive.
 *
 * It belongs to the member, like every other per-employee draft figure, and
 * is written again with the members on every save (SavePayroll), inside the
 * payroll membership lock and the same RequiredAudit transaction. Re-created
 * rather than updated, so it has no `updated_at`. `type_id` RESTRICTs the
 * catalog row it names.
 *
 * Owner: Payroll. Reached only through its member, whose run is read through
 * Payroll's scoped queries; it carries no employee column of its own.
 *
 * @property string $id
 * @property string $member_id
 * @property string $type_id
 * @property float $amount
 * @property CarbonImmutable $created_at
 * @property-read PayrollRunMember|null $member
 * @property-read PayrollIncentiveType|null $type
 */
class PayrollRunMemberIncentive extends BaseModel
{
    /** Re-created with its member on every save: no updated_at. */
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'payroll_run_member_incentives';

    /** @var list<string> */
    protected $fillable = [
        'member_id',
        'type_id',
        'amount',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PayrollRunMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(PayrollRunMember::class, 'member_id');
    }

    /** @return BelongsTo<PayrollIncentiveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(PayrollIncentiveType::class, 'type_id');
    }
}
