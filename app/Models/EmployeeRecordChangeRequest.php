<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/** Frozen employee proposals and their independent decisions, retained beside the employee record.
 *
 * @property string $id
 * @property string $employee_id
 * @property string|null $pending_employee_id
 * @property string $employee_name
 * @property string $kind
 * @property string $section
 * @property string $operation
 * @property string|null $row_id
 * @property array<string, mixed> $baseline
 * @property array<string, mixed> $proposed
 * @property array<string, mixed> $form
 * @property list<array{field:string,label:string,before:?string,after:?string,before_known:bool,after_known:bool}> $changes
 * @property string $reason
 * @property string $status
 * @property int|null $requested_by
 * @property string $requested_by_name
 * @property int|null $reviewed_by
 * @property string|null $reviewed_by_name
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $withdrawn_by
 * @property string|null $withdrawn_by_name
 * @property CarbonImmutable|null $withdrawn_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmployeeRecordChangeRequest extends BaseModel
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const WITHDRAWN = 'WITHDRAWN';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'employee_name', 'kind', 'section', 'operation', 'row_id',
        'baseline', 'proposed', 'form', 'changes', 'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'baseline' => 'array', 'proposed' => 'array', 'form' => 'array', 'changes' => 'array',
            'requested_by' => 'integer', 'reviewed_by' => 'integer', 'withdrawn_by' => 'integer',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
