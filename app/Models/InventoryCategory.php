<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Groups items without changing historical receipt descriptions.
 *
 * @property string $id
 * @property string $name
 */
class InventoryCategory extends BaseModel
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
