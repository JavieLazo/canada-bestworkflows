<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A minutes-to-days conversion table an earlier build stored: the hours in
 * a day, the decimals and rounding rule, every minute and hour row as a
 * fraction of a day (`rows`). HISTORY ONLY — the table in use is fixed in
 * code (ConversionTables), and nothing reads or writes these rows. They
 * stay for the audit trail and for payroll evidence that cites their ids;
 * a DRAFT row is one nobody saved (the seeded standard table), APPROVED
 * one that was in use. Attribution columns have no user foreign key;
 * Timekeeping's account retention keeps the accounts they name.
 *
 * @property string $id
 * @property CarbonImmutable $effective_from
 * @property float $hours_per_day
 * @property int $decimals
 * @property string $rounding
 * @property array{minutes: array<int|string, string>, hours: array<int|string, string>} $rows
 * @property string|null $reference
 * @property string $status
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveConversionTable extends BaseModel
{
    public const string DRAFT = 'DRAFT';

    public const string APPROVED = 'APPROVED';

    protected $table = 'leave_conversion_tables';

    protected $fillable = ['effective_from', 'hours_per_day', 'decimals', 'rounding', 'rows', 'reference', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'hours_per_day' => 'float',
            'decimals' => 'integer',
            'rows' => 'array',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
