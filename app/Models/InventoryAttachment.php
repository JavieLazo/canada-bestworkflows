<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * Retains inventory evidence separately from the item’s current custody.
 *
 * @property string $id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $receipt_id
 * @property string|null $disposal_id
 * @property string $file_name
 * @property string $mime
 * @property int $size
 * @property string $path
 * @property int $uploaded_by
 */
class InventoryAttachment extends BaseModel
{
    /** @var list<string> */
    protected $fillable = ['receipt_id', 'disposal_id', 'file_name', 'mime', 'size', 'path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }
}
