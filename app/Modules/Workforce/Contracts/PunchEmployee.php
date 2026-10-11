<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

/** Only the employee facts needed to authorize and label a live punch. */
final readonly class PunchEmployee
{
    public function __construct(public string $id, public string $name, public bool $is_active, public ?string $work_site_id) {}
}
