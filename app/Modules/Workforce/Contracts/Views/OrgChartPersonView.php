<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts\Views;

/**
 * One person on the Organization view: who they are, the position they hold
 * and their photo — nothing else from the record. Everyone who holds Employee
 * Records reads this card for every placed employee, so it carries no pay,
 * contact, status or private profile field.
 *
 * `canOpen` says whether this viewer may open the person's record (the
 * EmployeeRecords::find visibility), so the screen links the name only when
 * the record would open.
 */
final readonly class OrgChartPersonView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $initials,
        public string $position,
        public ?string $photoPath,
        public bool $canOpen,
    ) {}
}
