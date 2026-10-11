<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Contracts;

use App\Support\Access;

/** Self-service or authorized kiosk employee lookup, without exposing an employee model. */
interface PunchEmployees
{
    /**
     * With lock=true, inside the punch's own transaction: holds the employee
     * row so a punch and a schedule change for the same employee wait for
     * each other — the punch is then judged by the schedule as committed,
     * never by one a change is replacing.
     */
    public function find(Access $actor, string $id, bool $lock = false): ?PunchEmployee;
}
