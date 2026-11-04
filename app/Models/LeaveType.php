<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;

/**
 * A Leave Settings entry: the name a request is filed under, plus the
 * credit rules HR sets on Leave Credits settings (2026_10_31_0003,
 * LeaveTypeCredits) — whether the type earns credits, its unit, what may
 * be charged to it and in what order, and its below-zero limit. The
 * credit columns are never fillable: SaveLeaveTypeCredits writes them.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $earns_credits
 * @property string $unit
 * @property bool $charge_late
 * @property bool $charge_absence
 * @property int $charge_order
 * @property float $below_zero_limit
 * @property string|null $year_end CARRY or LAPSE: what the balance does at the year end (2026_11_02_0017); null before the column reads as CARRY
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveType extends BaseModel
{
    use IsReferenceList;

    protected $table = 'leave_types';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'earns_credits' => 'boolean',
            'charge_late' => 'boolean',
            'charge_absence' => 'boolean',
            'charge_order' => 'integer',
            'below_zero_limit' => 'float',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
