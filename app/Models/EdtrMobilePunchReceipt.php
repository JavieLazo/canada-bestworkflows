<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Remembers a punch the phone already sent, keyed by the phone's own id.
 *
 * A retried sync reads this row and does not insert a second attendance log.
 * If a later DTR correction removes that log, the receipt remains so the
 * phone does not recreate the original punch.
 *
 * @property string $id
 * @property string $client_id
 * @property string|null $attendance_log_id
 * @property string $message
 * @property CarbonImmutable $created_at
 */
class EdtrMobilePunchReceipt extends BaseModel
{
    public const UPDATED_AT = null;

    /** @var string */
    protected $table = 'edtr_mobile_punch_receipts';

    /** @var list<string> */
    protected $fillable = [
        'client_id',
        'attendance_log_id',
        'message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
