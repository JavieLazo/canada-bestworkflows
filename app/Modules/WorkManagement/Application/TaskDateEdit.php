<?php

declare(strict_types=1);

namespace App\Modules\WorkManagement\Application;

/** Only dates are posted; other task properties stay as they are. */
final readonly class TaskDateEdit
{
    public function __construct(
        public string $startDate,
        public string $dueDate,
        public string $seenStartDate,
        public string $seenDueDate,
    ) {}
}
