<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Modules\Workforce\Contracts\DirectoryEmployee;

/** Account attribution without email, credentials, role grants or employee records. */
final readonly class AccountLabel
{
    public function __construct(public int $id, public string $name, public ?string $employee_id, public bool $is_active, public ?DirectoryEmployee $employee = null) {}

    public static function unlinked(int|string|null $id): string
    {
        return $id === null ? 'Account' : 'Account #'.$id;
    }

    public function getAttribute(string $key): mixed
    {
        return match ($key) {
            'id' => $this->id, 'name' => $this->name, 'employee_id' => $this->employee_id, 'is_active' => $this->is_active, default => null
        };
    }

    public function getKey(): int
    {
        return $this->id;
    }
}
