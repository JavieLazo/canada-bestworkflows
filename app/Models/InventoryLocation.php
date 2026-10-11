<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Names physical inventory locations independently of employee placement.
 *
 * @property string $id
 * @property string $name
 */
class InventoryLocation extends BaseModel
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
