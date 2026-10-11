<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Internal, append-only finalization evidence; never projected into ordinary payroll or portal views.
 * @property string $id
 * @property string $run_id
 * @property int $schema_version
 * @property string $calculator_version
 * @property string $input_hash
 * @property string $payload_hash
 * @property string $payload
 * @property int $finalized_by
 * @property CarbonImmutable $created_at
 */
class PayrollFinalizationSnapshot extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'payroll_finalization_snapshots';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(static function (): never {
            throw new RuntimeException('Finalization evidence cannot be edited.');
        });
        static::deleting(static function (): never {
            throw new RuntimeException('Finalization evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['schema_version' => 'integer', 'finalized_by' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
