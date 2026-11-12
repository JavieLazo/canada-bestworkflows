<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use App\Modules\Workforce\Contracts\DirectoryEmployee;

/** Account attribution without email, credentials, role grants or employee records. */
final readonly class AccountLabel
{
    public function __construct(public int $id, public string $name, public ?string $employee_id, public bool $is_active, public ?DirectoryEmployee $employee = null) {}

    /** The longest name an account without an employee record may carry. */
    public const int NAME_CAP = 150;

    /**
     * What an account with no employee record is called: the name typed for
     * it, else its number.
     */
    public static function unlinked(int|string|null $id, ?string $typed = null): string
    {
        $typed = $typed === null ? '' : trim($typed);

        if ($typed !== '') {
            return $typed;
        }

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
