<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * One dated version of one Contribution & Tax Table: the SSS, PhilHealth,
 * Pag-IBIG or withholding figures (`params`, the shape
 * `App\Domain\Payroll\StatutorySchedule` reads) in force from
 * `effective_from` until a later version starts, with its figures last
 * CONFIRMED good through `covered_through` (a reminder past it, never a
 * refusal). A version in force never changes — a change is a new version;
 * only a version that has not taken effect yet is corrected or withdrawn —
 * and the account that added it is kept by id and by name.
 *
 * `created_by` and `created_by_name` are set by the command, never filled.
 *
 * @property string $id
 * @property string $kind
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable $covered_through
 * @property string|null $reference
 * @property array<string, mixed> $params
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PayrollStatutoryTable extends BaseModel
{
    /** @var string */
    protected $table = 'payroll_statutory_tables';

    /** @var list<string> */
    protected $fillable = ['kind', 'effective_from', 'covered_through', 'reference', 'params'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date:Y-m-d',
            'covered_through' => 'immutable_date:Y-m-d',
            'params' => 'array',
        ];
    }
}
