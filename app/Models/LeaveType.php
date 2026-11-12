<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsReferenceList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * A Leave Settings entry: the name a request is filed under, plus the
 * settings HR sets on Leave Settings (LeaveTypeCredits) — how it is filed
 * (earned credits, set days each year or each time, or without pay) and
 * how a request's days are counted, whether the type earns credits, its
 * unit, what may be charged to it and in what order, and its below-zero
 * limit. The settings columns are never fillable: SaveLeaveTypeCredits
 * writes them.
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
 * @property string|null $filing CREDITS, YEARLY, OCCASION or UNPAID: how the type is filed (2026_11_07_0001); null before the column reads by the name
 * @property float|null $allowance_days the set days of a YEARLY or OCCASION type
 * @property string|null $day_count WORKING or CALENDAR: the days a request is counted in; null before the column reads as WORKING
 * @property string|null $leave_details NONE, VACATION, SICK, WOMEN or STUDY: which details of leave a request asks for (2026_11_08_0002); null before the column reads as NONE
 * @property float|null $cash_out_min_balance the available credits needed before converting any to cash (2026_11_09_0001); null is no limit
 * @property float|null $cash_out_min_days the fewest days one cash conversion takes
 * @property float|null $cash_out_keep_days the days that must remain after a cash conversion
 * @property float|null $cash_out_max_year the most days converted to cash in a year, a large request aside
 * @property int|null $cash_out_per_year the most cash conversion requests in a year
 * @property int|null $cash_out_reason_share the percent of the available balance from which a cash conversion needs a reason
 * @property bool $paid_on_separation whether the remaining balance is paid out when an employee leaves (terminal leave)
 * @property list<string>|null $eligible_statuses the Employment list ids whose employees may use the type (2026_11_09_0005); null for every status
 * @property string|null $eligible_sex FEMALE or MALE for a type only one sex may use; null for anyone
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveType extends BaseModel
{
    use IsReferenceList;

    protected $table = 'leave_types';

    /**
     * How a leave type is filed until HR says otherwise: a name that reads
     * "without pay" is filed without pay, every other type against earned
     * credits. A new type starts from it (booted()), and it is how a type
     * reads while the `filing` column is still missing; the settings dialog
     * changes it like any other setting.
     */
    public static function filingByName(string $name): string
    {
        return preg_match('/without pay/i', $name) === 1 ? 'UNPAID' : 'CREDITS';
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(static function (LeaveType $type): void {
            if ($type->getAttribute('filing') === null && Schema::hasColumn('leave_types', 'filing')) {
                $type->setAttribute('filing', self::filingByName((string) $type->getAttribute('name')));
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'earns_credits' => 'boolean',
            'charge_late' => 'boolean',
            'charge_absence' => 'boolean',
            'charge_order' => 'integer',
            'below_zero_limit' => 'float',
            'allowance_days' => 'float',
            'cash_out_min_balance' => 'float',
            'cash_out_min_days' => 'float',
            'cash_out_keep_days' => 'float',
            'cash_out_max_year' => 'float',
            'cash_out_per_year' => 'integer',
            'cash_out_reason_share' => 'integer',
            'paid_on_separation' => 'boolean',
            'eligible_statuses' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
