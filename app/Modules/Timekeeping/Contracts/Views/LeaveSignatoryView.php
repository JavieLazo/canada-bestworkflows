<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping\Contracts\Views;

/**
 * One officer as the Application for Leave names them. Typed properties
 * rather than a Record, like SignatoryView: a misspelt field is an error at
 * render time, not a silently blank line. `employeeId` is '' for a slot no
 * employee has been picked for, and `employeeActive` is false once the picked
 * employee's record is inactive.
 */
final readonly class LeaveSignatoryView
{
    public function __construct(
        public string $slot,
        public string $label,
        public string $name,
        public string $designation,
        public string $employeeId = '',
        public bool $employeeActive = true,
    ) {}
}
