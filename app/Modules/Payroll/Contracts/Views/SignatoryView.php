<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts\Views;

/**
 * One signature slot as a document prints it: the slot, its label, and the
 * name and designation to print (empty strings print as a blank line).
 */
final readonly class SignatoryView
{
    public function __construct(
        public string $slot,
        public string $label,
        public string $name,
        public string $designation,
    ) {}
}
